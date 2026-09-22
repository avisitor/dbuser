<?php

declare(strict_types=1);

namespace Common\User\Tests;

use Common\User\DBUser;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Hermetic tests for the avisitor/dbuser package.
 *
 * No live DB connection is made: PDO is always stubbed via createMock(),
 * and DBBase's static state (default connection, column cache) is reset
 * between tests so runs never leak state.
 */
class DBUserTest extends TestCase
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

    private function makeUser(?PDO $pdo = null): DBUser
    {
        return new DBUser($pdo ?? $this->mockPdo(), $this->makeLogger());
    }

    // ---------------------------------------------------------------
    // getByEmail / getById
    // ---------------------------------------------------------------

    public function testGetByEmailQueriesUsernameFieldAndReturnsRow(): void
    {
        $row = ['id' => 'u1', 'username' => 'a@x.test', 'password' => '$2y$10$abc'];
        $user = new DBUser($this->mockPdo([[$row]]), $this->makeLogger());

        $result = $user->getByEmail('a@x.test');

        $this->assertSame($row, $result);
        $this->assertStringContainsString('WHERE username = :email', $this->lastSql());
        $this->assertStringContainsString('LIMIT 1', $this->lastSql());
        $this->assertSame([':email' => 'a@x.test'], end($this->bound));
    }

    public function testGetByEmailReturnsEmptyArrayWhenNoRow(): void
    {
        $user = new DBUser($this->mockPdo([[]]), $this->makeLogger());
        $this->assertSame([], $user->getByEmail('nobody@x.test'));
    }

    public function testGetByEmailReturnsEmptyArrayOnPrepareFailure(): void
    {
        $user = new DBUser($this->failingPdo(), $this->makeLogger());
        $this->assertSame([], $user->getByEmail('a@x.test'));
    }

    public function testGetByIdReturnsRowWhenFound(): void
    {
        $row = ['id' => 'u7', 'username' => 'b@x.test'];
        $user = new DBUser($this->mockPdo([[$row]]), $this->makeLogger());

        $this->assertSame($row, $user->getById('u7'));
        $this->assertStringContainsString('WHERE id = :id', $this->lastSql());
    }

    public function testGetByIdReturnsEmptyArrayWhenMissing(): void
    {
        $user = new DBUser($this->mockPdo([[]]), $this->makeLogger());
        $this->assertSame([], $user->getById('nope'));
    }

    public function testGetByIdReturnsEmptyArrayOnPrepareFailure(): void
    {
        $user = new DBUser($this->failingPdo(), $this->makeLogger());
        $this->assertSame([], $user->getById('u7'));
    }

    // ---------------------------------------------------------------
    // verifyPassword
    // ---------------------------------------------------------------

    public function testVerifyPasswordReturnsFalseWhenUserMissing(): void
    {
        $user = new DBUser($this->mockPdo([[]]), $this->makeLogger());
        $this->assertFalse($user->verifyPassword('nobody@x.test', 'secret'));
    }

    public function testVerifyPasswordAcceptsCorrectBcryptHash(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $user = new DBUser($this->mockPdo([[['username' => 'a@x.test', 'password' => $hash]]]), $this->makeLogger());
        $this->assertTrue($user->verifyPassword('a@x.test', 'secret'));
    }

    public function testVerifyPasswordRejectsWrongBcryptPassword(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $user = new DBUser($this->mockPdo([[['username' => 'a@x.test', 'password' => $hash]]]), $this->makeLogger());
        $this->assertFalse($user->verifyPassword('a@x.test', 'wrong'));
    }

    public function testVerifyPasswordAcceptsCorrectArgon2idHash(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('argon2id not available in this PHP build');
        }
        $hash = password_hash('secret', PASSWORD_ARGON2ID);
        $user = new DBUser($this->mockPdo([[['username' => 'a@x.test', 'password' => $hash]]]), $this->makeLogger());
        $this->assertTrue($user->verifyPassword('a@x.test', 'secret'));
    }

    public function testVerifyPasswordAcceptsMatchingPlaintextFallback(): void
    {
        $user = new DBUser($this->mockPdo([[['username' => 'a@x.test', 'password' => 'secret']]]), $this->makeLogger());
        $this->assertTrue($user->verifyPassword('a@x.test', 'secret'));
    }

    public function testVerifyPasswordRejectsMismatchedPlaintext(): void
    {
        $user = new DBUser($this->mockPdo([[['username' => 'a@x.test', 'password' => 'secret']]]), $this->makeLogger());
        $this->assertFalse($user->verifyPassword('a@x.test', 'wrong'));
    }

    public function testVerifyPasswordReturnsFalseWhenPasswordColumnMissing(): void
    {
        $user = new DBUser($this->mockPdo([[['username' => 'a@x.test']]]), $this->makeLogger());
        $this->assertFalse($user->verifyPassword('a@x.test', 'secret'));
    }

    // ---------------------------------------------------------------
    // isActive
    // ---------------------------------------------------------------

    public function testIsActiveReturnsFalseWhenUserMissing(): void
    {
        $user = new DBUser($this->mockPdo([[]]), $this->makeLogger());
        $this->assertFalse($user->isActive('nobody@x.test'));
    }

    public function testIsActiveTrueWhenActiveOne(): void
    {
        $user = new DBUser($this->mockPdo([[['active' => '1']]]), $this->makeLogger());
        $this->assertTrue($user->isActive('a@x.test'));
    }

    public function testIsActiveFalseWhenActiveZero(): void
    {
        $user = new DBUser($this->mockPdo([[['active' => '0']]]), $this->makeLogger());
        $this->assertFalse($user->isActive('a@x.test'));
    }

    public function testIsActiveReturnsFalseWhenActiveColumnNull(): void
    {
        // Source contract: a null/absent 'active' column defaults to active (1).
        $user = new DBUser($this->mockPdo([[['active' => null]]]), $this->makeLogger());
        $this->assertTrue($user->isActive('a@x.test'));
    }

    // ---------------------------------------------------------------
    // update: password hashing pass-through
    // ---------------------------------------------------------------

    private function recordingUser(): DBUser
    {
        return new class(null, $this->makeLogger()) extends DBUser {
            public array $upsertParams = [];
            public function updateOneDBRecord($potential, $params, $table, $prefix = '', $idName = 'id', $criteria = '')
            {
                $this->upsertParams = $params;
                return 0;
            }
        };
    }

    public function testUpdateHashesPlaintextPasswordBeforeUpsert(): void
    {
        $recorder = $this->recordingUser();

        $recorder->update(['username' => 'a@x.test', 'password' => 'plaintext']);

        $this->assertStringStartsWith('$2y$', $recorder->upsertParams['password']);
        $this->assertNotSame('plaintext', $recorder->upsertParams['password']);
    }

    public function testUpdateLeavesPreHashedPasswordAlone(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $recorder = $this->recordingUser();

        $recorder->update(['username' => 'a@x.test', 'password' => $hash]);

        $this->assertSame($hash, $recorder->upsertParams['password']);
    }

    // ---------------------------------------------------------------
    // create
    // ---------------------------------------------------------------

    public function testCreateInsertsHashedPasswordWithDefaults(): void
    {
        $user = new DBUser($this->mockPdo([[], []]), $this->makeLogger());

        $result = $user->create(['username' => 'a@x.test', 'password' => 'plaintext']);

        $this->assertSame(0, $result);
        $insert = $this->lastSql();
        $this->assertStringContainsString('INSERT INTO users', $insert);
        $this->assertStringContainsString('username', $insert);
        $this->assertStringContainsString('password', $insert);
    }

    public function testCreateHashesPlaintextPassword(): void
    {
        $user = new DBUser($this->mockPdo([[], []]), $this->makeLogger());
        $user->create(['username' => 'a@x.test', 'password' => 'plaintext']);

        $bound = end($this->bound);
        $this->assertArrayHasKey('password', $bound);
        $this->assertStringStartsWith('$2y$', (string)$bound['password']);
    }

    public function testCreateInjectsDefaultActiveHashAndRate(): void
    {
        $user = new DBUser($this->mockPdo([[], []]), $this->makeLogger());
        $user->create(['username' => 'a@x.test']);

        $bound = end($this->bound);
        $this->assertSame(1, $bound['active'] ?? null);
        $this->assertSame(0, $bound['rate'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)($bound['hash'] ?? ''));
    }

    public function testCreateKeepsExplicitlyProvidedFields(): void
    {
        $user = new DBUser($this->mockPdo([[], []]), $this->makeLogger());
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $user->create(['username' => 'a@x.test', 'password' => $hash, 'hash' => 'gottentag', 'rate' => 5]);

        $bound = end($this->bound);
        $this->assertSame($hash, $bound['password']);
        $this->assertSame('gottentag', $bound['hash']);
        $this->assertSame(5, $bound['rate']);
    }

    public function testCreateFiltersUnknownFields(): void
    {
        $user = new DBUser($this->mockPdo([[], []]), $this->makeLogger());
        $user->create(['username' => 'a@x.test', 'bogus_field' => 'nope']);

        $bound = end($this->bound);
        $this->assertArrayNotHasKey('bogus_field', $bound);
        $this->assertArrayHasKey('username', $bound);
    }

    public function testCreateOnlyUnknownFieldsStillInsertsDefaults(): void
    {
        // Defaults (active/hash/rate) are always injected via fieldNames, so
        // even a payload of unknown keys still produces a defaults-only insert.
        $user = new DBUser($this->mockPdo([[], []]), $this->makeLogger());
        $this->assertSame(0, $user->create(['bogus_field' => 'nope']));

        $bound = end($this->bound);
        $this->assertArrayNotHasKey('bogus_field', $bound);
        $this->assertArrayHasKey('active', $bound);
    }

    public function testCreateReturnsFalseOnPrepareFailure(): void
    {
        $user = new DBUser($this->failingPdo(), $this->makeLogger());
        $this->assertFalse($user->create(['username' => 'a@x.test']));
    }

    // ---------------------------------------------------------------
    // getAdminEmails
    // ---------------------------------------------------------------

    public function testGetAdminEmailsJoinsTagsOnAdmin(): void
    {
        $rows = [['email' => 'admin@x.test'], ['email' => 'root@x.test']];
        $user = new DBUser($this->mockPdo([$rows]), $this->makeLogger());

        $this->assertSame($rows, $user->getAdminEmails());
        $this->assertMatchesRegularExpression('/tags/i', $this->lastSql());
        $this->assertMatchesRegularExpression("/tag\s*=\s*'Admin'/", $this->lastSql());
    }

    public function testGetAdminEmailsReturnsEmptyOnPrepareFailure(): void
    {
        $user = new DBUser($this->failingPdo(), $this->makeLogger());
        $this->assertSame([], $user->getAdminEmails());
    }

    private function lastSql(): string
    {
        return $this->sql[count($this->sql) - 1] ?? '';
    }
}
