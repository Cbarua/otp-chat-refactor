<?php
// src/Service/SessionService.php

namespace App\Service;

/**
 * A simple wrapper around PHP's $_SESSION superglobal to allow for
 * easier testing and dependency injection.
 */
class SessionService
{
    /**
     * Get a value from the session.
     *
     * @param string $key The key of the item to retrieve.
     * @param mixed|null $default The default value to return if the key is not found.
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a value in the session.
     *
     * @param string $key The key of the item to set.
     * @param mixed $value The value to set.
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Check if a key exists in the session.
     *
     * @param string $key The key to check.
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a value from the session.
     *
     * @param string $key The key of the item to remove.
     */
    public function unset(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Get all session data.
     *
     * @return array
     */
    public function all(): array
    {
        return $_SESSION;
    }
}
