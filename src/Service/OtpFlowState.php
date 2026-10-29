<?php
// src/Service/OtpFlowState.php
declare(strict_types=1);

namespace App\Service;

use App\DTO\OtpVerificationToken;
use App\DTO\PhoneNumber;
use App\Enum\SessionKey;

/**
 * The OTP flow's state between requests: the phone, the OTP token, SMS fallback, the invalid OTP
 * count and the lead and reg ids. It keeps the session keys and stored values the flow always had,
 * so sessions in flight survive a deploy. SMS fallback entered at the request step stores the
 * literal true as the token; only this class sees it.
 */
class OtpFlowState
{
    public function __construct(private SessionService $session)
    {
    }

    /**
     * The number submitted last, or null before the first valid submission.
     */
    public function phone(): ?PhoneNumber
    {
        $phone = $this->session->get(SessionKey::PHONE_DATA);
        return $phone instanceof PhoneNumber ? $phone : null;
    }

    /**
     * The OTP token being verified, or null when there is none (also in SMS fallback entered at the request step).
     */
    public function token(): ?OtpVerificationToken
    {
        $token = $this->session->get(SessionKey::OTP_TOKEN);
        return $token instanceof OtpVerificationToken ? $token : null;
    }

    /**
     * Whether the visitor has reached the verification step, with a token or in SMS fallback.
     */
    public function inVerificationStep(): bool
    {
        return $this->session->has(SessionKey::OTP_TOKEN);
    }

    /**
     * Whether /otp shows the SMS link instead of the OTP input.
     */
    public function inSmsFallback(): bool
    {
        return (bool) $this->session->get(SessionKey::SHOW_SMS_LINK, false);
    }

    /**
     * The reg id of a registration the thank-you page has not reported yet.
     */
    public function regId(): ?string
    {
        return $this->session->get(SessionKey::REG_ID);
    }

    /**
     * Returns the pending Lead event id and clears it, so /otp fires the Lead once.
     */
    public function takeLeadId(): ?string
    {
        $leadId = $this->session->get(SessionKey::LEAD_ID);
        $this->session->unset(SessionKey::LEAD_ID);
        return $leadId;
    }

    /**
     * Moves the visitor to the verification step with a new lead id. A null token means SMS
     * fallback at the request step.
     */
    public function startVerificationStep(PhoneNumber $phone, ?OtpVerificationToken $token): void
    {
        $this->session->set(SessionKey::LEAD_ID, $this->generateRandomId('lead-'));
        $this->session->set(SessionKey::PHONE_DATA, $phone);
        if ($token === null) {
            $this->session->set(SessionKey::SHOW_SMS_LINK, true);
        }
        $this->session->set(SessionKey::OTP_TOKEN, $token ?? true);
    }

    /**
     * Keeps the number for the phone form without moving to the verification step.
     */
    public function rememberPhone(PhoneNumber $phone): void
    {
        $this->session->set(SessionKey::PHONE_DATA, $phone);
    }

    /**
     * Counts one more invalid OTP and returns the new count.
     */
    public function countInvalidOtp(): int
    {
        $count = (int) $this->session->get(SessionKey::INVALID_OTP_COUNT, 0) + 1;
        $this->session->set(SessionKey::INVALID_OTP_COUNT, $count);
        return $count;
    }

    /**
     * Turns SMS fallback on or off; the token stays.
     */
    public function setSmsFallback(bool $on): void
    {
        $this->session->set(SessionKey::SHOW_SMS_LINK, $on);
    }

    /**
     * Stores the token from a token renewal or an app fallback and starts its attempts afresh.
     */
    public function replaceToken(OtpVerificationToken $token): void
    {
        $this->session->set(SessionKey::OTP_TOKEN, $token);
        $this->resetAttempts();
    }

    /**
     * Records a registration under a new reg id.
     */
    public function register(): void
    {
        $this->session->set(SessionKey::REG_ID, $this->generateRandomId('reg-'));
        $this->resetAttempts();
    }

    /**
     * Clears the invalid OTP count and leaves SMS fallback; the token stays.
     */
    public function resetAttempts(): void
    {
        $this->session->unset(SessionKey::INVALID_OTP_COUNT);
        $this->session->unset(SessionKey::SHOW_SMS_LINK);
    }

    /**
     * Ends the flow once the thank-you page has reported the registration, so a reload does not
     * report it again; the phone stays.
     */
    public function finish(): void
    {
        $this->session->unset(SessionKey::REG_ID);
        $this->session->unset(SessionKey::OTP_TOKEN);
    }

    /**
     * Generates a random ID with a prefix.
     */
    private function generateRandomId(string $prefix): string
    {
        try {
            return $prefix . bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return $prefix . uniqid();
        }
    }
}
