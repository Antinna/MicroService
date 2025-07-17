<?php

namespace Antinna\Auth\Database;

use PDO;

/**
 * Base migration class
 */
abstract class Migration
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
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
     * Get migration name
     */
    abstract public function getName(): string;

    /**
     * Execute SQL statement
     */
    protected function execute(string $sql): void
    {
        $this->db->exec($sql);
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $tableName): bool
    {
        $sql = "SHOW TABLES LIKE ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if column exists in table
     */
    protected function columnExists(string $tableName, string $columnName): bool
    {
        $sql = "SHOW COLUMNS FROM {$tableName} LIKE ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    }
}