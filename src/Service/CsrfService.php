<?php
// src/Service/CsrfService.php

namespace App\Service;

use App\Enum\SessionKey;

class CsrfService
{
    private SessionService $session;
    public const SESSION_KEY = 'csrf_token';

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    /**
     * Generates a new CSRF token if one doesn't exist, or returns the existing one.
     */
    public function getToken(): string
    {
        if (!$this->session->has(SessionKey::CSRF_TOKEN)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(SessionKey::CSRF_TOKEN, $token);
        }
        return $this->session->get(SessionKey::CSRF_TOKEN);
    }

    /**
     * Validates the provided token against the stored token.
     */
    public function validate(?string $token): bool
    {
        if (empty($token) || !$this->session->has(SessionKey::CSRF_TOKEN)) {
            return false;
        }

        return hash_equals((string) $this->session->get(SessionKey::CSRF_TOKEN), $token);
    }

    /**
     * Regenerates the token. Useful after login/logout or sensitive actions.
     */
    public function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(SessionKey::CSRF_TOKEN, $token);
        return $token;
    }
}
