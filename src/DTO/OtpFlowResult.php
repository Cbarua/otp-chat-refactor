<?php
// src/DTO/OtpFlowResult.php
declare(strict_types=1);

namespace App\DTO;

use JsonSerializable;

/**
 * Immutable DTO representing the outcome of one OTP flow step (request or verification).
 * BaseController::respond() turns it into JSON (XHR) or a flash message and a redirect (HTML).
 */
class OtpFlowResult implements JsonSerializable
{
    /**
     * @param ?string $next  The page the visitor moves to; null keeps them on the page they submitted from.
     * @param bool    $flash Whether the HTML flow shows $message on the next page.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $message,
        public readonly ?string $next,
        public readonly bool $showSmsLink = false,
        public readonly bool $flash = false
    ) {
    }

    /**
     * Creates a successful flow result.
     */
    public static function success(string $next): self
    {
        return new self(status: 'success', message: null, next: $next);
    }

    /**
     * Creates an error flow result.
     */
    public static function error(
        string $message,
        ?string $next = null,
        bool $showSmsLink = false,
        bool $flash = true
    ): self {
        return new self(
            status: 'error',
            message: $message,
            next: $next,
            showSmsLink: $showSmsLink,
            flash: $flash
        );
    }

    /**
     * Creates the result for a number the platform already has registered.
     *
     * @param string $message Shown in the phone form's green box; flashed under already_registered for HTML.
     * @return self
     */
    public static function alreadyRegistered(string $message): self
    {
        return new self(status: 'already_registered', message: $message, next: null, flash: true);
    }

    /**
     * Checks whether the step succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Converts the result into an array suitable for JSON responses.
     */
    public function toArray(): array
    {
        // phone-form.js still matches this text.
        if ($this->status === 'already_registered') {
            return ['status' => 'error', 'message' => 'user already registered'];
        }

        $data = ['status' => $this->status];

        if ($this->message !== null) {
            $data['message'] = $this->message;
        }

        if ($this->showSmsLink) {
            $data['showSmsLink'] = true;
        }

        if ($this->next !== null) {
            $data['redirect'] = $this->next;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
