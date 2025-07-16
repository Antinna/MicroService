<?php

namespace Antinna\MultiVendor\Services;

use Antinna\MultiVendor\Database\Connection;
use PDO;
use Exception;

/**
 * Customer feedback processing and rating calculation service
 */
class FeedbackAggregator
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->createFeedbackTables();
    }

    /**
     * Process customer feedback
     */
    public function processFeedback(array $feedbackData): array
    {
        try {
            // Validate feedback data
            $validation = $this->validateFeedbackData($feedbackData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Create feedback record
            $feedbackId = $this->createFeedbackRecord($feedbackData);

            if ($feedbackId) {
                // Update vendor ratings
                $this->updateVendorRatings($feedbackData['vendor_id']);

                return [
                    'success' => true,
                    'feedback_id' => $feedbackId,
                    'message' => 'Feedback processed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to process feedback'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Feedback processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get vendor ratings and feedback summary
     */
    public function getVendorFeedbackSummary(int $vendorId): array
    {
        try {
            // Overall ratings
            $sql = "SELECT 
                        COUNT(*) as total_reviews,
                        AVG(overall_rating) as avg_overall_rating,
                        AVG(product_quality_rating) as avg_product_quality,
                        AVG(delivery_rating) as avg_delivery_rating,
                        AVG(packaging_rating) as avg_packaging_rating,
                        AVG(service_rating) as avg_service_rating
                    FROM customer_feedback 
                    WHERE vendor_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $ratings = $stmt->fetch();

            // Rating distribution
            $sql = "SELECT 
                        overall_rating,
                        COUNT(*) as count,
                        (COUNT(*) * 100.0 / (SELECT COUNT(*) FROM customer_feedback WHERE vendor_id = ?)) as percentage
                    FROM customer_feedback 
                    WHERE vendor_id = ?
                    GROUP BY overall_rating
                    ORDER BY overall_rating DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $vendorId]);
            $distribution = $stmt->fetchAll();

            // Recent feedback
            $sql = "SELECT * FROM customer_feedback 
                    WHERE vendor_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 10";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId]);
            $recentFeedback = $stmt->fetchAll();

            return [
                'success' => true,
                'vendor_id' => $vendorId,
                'ratings_summary' => [
                    'total_reviews' => (int)$ratings['total_reviews'],
                    'avg_overall_rating' => round($ratings['avg_overall_rating'] ?? 0, 2),
                    'avg_product_quality' => round($ratings['avg_product_quality'] ?? 0, 2),
                    'avg_delivery_rating' => round($ratings['avg_delivery_rating'] ?? 0, 2),
                    'avg_packaging_rating' => round($ratings['avg_packaging_rating'] ?? 0, 2),
                    'avg_service_rating' => round($ratings['avg_service_rating'] ?? 0, 2)
                ],
                'rating_distribution' => $distribution,
                'recent_feedback' => $recentFeedback
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get feedback summary: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate feedback data
     */
    private function validateFeedbackData(array $data): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['vendor_id', 'customer_id', 'order_id', 'overall_rating'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "The {$field} field is required";
            }
        }

        // Rating validations
        $ratingFields = ['overall_rating', 'product_quality_rating', 'delivery_rating', 'packaging_rating', 'service_rating'];
        foreach ($ratingFields as $field) {
            if (isset($data[$field])) {
                if (!is_numeric($data[$field]) || $data[$field] < 1 || $data[$field] > 5) {
                    $errors[$field] = "Rating must be between 1 and 5";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Create feedback record
     */
    private function createFeedbackRecord(array $data): ?int
    {
        try {
            $sql = "INSERT INTO customer_feedback (
                        vendor_id, customer_id, order_id, overall_rating,
                        product_quality_rating, delivery_rating, packaging_rating, service_rating,
                        comments, is_anonymous, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $data['vendor_id'],
                $data['customer_id'],
                $data['order_id'],
                $data['overall_rating'],
                $data['product_quality_rating'] ?? null,
                $data['delivery_rating'] ?? null,
                $data['packaging_rating'] ?? null,
                $data['service_rating'] ?? null,
                $data['comments'] ?? null,
                $data['is_anonymous'] ?? false
            ]);

            return $success ? $this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update vendor ratings
     */
    private function updateVendorRatings(int $vendorId): void
    {
        try {
            $sql = "UPDATE vendors SET 
                        average_rating = (
                            SELECT AVG(overall_rating) 
                            FROM customer_feedback 
                            WHERE vendor_id = ?
                        ),
                        total_reviews = (
                            SELECT COUNT(*) 
                            FROM customer_feedback 
                            WHERE vendor_id = ?
                        )
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$vendorId, $vendorId, $vendorId]);

        } catch (Exception $e) {
            // Log error but don't fail
        }
    }

    /**
     * Create feedback tables
     */
    private function createFeedbackTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS customer_feedback (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            vendor_id BIGINT NOT NULL,
            customer_id BIGINT NOT NULL,
            order_id BIGINT NOT NULL,
            overall_rating TINYINT NOT NULL CHECK (overall_rating BETWEEN 1 AND 5),
            product_quality_rating TINYINT CHECK (product_quality_rating BETWEEN 1 AND 5),
            delivery_rating TINYINT CHECK (delivery_rating BETWEEN 1 AND 5),
            packaging_rating TINYINT CHECK (packaging_rating BETWEEN 1 AND 5),
            service_rating TINYINT CHECK (service_rating BETWEEN 1 AND 5),
            comments TEXT,
            is_anonymous BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_customer_id (customer_id),
            INDEX idx_order_id (order_id),
            INDEX idx_overall_rating (overall_rating),
            INDEX idx_created_at (created_at)
        )";

        $this->db->exec($sql);
    }
}