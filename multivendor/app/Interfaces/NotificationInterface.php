<?php

namespace Antinna\MultiVendor\Interfaces;

/**
 * Interface for notification services
 */
interface NotificationInterface
{
    /**
     * Send notification via specific channel
     */
    public function send(string $recipient, string $message, array $data = []): bool;

    /**
     * Check if notification channel is available
     */
    public function isAvailable(): bool;

    /**
     * Get notification channel name
     */
    public function getChannelName(): string;
}