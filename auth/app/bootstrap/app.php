<?php

namespace Antinna\Auth;

// Autoload dependencies
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Antinna\Auth\Config\Environment;

// Load environment variables
$basePath = dirname(__DIR__);
if (file_exists("{$basePath}/.env")) {
    Dotenv::createImmutable($basePath)->safeLoad();
}

// Custom env() helper function
if (!function_exists('env')) {
    function env(string $key, $default = null): mixed {
        return $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

// Load helper functions
require_once __DIR__ . '/../Helpers/functions.php';

// Initialize environment configuration
Environment::load();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set timezone
date_default_timezone_set('UTC');
