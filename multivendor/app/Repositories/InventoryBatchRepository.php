<?php

namespace Antinna\MultiVendor\Repositories;

/**
 * Inventory batch repository for database operations
 */
class InventoryBatchRepository extends BaseRepository
{
    protected string $table = 'inventory_batches';

    /**
     * Find batches by product
     */
    public function findByProduct(int $productId): array
    {
        return $this->findAll(['product_id' => $productId]);
    }

    /**
     * Find batches expiring soon
     */
    public function findExpiringSoon(int $days = 3): array
    {
        $sql = "SELECT ib.*, p.name as product_name, v.business_name as vendor_name
                FROM {$this->table} ib
                JOIN products p ON ib.product_id = p.id
                JOIN vendors v ON p.vendor_id = v.id
                WHERE ib.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND ib.expiry_date > CURDATE()
                AND ib.quantity_available > 0
                AND ib.is_recalled = FALSE
                ORDER BY ib.expiry_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        
        return $stmt->fetchAll();
    }

    /**
     * Find expired batches
     */
    public function findExpired(): array
    {
        $sql = "SELECT ib.*, p.name as product_name, v.business_name as vendor_name
                FROM {$this->table} ib
                JOIN products p ON ib.product_id = p.id
                JOIN vendors v ON p.vendor_id = v.id
                WHERE ib.expiry_date < CURDATE()
                AND ib.quantity_available > 0
                AND ib.is_recalled = FALSE
                ORDER BY ib.expiry_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Find available batches for a product
     */
    public function findAvailableForProduct(int $productId): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE product_id = ?
                AND expiry_date > CURDATE()
                AND quantity_available > quantity_reserved
                AND is_recalled = FALSE
                ORDER BY expiry_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Reserve quantity from batch
     */
    public function reserveQuantity(int $batchId, int $quantity): bool
    {
        $sql = "UPDATE {$this->table} 
                SET quantity_reserved = quantity_reserved + ?
                WHERE id = ? 
                AND quantity_available >= quantity_reserved + ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$quantity, $batchId, $quantity]);
    }

    /**
     * Release reserved quantity
     */
    public function releaseQuantity(int $batchId, int $quantity): bool
    {
        $sql = "UPDATE {$this->table} 
                SET quantity_reserved = GREATEST(0, quantity_reserved - ?)
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$quantity, $batchId]);
    }
}