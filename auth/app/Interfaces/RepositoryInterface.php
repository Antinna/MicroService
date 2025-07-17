<?php

namespace Antinna\Auth\Interfaces;

/**
 * Base repository interface for data access
 */
interface RepositoryInterface
{
    /**
     * Find record by ID
     */
    public function find(int $id): ?array;

    /**
     * Find record by criteria
     */
    public function findBy(array $criteria): ?array;

    /**
     * Find all records matching criteria
     */
    public function findAll(array $criteria = [], int $limit = null, int $offset = null): array;

    /**
     * Create new record
     */
    public function create(array $data): int;

    /**
     * Update record by ID
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete record by ID
     */
    public function delete(int $id): bool;

    /**
     * Count records matching criteria
     */
    public function count(array $criteria = []): int;
}