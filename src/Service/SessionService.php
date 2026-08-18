<?php
// src/Service/SessionService.php

namespace App\Service;

use App\Enum\SessionKey;

/**
 * A wrapper around PHP's $_SESSION superglobal supporting both string keys and SessionKey enums.
 */
class SessionService
{
    /**
     * Resolves a SessionKey enum or string to its string key.
     */
    private function resolveKey(SessionKey|string $key): string
    {
        return $key instanceof SessionKey ? $key->value : $key;
    }

    /**
     * Get a value from the session.
     *
     * @param SessionKey|string $key The key of the item to retrieve.
     * @param mixed $default The default value to return if the key is not found.
     * @return mixed
     */
    public function get(SessionKey|string $key, mixed $default = null): mixed
    {
        $k = $this->resolveKey($key);
        return $_SESSION[$k] ?? $default;
    }

    /**
     * Set a value in the session.
     *
     * @param SessionKey|string $key The key of the item to set.
     * @param mixed $value The value to set.
     */
    public function set(SessionKey|string $key, mixed $value): void
    {
        $k = $this->resolveKey($key);
        $_SESSION[$k] = $value;
    }

    /**
     * Check if a key exists in the session.
     *
     * @param SessionKey|string $key The key to check.
     * @return bool
     */
    public function has(SessionKey|string $key): bool
    {
        $k = $this->resolveKey($key);
        return isset($_SESSION[$k]);
    }

    /**
     * Remove a value from the session.
     *
     * @param SessionKey|string $key The key of the item to remove.
     */
    public function unset(SessionKey|string $key): void
    {
        $k = $this->resolveKey($key);
        unset($_SESSION[$k]);
    }

    /**
     * Alias for unset() to support standard remove method convention.
     *
     * @param SessionKey|string $key The key of the item to remove.
     */
    public function remove(SessionKey|string $key): void
    {
        $this->unset($key);
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

