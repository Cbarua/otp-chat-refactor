<?php
// src/Service/CsrfService.php

namespace App\Service;

class CsrfService
{
    private SessionService $session;
    private const SESSION_KEY = 'csrf_token';

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    /**
     * Generates a new CSRF token if one doesn't exist, or returns the existing one.
     */
    public function getToken(): string
    {
        if (!$this->session->has(self::SESSION_KEY)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $this->session->get(self::SESSION_KEY);
    }

    /**
     * Validates the provided token against the stored token.
     */
    public function validate(?string $token): bool
    {
        if (empty($token) || !$this->session->has(self::SESSION_KEY)) {
            return false;
        }

        return hash_equals($this->session->get(self::SESSION_KEY), $token);
    }

    /**
     * Regenerates the token. Useful after login/logout or sensitive actions.
     */
    public function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);
        return $token;
    }
}
