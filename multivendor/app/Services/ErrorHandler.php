<?php

namespace Antinna\Multivendor\Services;

use Exception;
use Throwable;

class ErrorHandler
{
    private Logger $logger;
    private array $errorMap;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->initializeErrorMap();
    }

    private function initializeErrorMap(): void
    {
        $this->errorMap = [
            'ValidationException' => 400,
            'UnauthorizedException' => 401,
            'ForbiddenException' => 403,
            'NotFoundException' => 404,
            'ConflictException' => 409,
            'UnprocessableEntityException' => 422,
            'TooManyRequestsException' => 429,
            'InternalServerErrorException' => 500,
            'ServiceUnavailableException' => 503
        ];
    }

    public function handleException(Throwable $exception): array
    {
        $errorCode = $this->getHttpStatusCode($exception);
        $errorMessage = $this->getErrorMessage($exception);
        $errorDetails = $this->getErrorDetails($exception);

        // Log the error
        $this->logger->error('Exception occurred', [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'http_code' => $errorCode
        ]);

        return [
            'error' => true,
            'code' => $errorCode,
            'message' => $errorMessage,
            'details' => $errorDetails,
            'timestamp' => date('c')
        ];
    }

    private function getHttpStatusCode(Throwable $exception): int
    {
        $className = (new \ReflectionClass($exception))->getShortName();
        
        return $this->errorMap[$className] ?? 500;
    }

    private function getErrorMessage(Throwable $exception): string
    {
        // Don't expose internal error details in production
        if ($this->isProductionEnvironment()) {
            return $this->getGenericErrorMessage($exception);
        }

        return $exception->getMessage();
    }

    private function getGenericErrorMessage(Throwable $exception): string
    {
        $statusCode = $this->getHttpStatusCode($exception);

        $messages = [
            400 => 'Bad Request - Invalid input provided',
            401 => 'Unauthorized - Authentication required',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - Resource not found',
            409 => 'Conflict - Resource already exists',
            422 => 'Unprocessable Entity - Validation failed',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Internal Server Error - Something went wrong',
            503 => 'Service Unavailable - Service temporarily unavailable'
        ];

        return $messages[$statusCode] ?? 'An error occurred';
    }

    private function getErrorDetails(Throwable $exception): ?array
    {
        // Only include details in development environment
        if (!$this->isProductionEnvironment()) {
            return [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_slice($exception->getTrace(), 0, 5) // Limit trace depth
            ];
        }

        return null;
    }

    private function isProductionEnvironment(): bool
    {
        return (getenv('APP_ENV') ?? 'production') === 'production';
    }

    public function handleValidationErrors(array $errors): array
    {
        $this->logger->warning('Validation errors occurred', [
            'errors' => $errors,
            'timestamp' => date('c')
        ]);

        return [
            'error' => true,
            'code' => 422,
            'message' => 'Validation failed',
            'validation_errors' => $errors,
            'timestamp' => date('c')
        ];
    }

    public function handleDatabaseError(Throwable $exception): array
    {
        $this->logger->error('Database error occurred', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);

        return [
            'error' => true,
            'code' => 500,
            'message' => $this->isProductionEnvironment() 
                ? 'Database operation failed' 
                : $exception->getMessage(),
            'timestamp' => date('c')
        ];
    }

    public function handleApiError(string $service, int $statusCode, string $message): array
    {
        $this->logger->error('External API error', [
            'service' => $service,
            'status_code' => $statusCode,
            'message' => $message
        ]);

        return [
            'error' => true,
            'code' => $statusCode >= 500 ? 502 : $statusCode,
            'message' => "External service error: {$service}",
            'timestamp' => date('c')
        ];
    }
}