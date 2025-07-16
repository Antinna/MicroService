<?php

namespace Antinna\MultiVendor\Repositories;

use Antinna\MultiVendor\Interfaces\RepositoryInterface;
use Antinna\MultiVendor\Database\Connection;
use PDO;
use PDOException;

/**
 * Base repository class implementing common CRUD operations
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected PDO $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    public function find(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            return $result ?: null;
        } catch (PDOException $e) {
            throw new PDOException("Error finding record: " . $e->getMessage());
        }
    }

    public function findAll(array $conditions = []): array
    {
        try {
            $sql = "SELECT * FROM {$this->table}";
            $params = [];
            
            if (!empty($conditions)) {
                $whereClause = [];
                foreach ($conditions as $column => $value) {
                    $whereClause[] = "{$column} = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $whereClause);
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new PDOException("Error fetching records: " . $e->getMessage());
        }
    }

    public function create(array $data): int
    {
        try {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            
            $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($data));
            
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new PDOException("Error creating record: " . $e->getMessage());
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            $columns = array_keys($data);
            $setClause = array_map(fn($col) => "{$col} = ?", $columns);
            
            $sql = "UPDATE {$this->table} SET " . implode(', ', $setClause) . " WHERE {$this->primaryKey} = ?";
            
            $params = array_values($data);
            $params[] = $id;
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new PDOException("Error updating record: " . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new PDOException("Error deleting record: " . $e->getMessage());
        }
    }

    public function count(array $conditions = []): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM {$this->table}";
            $params = [];
            
            if (!empty($conditions)) {
                $whereClause = [];
                foreach ($conditions as $column => $value) {
                    $whereClause[] = "{$column} = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $whereClause);
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new PDOException("Error counting records: " . $e->getMessage());
        }
    }
}