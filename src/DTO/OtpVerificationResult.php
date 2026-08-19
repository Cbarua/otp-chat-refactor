<?php
// src/DTO/OtpVerificationResult.php
declare(strict_types=1);

namespace App\DTO;

use JsonSerializable;

/**
 * Immutable DTO representing the outcome of an OTP verification attempt.
 */
class OtpVerificationResult implements JsonSerializable
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message,
        public readonly string $redirectUrl,
        public readonly bool $showSmsLink = false,
        public readonly ?string $ajaxRedirect = null
    ) {
    }

    /**
     * Creates a successful verification result.
     */
    public static function success(string $redirectUrl = 'thanks'): self
    {
        return new self(
            status: 'success',
            message: null,
            redirectUrl: $redirectUrl,
            showSmsLink: false,
            ajaxRedirect: $redirectUrl
        );
    }

    /**
     * Creates an error verification result.
     */
    public static function error(
        string $message,
        string $redirectUrl = 'otp',
        bool $showSmsLink = false,
        ?string $ajaxRedirect = null
    ): self {
        return new self(
            status: 'error',
            message: $message,
            redirectUrl: $redirectUrl,
            showSmsLink: $showSmsLink,
            ajaxRedirect: $ajaxRedirect
        );
    }

    /**
     * Checks whether the verification was successful.
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
        $data = ['status' => $this->status];

        if ($this->message !== null) {
            $data['message'] = $this->message;
        }

        if ($this->showSmsLink) {
            $data['showSmsLink'] = true;
        }

        if ($this->ajaxRedirect !== null) {
            $data['redirect'] = $this->ajaxRedirect;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
