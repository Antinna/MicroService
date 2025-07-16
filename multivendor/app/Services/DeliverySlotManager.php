<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use Antinna\MultiVendor\Repositories\VendorRepository;
use PDO;
use Exception;
use DateTime;

/**
 * Delivery slot management service with freshness window constraints
 */
class DeliverySlotManager
{
    private PDO $db;
    private VendorRepository $vendorRepository;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->vendorRepository = new VendorRepository();
    }

    /**
     * Validate delivery slot data
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = ['vendor_id', 'slot_name', 'start_time', 'end_time', 'max_capacity', 'days_of_week'];

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Vendor ID validation
        if (!empty($data['vendor_id'])) {
            if (!is_numeric($data['vendor_id'])) {
                $errors['vendor_id'] = 'Vendor ID must be a valid number';
            } else {
                $vendor = $this->vendorRepository->find($data['vendor_id']);
                if (!$vendor) {
                    $errors['vendor_id'] = 'Vendor not found';
                } elseif ($vendor['status'] !== 'active') {
                    $errors['vendor_id'] = 'Vendor must be active to create delivery slots';
                }
            }
        }

        // Slot name validation
        if (!empty($data['slot_name'])) {
            if (strlen($data['slot_name']) < 3) {
                $errors['slot_name'] = 'Slot name must be at least 3 characters long';
            }
            if (strlen($data['slot_name']) > 100) {
                $errors['slot_name'] = 'Slot name must not exceed 100 characters';
            }
        }

        // Time validation
        if (!empty($data['start_time'])) {
            if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $data['start_time'])) {
                $errors['start_time'] = 'Start time must be in HH:MM:SS format';
            }
        }

        if (!empty($data['end_time'])) {
            if (!preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $data['end_time'])) {
                $errors['end_time'] = 'End time must be in HH:MM:SS format';
            }
        }

        // Cross-time validation
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            $startTime = DateTime::createFromFormat('H:i:s', $data['start_time']);
            $endTime = DateTime::createFromFormat('H:i:s', $data['end_time']);
            
            if ($startTime && $endTime && $endTime <= $startTime) {
                $errors['end_time'] = 'End time must be after start time';
            }
        }

        // Capacity validation
        if (!empty($data['max_capacity'])) {
            if (!is_numeric($data['max_capacity']) || $data['max_capacity'] <= 0) {
                $errors['max_capacity'] = 'Max capacity must be a positive number';
            }
            if ($data['max_capacity'] > 10000) {
                $errors['max_capacity'] = 'Max capacity cannot exceed 10,000';
            }
        }

        // Freshness window validation
        if (!empty($data['freshness_window_hours'])) {
            if (!is_numeric($data['freshness_window_hours']) || $data['freshness_window_hours'] <= 0) {
                $errors['freshness_window_hours'] = 'Freshness window must be a positive number';
            }
            if ($data['freshness_window_hours'] > 168) { // 1 week
                $errors['freshness_window_hours'] = 'Freshness window cannot exceed 168 hours (1 week)';
            }
        }

        // Days of week validation
        if (!empty($data['days_of_week'])) {
            if (is_string($data['days_of_week'])) {
                $data['days_of_week'] = json_decode($data['days_of_week'], true);
            }
            
            if (!is_array($data['days_of_week'])) {
                $errors['days_of_week'] = 'Days of week must be an array';
            } else {
                $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                foreach ($data['days_of_week'] as $day) {
                    if (!in_array(strtolower($day), $validDays)) {
                        $errors['days_of_week'] = 'Invalid day: ' . $day;
                        break;
                    }
                }
                
                if (empty($data['days_of_week'])) {
                    $errors['days_of_week'] = 'At least one day must be specified';
                }
            }
        }

        // Delivery fee validation
        if (!empty($data['delivery_fee'])) {
            if (!is_numeric($data['delivery_fee']) || $data['delivery_fee'] < 0) {
                $errors['delivery_fee'] = 'Delivery fee must be a non-negative number';
            }
            if ($data['delivery_fee'] > 9999.99) {
                $errors['delivery_fee'] = 'Delivery fee cannot exceed 9,999.99';
            }
        }

        // Boolean validations
        if (isset($data['requires_cold_chain'])) {
            $data['requires_cold_chain'] = filter_var($data['requires_cold_chain'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Create delivery slot
     */
    public function createDeliverySlot(array $data): array
    {
        try {
            // Validate slot data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for overlapping slots
            $overlapCheck = $this->checkSlotOverlap(
                $data['vendor_id'],
                $data['start_time'],
                $data['end_time'],
                $data['days_of_week']
            );

            if (!$overlapCheck['valid']) {
                return [
                    'success' => false,
                    'errors' => ['overlap' => $overlapCheck['message']]
                ];
            }

            // Prepare slot data
            $slotData = [
                'vendor_id' => $validation['data']['vendor_id'],
                'slot_name' => $validation['data']['slot_name'],
                'start_time' => $validation['data']['start_time'],
                'end_time' => $validation['data']['end_time'],
                'max_capacity' => $validation['data']['max_capacity'],
                'current_bookings' => 0,
                'freshness_window_hours' => $validation['data']['freshness_window_hours'] ?? 24,
                'requires_cold_chain' => $validation['data']['requires_cold_chain'] ?? false,
                'delivery_fee' => $validation['data']['delivery_fee'] ?? 0.00,
                'is_active' => $validation['data']['is_active'] ?? true,
                'days_of_week' => json_encode($validation['data']['days_of_week'])
            ];

            // Create slot
            $sql = "INSERT INTO delivery_slots (" . implode(', ', array_keys($slotData)) . ") 
                    VALUES (" . str_repeat('?,', count($slotData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(array_values($slotData));

            if ($success) {
                $slotId = $this->db->lastInsertId();
                
                return [
                    'success' => true,
                    'slot_id' => $slotId,
                    'message' => 'Delivery slot created successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create delivery slot'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery slot creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update delivery slot
     */
    public function updateDeliverySlot(int $slotId, array $data): array
    {
        try {
            // Check if slot exists
            $existingSlot = $this->getDeliverySlot($slotId);
            if (!$existingSlot['found']) {
                return [
                    'success' => false,
                    'error' => 'Delivery slot not found'
                ];
            }

            $slot = $existingSlot['slot'];
            $data['vendor_id'] = $slot['vendor_id']; // Ensure vendor_id for validation

            // Validate update data
            $validation = $this->validate($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Check for overlapping slots (excluding current slot)
            if (isset($data['start_time']) || isset($data['end_time']) || isset($data['days_of_week'])) {
                $overlapCheck = $this->checkSlotOverlap(
                    $slot['vendor_id'],
                    $data['start_time'] ?? $slot['start_time'],
                    $data['end_time'] ?? $slot['end_time'],
                    $data['days_of_week'] ?? json_decode($slot['days_of_week'], true),
                    $slotId
                );

                if (!$overlapCheck['valid']) {
                    return [
                        'success' => false,
                        'errors' => ['overlap' => $overlapCheck['message']]
                    ];
                }
            }

            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'slot_name', 'start_time', 'end_time', 'max_capacity',
                'freshness_window_hours', 'requires_cold_chain', 'delivery_fee', 'is_active'
            ];

            foreach ($allowedFields as $field) {
                if (isset($validation['data'][$field])) {
                    $updateData[$field] = $validation['data'][$field];
                }
            }

            if (isset($validation['data']['days_of_week'])) {
                $updateData['days_of_week'] = json_encode($validation['data']['days_of_week']);
            }

            if (empty($updateData)) {
                return [
                    'success' => false,
                    'error' => 'No valid fields to update'
                ];
            }

            // Update slot
            $setParts = array_map(fn($key) => "{$key} = ?", array_keys($updateData));
            $sql = "UPDATE delivery_slots SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            $params = array_values($updateData);
            $params[] = $slotId;
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Delivery slot updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to update delivery slot'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery slot update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get delivery slot details
     */
    public function getDeliverySlot(int $slotId): array
    {
        try {
            $sql = "SELECT ds.*, v.business_name as vendor_name
                    FROM delivery_slots ds
                    JOIN vendors v ON ds.vendor_id = v.id
                    WHERE ds.id = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch();

            if (!$slot) {
                return [
                    'found' => false,
                    'error' => 'Delivery slot not found'
                ];
            }

            // Decode JSON fields
            $slot['days_of_week'] = json_decode($slot['days_of_week'], true);

            return [
                'found' => true,
                'slot' => $slot
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => 'Error retrieving delivery slot: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get vendor delivery slots
     */
    public function getVendorDeliverySlots(int $vendorId, ?bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT * FROM delivery_slots WHERE vendor_id = ?";
            $params = [$vendorId];

            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }

            $sql .= " ORDER BY start_time";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $slots = $stmt->fetchAll();

            // Decode JSON fields
            foreach ($slots as &$slot) {
                $slot['days_of_week'] = json_decode($slot['days_of_week'], true);
            }

            return [
                'success' => true,
                'slots' => $slots
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving vendor delivery slots: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get available slots for date and requirements
     */
    public function getAvailableSlots(int $vendorId, string $date, ?bool $requiresColdChain = null): array
    {
        try {
            $dayOfWeek = strtolower(date('l', strtotime($date)));
            
            $sql = "SELECT ds.*, 
                           (ds.max_capacity - ds.current_bookings) as available_capacity
                    FROM delivery_slots ds
                    WHERE ds.vendor_id = ?
                    AND ds.is_active = 1
                    AND JSON_CONTAINS(ds.days_of_week, ?)";

            $params = [$vendorId, json_encode($dayOfWeek)];

            if ($requiresColdChain !== null) {
                $sql .= " AND ds.requires_cold_chain = ?";
                $params[] = $requiresColdChain;
            }

            $sql .= " ORDER BY ds.start_time";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $slots = $stmt->fetchAll();

            // Decode JSON fields and filter by availability
            $availableSlots = [];
            foreach ($slots as $slot) {
                $slot['days_of_week'] = json_decode($slot['days_of_week'], true);
                
                if ($slot['available_capacity'] > 0) {
                    $availableSlots[] = $slot;
                }
            }

            return [
                'success' => true,
                'date' => $date,
                'day_of_week' => $dayOfWeek,
                'slots' => $availableSlots
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Error retrieving available slots: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check slot availability for booking
     */
    public function checkSlotAvailability(int $slotId, string $date, int $quantity = 1): array
    {
        try {
            $slot = $this->getDeliverySlot($slotId);
            if (!$slot['found']) {
                return [
                    'available' => false,
                    'error' => 'Delivery slot not found'
                ];
            }

            $slotData = $slot['slot'];

            // Check if slot is active
            if (!$slotData['is_active']) {
                return [
                    'available' => false,
                    'error' => 'Delivery slot is not active'
                ];
            }

            // Check if date matches slot days
            $dayOfWeek = strtolower(date('l', strtotime($date)));
            if (!in_array($dayOfWeek, $slotData['days_of_week'])) {
                return [
                    'available' => false,
                    'error' => 'Delivery slot not available on ' . ucfirst($dayOfWeek)
                ];
            }

            // Check freshness window
            $deliveryDateTime = new DateTime($date . ' ' . $slotData['start_time']);
            $now = new DateTime();
            $hoursDifference = ($deliveryDateTime->getTimestamp() - $now->getTimestamp()) / 3600;

            if ($hoursDifference > $slotData['freshness_window_hours']) {
                return [
                    'available' => false,
                    'error' => 'Delivery date exceeds freshness window of ' . $slotData['freshness_window_hours'] . ' hours'
                ];
            }

            // Check capacity
            $currentBookings = $this->getCurrentBookings($slotId, $date);
            $availableCapacity = $slotData['max_capacity'] - $currentBookings;

            if ($quantity > $availableCapacity) {
                return [
                    'available' => false,
                    'error' => 'Insufficient capacity. Available: ' . $availableCapacity . ', Requested: ' . $quantity
                ];
            }

            return [
                'available' => true,
                'available_capacity' => $availableCapacity,
                'max_capacity' => $slotData['max_capacity'],
                'current_bookings' => $currentBookings,
                'freshness_window_hours' => $slotData['freshness_window_hours'],
                'delivery_fee' => $slotData['delivery_fee']
            ];

        } catch (Exception $e) {
            return [
                'available' => false,
                'error' => 'Availability check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Book delivery slot
     */
    public function bookDeliverySlot(int $slotId, string $date, int $quantity = 1): array
    {
        try {
            $availabilityCheck = $this->checkSlotAvailability($slotId, $date, $quantity);
            
            if (!$availabilityCheck['available']) {
                return [
                    'success' => false,
                    'error' => $availabilityCheck['error']
                ];
            }

            // Update current bookings
            $sql = "UPDATE delivery_slots 
                    SET current_bookings = current_bookings + ? 
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$quantity, $slotId]);

            if ($success) {
                return [
                    'success' => true,
                    'booked_quantity' => $quantity,
                    'delivery_fee' => $availabilityCheck['delivery_fee'],
                    'message' => 'Delivery slot booked successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to book delivery slot'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery slot booking failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Release delivery slot booking
     */
    public function releaseDeliverySlot(int $slotId, int $quantity = 1): array
    {
        try {
            $sql = "UPDATE delivery_slots 
                    SET current_bookings = GREATEST(0, current_bookings - ?) 
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$quantity, $slotId]);

            if ($success) {
                return [
                    'success' => true,
                    'released_quantity' => $quantity,
                    'message' => 'Delivery slot booking released successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to release delivery slot booking'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Delivery slot release failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check for slot overlap
     */
    private function checkSlotOverlap(int $vendorId, string $startTime, string $endTime, array $daysOfWeek, ?int $excludeSlotId = null): array
    {
        try {
            $sql = "SELECT id, slot_name, start_time, end_time, days_of_week
                    FROM delivery_slots 
                    WHERE vendor_id = ? AND is_active = 1";
            
            $params = [$vendorId];

            if ($excludeSlotId) {
                $sql .= " AND id != ?";
                $params[] = $excludeSlotId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $existingSlots = $stmt->fetchAll();

            foreach ($existingSlots as $slot) {
                $existingDays = json_decode($slot['days_of_week'], true);
                
                // Check if days overlap
                $dayOverlap = array_intersect($daysOfWeek, $existingDays);
                if (!empty($dayOverlap)) {
                    // Check if times overlap
                    $newStart = DateTime::createFromFormat('H:i:s', $startTime);
                    $newEnd = DateTime::createFromFormat('H:i:s', $endTime);
                    $existingStart = DateTime::createFromFormat('H:i:s', $slot['start_time']);
                    $existingEnd = DateTime::createFromFormat('H:i:s', $slot['end_time']);
                    
                    if (($newStart < $existingEnd) && ($newEnd > $existingStart)) {
                        return [
                            'valid' => false,
                            'message' => "Time slot overlaps with existing slot '{$slot['slot_name']}' on " . implode(', ', $dayOverlap)
                        ];
                    }
                }
            }

            return ['valid' => true];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'message' => 'Overlap check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get current bookings for slot and date
     */
    private function getCurrentBookings(int $slotId, string $date): int
    {
        try {
            // This would typically query actual orders/bookings
            // For now, we use the current_bookings field as a simple counter
            $sql = "SELECT current_bookings FROM delivery_slots WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$slotId]);
            
            return (int) $stmt->fetchColumn();

        } catch (Exception $e) {
            return 0;
        }
    }
}