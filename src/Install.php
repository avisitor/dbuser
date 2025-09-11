<?php
namespace Common\User;

use PDO;use Throwable;use RuntimeException;

class Install {
    public static function install(PDO $pdo): void {
        $sqlFile = __DIR__ . '/../sql/users.sql';
        if (!is_readable($sqlFile)) {
            throw new RuntimeException('Missing users.sql in package');
        }
        $sql = file_get_contents($sqlFile);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
            if ($stmt) { $pdo->exec($stmt); }
        }
    }
}
