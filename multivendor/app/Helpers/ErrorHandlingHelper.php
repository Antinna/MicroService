<?php

namespace Antinna\Multivendor\Helpers;

use Antinna\Multivendor\Services\ErrorHandler;
use Antinna\Multivendor\Services\Logger;
use Antinna\Multivendor\Middleware\LoggingMiddleware;

class ErrorHandlingHelper
{
    private static ?ErrorHandler $errorHandler = null;
    private static ?Logger $logger = null;
    private static ?LoggingMiddleware $loggingMiddleware = null;

    public static function getLogger(): Logger
    {
        if (self::$logger === null) {
            self::$logger = new Logger();
        }
        return self::$logger;
    }

    public static function getErrorHandler(): ErrorHandler
    {
        if (self::$errorHandler === null) {
            self::$errorHandler = new ErrorHandler(self::getLogger());
        }
        return self::$errorHandler;
    }

    public static function getLoggingMiddleware(): LoggingMiddleware
    {
        if (self::$loggingMiddleware === null) {
            self::$loggingMiddleware = new LoggingMiddleware(self::getLogger());
        }
        return self::$loggingMiddleware;
    }

    /**
     * Set up global error and exception handlers
     */
    public static function setupGlobalHandlers(): void
    {
        $errorHandler = self::getErrorHandler();
        $logger = self::getLogger();

        // Set custom error handler
        set_error_handler(function($severity, $message, $file, $line) use ($logger) {
            $logger->error('PHP Error', [
                'severity' => $severity,
                'message' => $message,
                'file' => $file,
                'line' => $line
            ]);
            
            // Don't execute PHP internal error handler
            return true;
        });

        // Set custom exception handler
        set_exception_handler(function($exception) use ($errorHandler) {
            $response = $errorHandler->handleException($exception);
            
            // Set appropriate HTTP status code
            http_response_code($response['code']);
            
            // Output JSON response
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        });

        // Set shutdown function to catch fatal errors
        register_shutdown_function(function() use ($logger) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $logger->critical('Fatal Error', [
                    'type' => $error['type'],
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line']
                ]);
            }
        });
    }

    /**
     * Handle API response with proper error formatting
     */
    public static function handleApiResponse(callable $callback): void
    {
        $errorHandler = self::getErrorHandler();
        
        try {
            $result = $callback();
            
            // Set success response
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($result);
            
        } catch (\Throwable $e) {
            $response = $errorHandler->handleException($e);
            
            http_response_code($response['code']);
            header('Content-Type: application/json');
            echo json_encode($response);
        }
    }

    /**
     * Wrap request with logging middleware
     */
    public static function withLogging(callable $callback)
    {
        $middleware = self::getLoggingMiddleware();
        return $middleware->handle($callback);
    }
}