<?php
namespace Antinna\Pay;
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env
$basePath = dirname(__DIR__);
if (file_exists($basePath . '/.env')) {
    Dotenv::createImmutable($basePath)->safeLoad();
}

// Custom env() fallback
function env(string $key, $default = null): mixed {
    return $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
}

// Load helpers
require_once __DIR__ . '/../Helpers/functions.php';
