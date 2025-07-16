<?php

namespace Antinna\MultiVendor\Database;

use PDO;

/**
 * Base migration class
 */
abstract class BaseMigration
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Run the migration
     */
    abstract public function up(): void;

    /**
     * Reverse the migration
     */
    abstract public function down(): void;

    /**
     * Helper method to execute SQL
     */
    protected function execute(string $sql): void
    {
        $this->db->exec($sql);
    }

    /**
     * Helper method to check if table exists
     */
    protected function tableExists(string $tableName): bool
    {
        $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Helper method to check if column exists
     */
    protected function columnExists(string $tableName, string $columnName): bool
    {
        $stmt = $this->db->prepare("SHOW COLUMNS FROM {$tableName} LIKE ?");
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    }
}