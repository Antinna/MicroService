<?php

namespace Antinna\MultiVendor\Database;

use Antinna\MultiVendor\Config\Database as DatabaseConfig;
use PDO;
use PDOException;

/**
 * Database connection manager
 */
class Connection
{
    private static ?PDO $connection = null;
    private static ?Connection $instance = null;

    private function __construct() {}

    public static function getInstance(): Connection
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        if (self::$connection === null) {
            $this->connect();
        }
        return self::$connection;
    }

    private function connect(): void
    {
        try {
            $config = DatabaseConfig::getInstance();
            $dbConfig = $config->getConfig();
            
            self::$connection = new PDO(
                $config->getDsn(),
                $dbConfig['username'],
                $dbConfig['password'],
                $dbConfig['options']
            );
            
        } catch (PDOException $e) {
            throw new PDOException("Database connection failed: " . $e->getMessage());
        }
    }

    public function disconnect(): void
    {
        self::$connection = null;
    }

    public function isConnected(): bool
    {
        return self::$connection !== null;
    }
}