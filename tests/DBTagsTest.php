<?php

declare(strict_types=1);

namespace Common\User\Tests;

use Common\User\DBTags;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Hermetic tests for the avisitor/dbuser DBTags class.
 *
 * No live DB connection is made: PDO is always stubbed via createMock(),
 * and DBBase's static state (default connection, column cache) is reset
 * between tests so runs never leak state.
 */
class DBTagsTest extends TestCase
{
    /** @var string[] SQL statements received by the mocked PDO */
    private array $sql = [];

    /** @var array<int, array> values bound to statements, keyed by call index */
    private array $bound = [];

    /** Seed rows returned by fetch()/fetchAll() per PDO prepare() call index */
    private array $rowsByIndex = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sql = [];
        $this->bound = [];
        $this->rowsByIndex = [];

        // Reset DBBase static state so tests never inherit a connection/column cache.
        foreach (['defaultConn', 'defaultConfig', 'defaultLogger', 'cachedColumns'] as $prop) {
            $ref = new \ReflectionProperty(\Common\DB\DBBase::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    private function makeLogger(): object
    {
        return new class {
            public function log(mixed ...$args): void
            {
            }
        };
    }

    /**
     * Build a PDO mock whose prepare() records the SQL and bound values.
     * fetch()/fetchAll() results are seeded per call index.
     */
    private function mockPdo(array $rowsByIndex = []): PDO
    {
        $this->rowsByIndex = $rowsByIndex;
        $this->sql = [];
        $this->bound = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $this->sql[] = $sql;
            $index = count($this->sql) - 1;
            $rows = $this->rowsByIndex[$index] ?? [];

            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function ($values = null) use ($index) {
                if ($values !== null) {
                    $this->bound[$index] = $values;
                }
                return true;
            });
            $stmt->method('fetch')->willReturnCallback(fn() => count($rows) ? array_shift($rows) : false);
            $stmt->method('fetchAll')->willReturn($rows);
            return $stmt;
        });

