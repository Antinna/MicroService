<?php

namespace Antinna\Auth\Repositories;

use Antinna\Auth\Database\Connection;
use Antinna\Auth\Interfaces\RepositoryInterface;
use PDO;

/**
 * Base repository implementation
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected PDO $db;
    protected string $table;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
    }

    abstract protected function getTableName(): string;

    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->getTableName()} WHERE id = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findBy(array $criteria): ?array
    {
        $conditions = [];
        $values = [];

        foreach ($criteria as $column => $value) {
            $conditions[] = "{$column} = ?";
            $values[] = $value;
        }

        $whereClause = implode(' AND ', $conditions);
        $sql = "SELECT * FROM {$this->getTableName()} WHERE {$whereClause} LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $criteria = [], int $limit = null, int $offset = null): array
    {
        $conditions = [];
        $values = [];

        foreach ($criteria as $column => $value) {
            $conditions[] = "{$column} = ?";
            $values[] = $value;
        }

        $sql = "SELECT * FROM {$this->getTableName()}";
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
            if ($offset !== null) {
                $sql .= " OFFSET {$offset}";
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO {$this->getTableName()} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $columns = array_keys($data);
        $setClause = implode(' = ?, ', $columns) . ' = ?';
        
        $sql = "UPDATE {$this->getTableName()} SET {$setClause} WHERE id = ?";
        
        $values = array_values($data);
        $values[] = $id;
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM {$this->getTableName()} WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    public function count(array $criteria = []): int
    {
        $conditions = [];
        $values = [];

        foreach ($criteria as $column => $value) {
            $conditions[] = "{$column} = ?";
            $values[] = $value;
        }

        $sql = "SELECT COUNT(*) FROM {$this->getTableName()}";
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        
        return (int)$stmt->fetchColumn();
    }
}