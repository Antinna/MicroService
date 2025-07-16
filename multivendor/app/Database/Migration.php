<?php

namespace Antinna\MultiVendor\Database;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use PDOException;
use Exception;

/**
 * Database migration manager
 */
class Migration
{
    private PDO $db;
    private string $migrationsTable = 'migrations';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->createMigrationsTable();
    }

    /**
     * Create migrations tracking table
     */
    private function createMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $this->db->exec($sql);
    }

    /**
     * Run all pending migrations
     */
    public function migrate(): array
    {
        $results = [];
        $migrations = $this->getPendingMigrations();
        
        foreach ($migrations as $migration) {
            try {
                $this->db->beginTransaction();
                
                // Execute migration
                $migrationClass = $this->loadMigration($migration);
                $migrationClass->up();
                
                // Record migration
                $this->recordMigration($migration);
                
                $this->db->commit();
                $results[] = ['migration' => $migration, 'status' => 'success'];
                
            } catch (Exception $e) {
                $this->db->rollBack();
                $results[] = ['migration' => $migration, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }
        
        return $results;
    }

    /**
     * Rollback last migration
     */
    public function rollback(): array
    {
        $lastMigration = $this->getLastMigration();
        
        if (!$lastMigration) {
            return ['status' => 'no_migrations'];
        }
        
        try {
            $this->db->beginTransaction();
            
            $migrationClass = $this->loadMigration($lastMigration);
            $migrationClass->down();
            
            $this->removeMigrationRecord($lastMigration);
            
            $this->db->commit();
            return ['migration' => $lastMigration, 'status' => 'rolled_back'];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['migration' => $lastMigration, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Get pending migrations
     */
    private function getPendingMigrations(): array
    {
        $migrationFiles = glob(__DIR__ . '/Migrations/*.php');
        $executedMigrations = $this->getExecutedMigrations();
        
        $pending = [];
        foreach ($migrationFiles as $file) {
            $migration = basename($file, '.php');
            if (!in_array($migration, $executedMigrations)) {
                $pending[] = $migration;
            }
        }
        
        sort($pending);
        return $pending;
    }

    /**
     * Get executed migrations
     */
    private function getExecutedMigrations(): array
    {
        $stmt = $this->db->query("SELECT migration FROM {$this->migrationsTable} ORDER BY executed_at");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get last executed migration
     */
    private function getLastMigration(): ?string
    {
        $stmt = $this->db->query("SELECT migration FROM {$this->migrationsTable} ORDER BY executed_at DESC LIMIT 1");
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Load migration class
     */
    private function loadMigration(string $migration): object
    {
        $file = __DIR__ . "/Migrations/{$migration}.php";
        require_once $file;
        
        $className = "Antinna\\MultiVendor\\Database\\Migrations\\{$migration}";
        return new $className($this->db);
    }

    /**
     * Record executed migration
     */
    private function recordMigration(string $migration): void
    {
        $stmt = $this->db->prepare("INSERT INTO {$this->migrationsTable} (migration) VALUES (?)");
        $stmt->execute([$migration]);
    }

    /**
     * Remove migration record
     */
    private function removeMigrationRecord(string $migration): void
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->migrationsTable} WHERE migration = ?");
        $stmt->execute([$migration]);
    }

    /**
     * Get migration status
     */
    public function getStatus(): array
    {
        return [
            'executed' => $this->getExecutedMigrations(),
            'pending' => $this->getPendingMigrations()
        ];
    }
}