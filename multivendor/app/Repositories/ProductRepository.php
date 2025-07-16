<?php

namespace Antinna\MultiVendor\Repositories;

/**
 * Product repository for database operations
 */
class ProductRepository extends BaseRepository
{
    protected string $table = 'products';

    /**
     * Find products by vendor
     */
    public function findByVendor(int $vendorId): array
    {
        return $this->findAll(['vendor_id' => $vendorId, 'status' => 'active']);
    }

    /**
     * Find products by category
     */
    public function findByCategory(string $category): array
    {
        return $this->findAll(['category' => $category, 'status' => 'active']);
    }

    /**
     * Find organic products
     */
    public function findOrganic(): array
    {
        return $this->findAll(['is_organic' => true, 'status' => 'active']);
    }

    /**
     * Find products requiring cold chain
     */
    public function findRequiringColdChain(): array
    {
        return $this->findAll(['requires_cold_chain' => true, 'status' => 'active']);
    }

    /**
     * Search products by name
     */
    public function search(string $query): array
    {
        $sql = "SELECT p.*, v.business_name as vendor_name 
                FROM {$this->table} p
                JOIN vendors v ON p.vendor_id = v.id
                WHERE p.name LIKE ? AND p.status = 'active' AND v.status = 'active'
                ORDER BY p.name";
        
        $searchTerm = "%{$query}%";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm]);
        
        return $stmt->fetchAll();
    }

    /**
     * Find products with low stock (based on available batches)
     */
    public function findLowStock(int $threshold = 10): array
    {
        $sql = "SELECT p.*, v.business_name as vendor_name,
                SUM(ib.quantity_available) as total_stock
                FROM {$this->table} p
                JOIN vendors v ON p.vendor_id = v.id
                LEFT JOIN inventory_batches ib ON p.id = ib.product_id 
                    AND ib.expiry_date > CURDATE()
                WHERE p.status = 'active' AND v.status = 'active'
                GROUP BY p.id
                HAVING total_stock < ? OR total_stock IS NULL
                ORDER BY total_stock ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$threshold]);
        
        return $stmt->fetchAll();
    }
}