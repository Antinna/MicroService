<?php

namespace Antinna\MultiVendor\Repositories;

/**
 * Vendor repository for database operations
 */
class VendorRepository extends BaseRepository
{
    protected string $table = 'vendors';

    /**
     * Find vendors by business type
     */
    public function findByBusinessType(string $businessType): array
    {
        return $this->findAll(['business_type' => $businessType]);
    }

    /**
     * Find vendors by status
     */
    public function findByStatus(string $status): array
    {
        return $this->findAll(['status' => $status]);
    }

    /**
     * Find vendors within radius of coordinates
     */
    public function findNearby(float $latitude, float $longitude, float $radiusKm = 10): array
    {
        $sql = "SELECT *, 
                (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * 
                cos(radians(longitude) - radians(?)) + sin(radians(?)) * 
                sin(radians(latitude)))) AS distance 
                FROM {$this->table} 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                HAVING distance < ? 
                ORDER BY distance";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$latitude, $longitude, $latitude, $radiusKm]);
        
        return $stmt->fetchAll();
    }

    /**
     * Find cold-chain capable vendors
     */
    public function findColdChainCapable(): array
    {
        return $this->findAll(['cold_chain_capable' => true, 'status' => 'active']);
    }

    /**
     * Search vendors by name or business type
     */
    public function search(string $query): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE (business_name LIKE ? OR business_type LIKE ?) 
                AND status = 'active'
                ORDER BY business_name";
        
        $searchTerm = "%{$query}%";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm]);
        
        return $stmt->fetchAll();
    }
}