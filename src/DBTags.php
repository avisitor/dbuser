<?php

declare(strict_types=1);

namespace Common\User;

use Common\DB\DBBase;
use PDO;
use Throwable;

class DBTags extends DBBase
{
    protected $table = 'tags';
    protected $idName = 'email'; // Use email as primary identifier since there's no id field

    public function __construct($pdo = null, $logger = null)
    {
        parent::__construct($pdo, $logger);
    }

    /**
     * Get field names for this table
     * @return array
     */
    public function getFieldNames(): array
    {
        return ['email', 'tag', 'created'];
    }

    /**
     * Get tags by email address
     * @param string $email
     * @return array Array of tag records
     */
    public function getTagsByEmail(string $email): array
    {
        try {
            $this->debuglog("DBTags->getTagsByEmail(): ($email)");
            
            $sql = "SELECT * FROM {$this->table} WHERE email = :email ORDER BY tag";
            $stmt = $this->executeStatement($sql, [':email' => $email]);
            if (!$stmt) {
                return [];
            }
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->debuglog("DBTags->getTagsByEmail(): " . count($results) . " records found");
            
            return $results ?: [];
        } catch (Throwable $e) {
            $this->debuglog("DBTags->getTagsByEmail() error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get tags by email as simple array of tag strings
     * @param string $email
     * @return array Array of tag strings
     */
    public function getTagStringsByEmail(string $email): array
    {
        $records = $this->getTagsByEmail($email);
        $tags = [];
        foreach ($records as $record) {
            if (isset($record['tag'])) {
                $tags[] = $record['tag'];
            }
        }
        return $tags;
    }

    /**
     * Add tag for email
     * @param string $email
     * @param string $tag
     * @return mixed Result from insert operation
     */
    public function addTag(string $email, string $tag)
    {
        try {
            $this->debuglog("DBTags->addTag(): ($email, $tag)");
            
            // Check if tag already exists
            $existing = $this->query("SELECT * FROM {$this->table} WHERE email = :email AND tag = :tag", [
                ':email' => $email,
                ':tag' => $tag
            ]);
            
            if (!empty($existing)) {
                return 0; // Already exists, consider this success
            }
            
            $sql = "INSERT INTO {$this->table} (email, tag, created) VALUES (:email, :tag, :created)";
            $result = $this->executeStatement($sql, [
                ':email' => $email,
                ':tag' => $tag,
                ':created' => date('Y-m-d H:i:s')
            ]);
            
            return $result ? 0 : false;
        } catch (Throwable $e) {
            $this->debuglog("DBTags->addTag() error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove tag for email
     * @param string $email
     * @param string $tag
     * @return int Result from execute (0 on success)
     */
    public function removeTag(string $email, string $tag): int
    {
        try {
            $this->debuglog("DBTags->removeTag(): ($email, $tag)");
            
            $sql = "DELETE FROM {$this->table} WHERE email = :email AND tag = :tag";
            return $this->execute($sql, [':email' => $email, ':tag' => $tag]);
        } catch (Throwable $e) {
            $this->debuglog("DBTags->removeTag() error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Check if user has specific tag
     * @param string $email
     * @param string $tag
     * @return bool True if user has the tag
     */
    public function hasTag(string $email, string $tag): bool
    {
        try {
            $tags = $this->getTagStringsByEmail($email);
            return in_array($tag, $tags, true);
        } catch (Throwable $e) {
            $this->debuglog("DBTags->hasTag() error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all tags for user with role mapping
     * @param string $email
     * @return array Array with 'tags' and 'roles' keys
     */
    public function getTagsAndRoles(string $email): array
    {
        try {
            $tags = $this->getTagStringsByEmail($email);
            
            // Map tags to roles (case-insensitive):
            //  - 'Admin' => superadmin
            //  - 'TenantAdmin' or 'tenant_admin' => tenant_admin
            //  - else => editor
            $ltags = array_map(function($t){ return strtolower($t); }, $tags);
            
            if (in_array('admin', $ltags, true) || in_array('superadmin', $ltags, true)) {
                $roles = ['superadmin'];
            } elseif (in_array('tenantadmin', $ltags, true) || in_array('tenant_admin', $ltags, true)) {
                $roles = ['tenant_admin'];
            } else {
                $roles = ['editor'];
            }
            
            return [
                'tags' => $tags,
                'roles' => $roles
            ];
        } catch (Throwable $e) {
            $this->debuglog("DBTags->getTagsAndRoles() error: " . $e->getMessage());
            return ['tags' => [], 'roles' => ['editor']];
        }
    }

    /**
     * Delete all tags for an email address (for retreehawaii compatibility)
     * @param string $email
     * @return int Result from execute (0 on success)
     */
    public function deleteTagsByEmail(string $email): int
    {
        try {
            $this->debuglog("DBTags->deleteTagsByEmail(): ($email)");
            
            $sql = "DELETE FROM {$this->table} WHERE email = :email";
            return $this->execute($sql, [':email' => $email]);
        } catch (Throwable $e) {
            $this->debuglog("DBTags->deleteTagsByEmail() error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Process records into taglist format (for retreehawaii compatibility)
     * @param array $records
     * @return array Array keyed by email with tag arrays as values
     */
    public function processRecords($records)
    {
        $taglist = [];
        foreach ($records as $record) {
            if (!isset($taglist[$record['email']])) {
                $taglist[$record['email']] = [];
            }
            $taglist[$record['email']][] = $record['tag'];
        }
        return $taglist;
    }
}