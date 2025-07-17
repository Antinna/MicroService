<?php

namespace Antinna\Auth\Database;

use PDO;

/**
 * Migration runner for executing database migrations
 */
class MigrationRunner
{
    private PDO $db;
    private array $migrations = [];

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->loadMigrations();
        $this->createMigrationsTable();
    }

    private function loadMigrations(): void
    {
        $this->migrations = [
            new Migrations\CreateUsersTable(),
            new Migrations\CreateSessionsTable(),
            new Migrations\CreateSocialAccountsTable(),
            new Migrations\CreatePasskeysTable(),
            new Migrations\CreateAuditLogsTable(),
            new Migrations\CreateTokenBlacklistTable(),
        ];
    }

    private function createMigrationsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $this->db->exec($sql);
    }

    public function runMigrations(): array
    {
        $results = [];
        
        foreach ($this->migrations as $migration) {
            $migrationName = $migration->getName();
            
            if ($this->isMigrationExecuted($migrationName)) {
                $results[] = [
                    'migration' => $migrationName,
                    'status' => 'skipped',
                    'message' => 'Already executed'
                ];
                continue;
            }

            try {
                $this->db->beginTransaction();
                
                $migration->up();
                $this->markMigrationAsExecuted($migrationName);
                
                $this->db->commit();
                
                $results[] = [
                    'migration' => $migrationName,
                    'status' => 'success',
                    'message' => 'Executed successfully'
                ];
            } catch (\Exception $e) {
                $this->db->rollback();
                
                $results[] = [
                    'migration' => $migrationName,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    public function rollbackMigrations(): array
    {
        $results = [];
        $reversedMigrations = array_reverse($this->migrations);
        
        foreach ($reversedMigrations as $migration) {
            $migrationName = $migration->getName();
            
            if (!$this->isMigrationExecuted($migrationName)) {
                $results[] = [
                    'migration' => $migrationName,
                    'status' => 'skipped',
                    'message' => 'Not executed'
                ];
                continue;
            }

            try {
                $this->db->beginTransaction();
                
                $migration->down();
                $this->removeMigrationRecord($migrationName);
                
                $this->db->commit();
                
                $results[] = [
                    'migration' => $migrationName,
                    'status' => 'success',
                    'message' => 'Rolled back successfully'
                ];
            } catch (\Exception $e) {
                $this->db->rollback();
                
                $results[] = [
                    'migration' => $migrationName,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    private function isMigrationExecuted(string $migrationName): bool
    {
        $sql = "SELECT COUNT(*) FROM migrations WHERE migration = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$migrationName]);
        return $stmt->fetchColumn() > 0;
    }

    private function markMigrationAsExecuted(string $migrationName): void
    {
        $sql = "INSERT INTO migrations (migration) VALUES (?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$migrationName]);
    }

    private function removeMigrationRecord(string $migrationName): void
    {
        $sql = "DELETE FROM migrations WHERE migration = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$migrationName]);
    }

    public function getExecutedMigrations(): array
    {
        $sql = "SELECT migration, executed_at FROM migrations ORDER BY executed_at";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}