<?php
declare(strict_types=1);

namespace App\Service;

interface UserLoggerInterface
{
    /**
     * Logs a user visit or updates an existing record.
     *
     * @param string $visitorId   The visitor's unique ID (from cookie/session)
     * @param string $ip
     * @param string $userAgent
     * @param string|null $phoneNumber
     */
    public function logVisit(string $visitorId, string $ip, string $userAgent, ?string $phoneNumber = null): void;
}