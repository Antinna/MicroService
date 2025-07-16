<?php

namespace Antinna\MultiVendor\Interfaces;

/**
 * Base repository interface for CRUD operations
 */
interface RepositoryInterface
{
    /**
     * Find a record by ID
     */
    public function find(int $id): ?array;

    /**
     * Find all records with optional conditions
     */
    public function findAll(array $conditions = []): array;

    /**
     * Create a new record
     */
    public function create(array $data): int;

    /**
     * Update a record by ID
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete a record by ID
     */
    public function delete(int $id): bool;

    /**
     * Count records with optional conditions
     */
    public function count(array $conditions = []): int;
}