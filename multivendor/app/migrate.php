<?php

/**
 * Migration runner script
 * Usage: php migrate.php [action]
 * Actions: migrate, rollback, status
 */

require_once __DIR__ . '/bootstrap/app.php';

use Antinna\MultiVendor\Database\Migration;

function printUsage() {
    echo "Usage: php migrate.php [action]\n";
    echo "Actions:\n";
    echo "  migrate  - Run all pending migrations\n";
    echo "  rollback - Rollback the last migration\n";
    echo "  status   - Show migration status\n";
}

function printResults(array $results) {
    foreach ($results as $result) {
        if (isset($result['migration'])) {
            $status = $result['status'] === 'success' ? '✓' : '✗';
            echo "{$status} {$result['migration']}: {$result['status']}\n";
            if (isset($result['error'])) {
                echo "   Error: {$result['error']}\n";
            }
        }
    }
}

try {
    $action = $argv[1] ?? 'status';
    $migration = new Migration();
    
    switch ($action) {
        case 'migrate':
            echo "Running migrations...\n";
            $results = $migration->migrate();
            printResults($results);
            echo "Migration completed.\n";
            break;
            
        case 'rollback':
            echo "Rolling back last migration...\n";
            $result = $migration->rollback();
            if ($result['status'] === 'no_migrations') {
                echo "No migrations to rollback.\n";
            } else {
                printResults([$result]);
                echo "Rollback completed.\n";
            }
            break;
            
        case 'status':
            $status = $migration->getStatus();
            echo "Migration Status:\n";
            echo "Executed migrations:\n";
            foreach ($status['executed'] as $executed) {
                echo "  ✓ {$executed}\n";
            }
            echo "Pending migrations:\n";
            foreach ($status['pending'] as $pending) {
                echo "  - {$pending}\n";
            }
            break;
            
        default:
            printUsage();
            exit(1);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}