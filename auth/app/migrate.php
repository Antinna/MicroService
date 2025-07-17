<?php

require_once __DIR__ . '/bootstrap/app.php';

use Antinna\Auth\Database\MigrationRunner;

$command = $argv[1] ?? 'up';

$runner = new MigrationRunner();

echo "Auth Service Database Migration Tool\n";
echo "====================================\n\n";

switch ($command) {
    case 'up':
        echo "Running migrations...\n\n";
        $results = $runner->runMigrations();
        break;
        
    case 'down':
        echo "Rolling back migrations...\n\n";
        $results = $runner->rollbackMigrations();
        break;
        
    case 'status':
        echo "Migration status:\n\n";
        $executed = $runner->getExecutedMigrations();
        if (empty($executed)) {
            echo "No migrations have been executed.\n";
        } else {
            foreach ($executed as $migration) {
                echo "✓ {$migration['migration']} (executed at {$migration['executed_at']})\n";
            }
        }
        exit(0);
        
    default:
        echo "Usage: php migrate.php [up|down|status]\n";
        echo "  up     - Run pending migrations\n";
        echo "  down   - Rollback all migrations\n";
        echo "  status - Show migration status\n";
        exit(1);
}

foreach ($results as $result) {
    $status = $result['status'];
    $icon = $status === 'success' ? '✓' : ($status === 'error' ? '✗' : '-');
    $color = $status === 'success' ? "\033[32m" : ($status === 'error' ? "\033[31m" : "\033[33m");
    $reset = "\033[0m";
    
    echo "{$color}{$icon} {$result['migration']}: {$result['message']}{$reset}\n";
}

echo "\nMigration completed.\n";