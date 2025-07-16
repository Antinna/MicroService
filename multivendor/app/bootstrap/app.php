<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Antinna\MultiVendor\Config\App;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Set timezone
$config = App::getInstance();
date_default_timezone_set($config->get('timezone', 'UTC'));

// Error reporting based on environment
if ($config->get('debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set up error handler
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    $errorMessage = "Error: {$message} in {$file} on line {$line}";
    error_log($errorMessage);
    
    if (App::getInstance()->get('debug', false)) {
        echo $errorMessage . PHP_EOL;
    }
    
    return true;
});

// Set up exception handler
set_exception_handler(function ($exception) {
    $errorMessage = "Uncaught exception: " . $exception->getMessage() . 
                   " in " . $exception->getFile() . 
                   " on line " . $exception->getLine();
    
    error_log($errorMessage);
    
    if (App::getInstance()->get('debug', false)) {
        echo $errorMessage . PHP_EOL;
        echo $exception->getTraceAsString() . PHP_EOL;
    }
});

return $config;