        return $pdo;
    }

    /** PDO mock whose prepare() always throws (connection/prepare failure). */
    private function failingPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('mock prepare failure'));
        return $pdo;
    }

    private function lastSql(): string
    {
        return $this->sql[count($this->sql) - 1] ?? '';
    }

    private function makeTags(?PDO $pdo = null): DBTags
    {
        return new DBTags($pdo ?? $this->mockPdo(), $this->makeLogger());
    }

    // ---------------------------------------------------------------
    // getTagsByEmail / getTagStringsByEmail
    // ---------------------------------------------------------------

    public function testGetTagsByEmailReturnsOrderedRows(): void
    {
        $rows = [
            ['email' => 'a@x.test', 'tag' => 'Admin', 'created' => '2026-01-01 00:00:00'],
            ['email' => 'a@x.test', 'tag' => 'Past Client', 'created' => '2026-01-02 00:00:00'],
        ];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());

        $this->assertSame($rows, $tags->getTagsByEmail('a@x.test'));
        $this->assertStringContainsString('ORDER BY tag', $this->lastSql());
        $this->assertSame([':email' => 'a@x.test'], end($this->bound));
    }

    public function testGetTagsByEmailReturnsEmptyArrayWhenNoRows(): void
    {
        $tags = new DBTags($this->mockPdo([[]]), $this->makeLogger());
        $this->assertSame([], $tags->getTagsByEmail('nobody@x.test'));
    }

    public function testGetTagsByEmailReturnsEmptyArrayOnPrepareFailure(): void
    {
        $tags = new DBTags($this->failingPdo(), $this->makeLogger());
        $this->assertSame([], $tags->getTagsByEmail('a@x.test'));
    }

    public function testGetTagStringsByEmailExtractsTagValues(): void
    {
        $rows = [
            ['email' => 'a@x.test', 'tag' => 'Admin'],
            ['email' => 'b@x.test', 'tag' => 'Lead'],
        ];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());

        $this->assertSame(['Admin', 'Lead'], $tags->getTagStringsByEmail('a@x.test'));
    }

    public function testGetTagStringsByEmailSkipsRecordsWithoutTag(): void
    {
        $rows = [['email' => 'a@x.test', 'tag' => 'Admin'], ['email' => 'a@x.test']];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());

        $this->assertSame(['Admin'], $tags->getTagStringsByEmail('a@x.test'));
    }

    // ---------------------------------------------------------------
    // addTag
    // ---------------------------------------------------------------

    public function testAddTagInsertsWithCreatedTimestamp(): void
    {
        // Call 1: duplicate check (no rows), call 2: INSERT.
        $tags = new DBTags($this->mockPdo([[], []]), $this->makeLogger());

        $result = $tags->addTag('a@x.test', 'Lead');

        $this->assertSame(0, $result);
        $this->assertStringContainsString('INSERT INTO tags', $this->lastSql());
        $bound = end($this->bound);
        $this->assertSame('a@x.test', $bound[':email']);
        $this->assertSame('Lead', $bound[':tag']);
        $this->assertNotEmpty($bound[':created']);
    }

    public function testAddTagReturnsZeroWithoutInsertWhenTagExists(): void
    {
        $existing = [['email' => 'a@x.test', 'tag' => 'Lead']];
        $tags = new DBTags($this->mockPdo([$existing]), $this->makeLogger());

        $this->assertSame(0, $tags->addTag('a@x.test', 'Lead'));

        $insertSqls = array_values(array_filter($this->sql, fn($s) => str_contains($s, 'INSERT')));
        $this->assertCount(0, $insertSqls, 'must not issue an INSERT for an existing tag');
    }

    public function testAddTagReturnsFalseOnPrepareFailure(): void
    {
        $tags = new DBTags($this->failingPdo(), $this->makeLogger());
        $this->assertFalse($tags->addTag('a@x.test', 'Lead'));
    }

    // ---------------------------------------------------------------
    // removeTag / deleteTagsByEmail
    // ---------------------------------------------------------------

    public function testRemoveTagDeletesByEmailAndTag(): void
    {
        $tags = new DBTags($this->mockPdo(), $this->makeLogger());

        $this->assertSame(0, $tags->removeTag('a@x.test', 'Lead'));
        $this->assertStringContainsString('DELETE FROM tags', $this->lastSql());
        $bound = end($this->bound);
        $this->assertSame([':email' => 'a@x.test', ':tag' => 'Lead'], $bound);
    }

    public function testRemoveTagReturnsOneOnPrepareFailure(): void
    {
        $tags = new DBTags($this->failingPdo(), $this->makeLogger());
        $this->assertSame(1, $tags->removeTag('a@x.test', 'Lead'));
    }

    public function testDeleteTagsByEmailDeletesOnlyByEmail(): void
    {
        $tags = new DBTags($this->mockPdo(), $this->makeLogger());

        $this->assertSame(0, $tags->deleteTagsByEmail('a@x.test'));
        $this->assertSame([':email' => 'a@x.test'], end($this->bound));
        $this->assertStringContainsString('DELETE FROM tags WHERE email = :email', $this->lastSql());
    }

    public function testDeleteTagsByEmailReturnsOneOnPrepareFailure(): void
    {
        $tags = new DBTags($this->failingPdo(), $this->makeLogger());
        $this->assertSame(1, $tags->deleteTagsByEmail('a@x.test'));
    }

    // ---------------------------------------------------------------
    // hasTag
    // ---------------------------------------------------------------

    public function testHasTagReturnsTrueWhenTagPresent(): void
    {
        $rows = [['email' => 'a@x.test', 'tag' => 'Admin']];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());
        $this->assertTrue($tags->hasTag('a@x.test', 'Admin'));
    }

    public function testHasTagReturnsFalseWhenTagAbsent(): void
    {
        $rows = [['email' => 'a@x.test', 'tag' => 'Lead']];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());
        $this->assertFalse($tags->hasTag('a@x.test', 'Admin'));
    }

    public function testHasTagUsesStrictComparison(): void
    {
        // '1' vs 1 must not be considered equal by tag matching.
        $rows = [['email' => 'a@x.test', 'tag' => 1]];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());
        $this->assertFalse($tags->hasTag('a@x.test', '1'));
    }

    // ---------------------------------------------------------------
    // getTagsAndRoles
    // ---------------------------------------------------------------

    public function testGetTagsAndRolesMapsAdminTagToSuperadmin(): void
    {
        $rows = [['email' => 'a@x.test', 'tag' => 'Admin']];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());

        $result = $tags->getTagsAndRoles('a@x.test');
        $this->assertSame(['Admin'], $result['tags']);
        $this->assertSame(['superadmin'], $result['roles']);
    }

    public function testGetTagsAndRolesMapsSuperadminTagCaseInsensitively(): void
    {
        $rows = [['email' => 'a@x.test', 'tag' => 'SuperAdmin']];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());

        $this->assertSame(['superadmin'], $tags->getTagsAndRoles('a@x.test')['roles']);
    }

    public function testGetTagsAndRolesMapsTenantAdminVariants(): void
    {
        foreach (['TenantAdmin', 'tenant_admin', 'TENANTADMIN'] as $variant) {
            $rows = [['email' => 'a@x.test', 'tag' => $variant]];
            $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());
            $this->assertSame(['tenant_admin'], $tags->getTagsAndRoles('a@x.test')['roles'], "tag: $variant");
        }
    }

    public function testGetTagsAndRolesDefaultsNonRoleTagsToEditor(): void
    {
        $rows = [['email' => 'a@x.test', 'tag' => 'Past Client'], ['email' => 'a', 'tag' => 'Client']];
        $tags = new DBTags($this->mockPdo([$rows]), $this->makeLogger());

        $result = $tags->getTagsAndRoles('a@x.test');
        $this->assertSame(['Past Client', 'Client'], $result['tags']);
        $this->assertSame(['editor'], $result['roles']);
    }

    public function testGetTagsAndRolesReturnsEditorForUserWithNoTags(): void
    {
        $tags = new DBTags($this->mockPdo([[]]), $this->makeLogger());

        $result = $tags->getTagsAndRoles('a@x.test');
        $this->assertSame([], $result['tags']);
        $this->assertSame(['editor'], $result['roles']);
    }

    // ---------------------------------------------------------------
    // processRecords
    // ---------------------------------------------------------------

    public function testProcessRecordsGroupsTagsByEmail(): void
    {
        $tags = new DBTags($this->mockPdo(), $this->makeLogger());

        $records = [
            ['email' => 'a@x.test', 'tag' => 'Admin'],
            ['email' => 'a@x.test', 'tag' => 'Lead'],
            ['email' => 'b@x.test', 'tag' => 'Client'],
        ];

        $this->assertSame([
            'a@x.test' => ['Admin', 'Lead'],
            'b@x.test' => ['Client'],
        ], $tags->processRecords($records));
    }
}
