<?php
// src/Service/OtpFlowService.php
declare(strict_types=1);

namespace App\Service;

use App\DTO\OtpVerificationResult;
use App\DTO\OtpVerificationToken;
use App\Enum\ApiStatus;
use App\Enum\SessionKey;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the complete OTP verification workflow including rate limiting,
 * API verification, attempt counting, SMS fallback triggering, and automatic endpoint renewal/fallback.
 */
class OtpFlowService
{
    // API Statuses
    public const OTP_SUCCESS = ApiStatus::SUCCESS->value;
    public const OTP_INVALID = 'Invalid OTP';
    public const OTP_NOT_FOUND = 'Could not find OTP';
    public const OTP_STATUS_EXPIRED = 'OTP request has being expired';
    public const SUB_STATUS_PENDING = 'INITIAL CHARGING PENDING';
    public const SUB_STATUS_REGISTERED = 'REGISTERED';

    // Error Messages
    public const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';
    public const ERROR_CSRF = 'Security check failed. Please try again.';
    public const ERROR_REGISTRATION_FAILED = 'Registration failed. Please try again.';
    public const ERROR_OTP_INVALID_LENGTH = 'Invalid OTP. Must be 6 digits.';
    public const ERROR_OTP_INVALID = 'Invalid OTP. Please enter the correct OTP.';
    public const ERROR_OTP_NEW = 'Please try again with the new OTP sent to your phone.';
    public const ERROR_OTP_EXPIRED = 'Your OTP expired. A new OTP has been sent to your phone.';
    public const ERROR_GENERIC = 'An error occurred. Please try again later.';

    // Platforms
    public const PLATFORM_MSPACE = 'mspace';

    public function __construct(
        private array $config,
        private OtpApiInterface $otpService,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        private SessionService $session,
        private RateLimiterService $rateLimiter
    ) {
    }

    /**
     * Processes an OTP submission for the given request and raw OTP string.
     */
    public function processVerification(Request $request, string $rawOtp): OtpVerificationResult
    {
        $token = $this->session->get(SessionKey::OTP_TOKEN);
        $visitorId = $this->session->get(SessionKey::VISITOR_ID, 'unknown');
        $isAjax = $request->isXmlHttpRequest();
        $context = ['raw_otp' => $rawOtp, 'ref_no' => $token['referenceNo'] ?? 'N/A'];

        $this->logger->info('OTP form submitted ' . ($isAjax ? 'via AJAX' : ''), $context);

        // Check verification rate limit
        if ($this->isRateLimitExceeded((string) $visitorId)) {
            return $this->handleFailedVerification($request, $token, true);
        }

        // Validate OTP format
        if (!Validator::validateOtp($rawOtp)) {
            $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_OTP_INVALID_LENGTH);
            $this->logger->warning(self::ERROR_OTP_INVALID_LENGTH, ['otp' => $rawOtp]);
            return OtpVerificationResult::error(self::ERROR_OTP_INVALID_LENGTH, 'otp');
        }

        // Call OTP verification API
        $response = $this->otpService->verifyOtp($token, $rawOtp);
        $this->logger->info('OTP verification attempted', [
            'token' => $token,
            'otp' => $rawOtp,
            'status' => $response['status'] ?? null,
            'response' => $response['originalResponse'] ?? null,
        ]);

        return $this->handleVerificationResponse($request, $response, $token);
    }

    /**
     * Checks if the visitor has exceeded the maximum number of OTP verification attempts.
     */
    private function isRateLimitExceeded(string $visitorId): bool
    {
        $rateLimitKey = 'otp_verification:' . $visitorId;
        $maxAttempts = 5;
        $window = 600;
        if ($this->rateLimiter->check($rateLimitKey, $maxAttempts, $window)) {
            $this->rateLimiter->increment($rateLimitKey);
            return false;
        }
        $this->logger->warning('Rate limit exceeded. ', [
            'visitor_id' => $visitorId,
            'max_attempts' => $maxAttempts,
            'window' => $window
        ]);
        return true;
    }

    /**
     * Processes the API response after an OTP verification attempt.
     */
    private function handleVerificationResponse(Request $request, array $response, array|\ArrayAccess $token): OtpVerificationResult
    {
        $responseStatus = $response['status'] ?? null;

        if ($responseStatus === self::OTP_SUCCESS) {
            $platform = $token['platform'] ?? ($this->session->get(SessionKey::PHONE_DATA)['platform'] ?? null);
            return $this->handleSuccessfulVerification($response, $platform);
        }

        // Handle expired OTP specifically
        if ($responseStatus === self::OTP_STATUS_EXPIRED) {
            return $this->handleExpiredToken($request, $token);
        }

        $appName = $this->getAppNamesFromUrls([$token['usedApiUrl'] ?? ''])[0] ?? '';

        // If SMS config is set, show sms link
        $smsNumber = $this->config['sms']['number'] ?? null;
        $smsKeyword = $this->config['sms']['keyword'] ?? null;

        if ($smsNumber && $smsKeyword) {
            if ($responseStatus === self::OTP_INVALID) {
                $count = (int) $this->session->get(SessionKey::INVALID_OTP_COUNT, 0) + 1;
                $this->session->set(SessionKey::INVALID_OTP_COUNT, $count);
                $context = ['invalid_otp_count' => $count, 'app' => $appName, 'response' => $response];

                if ($count >= 3) {
                    $this->session->set(SessionKey::SHOW_SMS_LINK, true);
                    $this->logger->notice('3 invalid OTP attempts, showing SMS fallback link.', $context);
                } else {
                    $this->session->set(SessionKey::SHOW_SMS_LINK, false);
                    $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_OTP_INVALID);
                    $this->logger->notice('Invalid OTP attempt.', $context);
                }

                return OtpVerificationResult::error(
                    message: (string) ($this->session->get(SessionKey::ERROR_MESSAGE) ?? self::ERROR_OTP_INVALID),
                    redirectUrl: 'otp',
                    showSmsLink: (bool) $this->session->get(SessionKey::SHOW_SMS_LINK, false)
                );
            }

            if ($responseStatus === self::OTP_NOT_FOUND) {
                $this->session->set(SessionKey::SHOW_SMS_LINK, true);
                $this->logger->notice('OTP not found, showing SMS fallback link.', [
                    'app' => $appName,
                    'response' => $response,
                ]);
                return OtpVerificationResult::error(
                    message: 'OTP not found.',
                    redirectUrl: 'otp',
                    showSmsLink: true
                );
            }
        }

        // For invalid otp without SMS config
        if ($responseStatus === self::OTP_INVALID) {
            $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_OTP_INVALID);
            $this->logger->notice('Invalid OTP attempt.', ['app' => $appName, 'response' => $response]);
            return OtpVerificationResult::error(self::ERROR_OTP_INVALID, 'otp', false);
        }

        if ($responseStatus === self::OTP_NOT_FOUND) {
            $this->logger->notice('OTP not found.', ['app' => $appName, 'response' => $response]);
            // Get a new otp for the same url or fallback urls.
            return $this->handleExpiredToken($request, $token);
        }

        $this->logger->critical('OTP verification failed with an unexpected status.', ['app' => $appName, 'response' => $response]);
        return $this->handleFailedVerification($request, $token);
    }

    /**
     * Handles the logic for a successful OTP verification.
     */
    private function handleSuccessfulVerification(array $response, ?string $platform): OtpVerificationResult
    {
        if (!$platform) {
            $this->logger->critical('Could not determine platform for successful verification.');
            $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_REGISTRATION_FAILED);
            return OtpVerificationResult::error(self::ERROR_REGISTRATION_FAILED, './', false, './');
        }

        $isSubscribed = \in_array(
            $response['subscriptionStatus'] ?? null,
            [self::SUB_STATUS_REGISTERED, self::SUB_STATUS_PENDING],
            true
        );

        // mspace platform has a pass because of legacy code
        if ($isSubscribed || $platform === self::PLATFORM_MSPACE) {
            $regId = $this->generateRandomId('reg-');
            $this->session->set(SessionKey::REG_ID, $regId);
            $this->session->unset(SessionKey::INVALID_OTP_COUNT);
            $this->session->unset(SessionKey::SHOW_SMS_LINK);
            return OtpVerificationResult::success('thanks');
        }

        $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_REGISTRATION_FAILED);
        $this->logger->critical('Registration failed. Unexpected Subscription Status', [
            'platform' => $platform,
            'response' => $response,
        ]);
        return OtpVerificationResult::error(self::ERROR_REGISTRATION_FAILED, 'otp', false);
    }

    /**
     * Handles a failed verification by attempting to get a new OTP from a fallback URL.
     */
    private function handleFailedVerification(Request $request, array|\ArrayAccess $token, bool $isRateLimit = false): OtpVerificationResult
    {
        $phoneData = $this->session->get(SessionKey::PHONE_DATA, []);
        $subscriberId = $phoneData['telco_format'] ?? null;
        $platform = $token['platform'] ?? $phoneData['platform'] ?? null;
        $failedUrl = $token['usedApiUrl'] ?? null;

        if (empty($subscriberId) || empty($platform) || empty($failedUrl)) {
            $this->logger->critical('No fallback possible due to missing data.', [
                'subscriber_id' => $subscriberId,
                'platform' => $platform,
                'failed_url' => $failedUrl
            ]);
            $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_GENERIC);
            return OtpVerificationResult::error(self::ERROR_GENERIC, './', false, './');
        }

        // Retrieve previously failed URLs from the token and add the current one
        $previouslyFailedUrls = $token['failedUrls'] ?? [];
        $allFailedUrls = array_unique(array_merge($previouslyFailedUrls, [$failedUrl]));

        $response = $this->attemptFallback($platform, (string) $subscriberId, $request, $allFailedUrls);

        if (($response['status'] ?? null) === 'success') {
            return $this->handleFallbackSuccess($response, $allFailedUrls);
        }

        return $this->handleFallbackFailure($isRateLimit);
    }

    /**
     * Attempts to obtain a new OTP from an alternative API endpoint when the primary one fails.
     */
    private function attemptFallback(string $platform, string $subscriberId, Request $request, array $allFailedUrls): array
    {
        $userInfo = $this->analyticsTracker->getUserInfo($request);
        $metaData = array_merge(['client' => 'WEBAPP', 'appCode' => $request->getUri()], $userInfo);

        $this->logger->notice(
            'Attempting to get a new OTP from a fallback URL.',
            ['platform' => $platform, 'subscriber_id' => $subscriberId, 'failed_apps' => $this->getAppNamesFromUrls($allFailedUrls)]
        );

        return $this->otpService->getOtp($platform, $subscriberId, $metaData, $allFailedUrls);
    }

    /**
     * Handles a successful OTP fallback response.
     */
    private function handleFallbackSuccess(array $response, array $allFailedUrls): OtpVerificationResult
    {
        $newToken = $response['verificationToken'];
        if ($newToken instanceof OtpVerificationToken) {
            $newToken = $newToken->withFailedUrls($allFailedUrls);
        } else {
            $newToken['failedUrls'] = $allFailedUrls;
        }

        $this->logger->info(
            'Successfully received new OTP from a fallback URL.',
            ['app' => $this->getAppNamesFromUrls([$newToken['usedApiUrl'] ?? ''])[0] ?? '']
        );

        $visitorId = (string) $this->session->get(SessionKey::VISITOR_ID, 'unknown');
        $this->rateLimiter->clear('otp_verification:' . $visitorId);

        $this->session->set(SessionKey::OTP_TOKEN, $newToken);
        $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_OTP_NEW);

        $this->session->unset(SessionKey::INVALID_OTP_COUNT);
        $this->session->unset(SessionKey::SHOW_SMS_LINK);

        return OtpVerificationResult::error(self::ERROR_OTP_NEW, 'otp', false);
    }

    /**
     * Handles a failed OTP fallback response after all attempts have been exhausted.
     */
    private function handleFallbackFailure(bool $isRateLimit): OtpVerificationResult
    {
        $errorMessage = $isRateLimit ? self::ERROR_RATE_LIMIT : self::ERROR_GENERIC;
        $this->session->set(SessionKey::ERROR_MESSAGE, $errorMessage);

        $this->logger->error('All fallback OTP requests failed after a verification error.', [
            'error' => $errorMessage
        ]);

        return OtpVerificationResult::error($errorMessage, 'otp', false);
    }

    /**
     * Handles an expired OTP token by attempting to retrieve a new one.
     */
    private function handleExpiredToken(Request $request, array|\ArrayAccess $token): OtpVerificationResult
    {
        $usedUrl = $token['usedApiUrl'] ?? null;
        $refNo = $token['referenceNo'] ?? null;
        $createdAt = (int) ($token['createdAt'] ?? time());
        $platform = $token['platform'] ?? null;
        $phoneData = $this->session->get(SessionKey::PHONE_DATA, []);
        $subscriberId = $phoneData['telco_format'] ?? null;
        $platform ??= $phoneData['platform'] ?? null;
        $usedApp = $this->getAppNamesFromUrls([$usedUrl])[0] ?? '';

        $this->logger->notice('OTP reference expired during verification. Attempting to get a new one.', [
            'reference_no' => $refNo,
            'created_at' => $this->timestampToDateString($createdAt),
            'now' => $this->timestampToDateString(time()),
            'platform' => $platform,
            'used_app' => $usedApp,
        ]);

        $failedUrls = [];

        if (empty($subscriberId) || empty($platform)) {
            $this->logger->critical('Cannot auto-renew expired OTP due to missing data.', [
                'subscriber_id' => $subscriberId,
                'platform' => $platform
            ]);
            $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_GENERIC);
            return OtpVerificationResult::error(self::ERROR_GENERIC, './', false, './');
        }

        $response = $this->attemptFallback((string) $platform, (string) $subscriberId, $request, $failedUrls);

        if (($response['status'] ?? null) === self::OTP_SUCCESS) {
            $newToken = $response['verificationToken'];
            $newUrl = $newToken['usedApiUrl'] ?? null;
            $context = [
                'phone' => $phoneData['capi_format'] ?? null,
                'app' => $this->getAppNamesFromUrls([$newUrl])[0] ?? '',
                'reference_no' => $newToken['referenceNo'] ?? null,
                'created_at' => $this->timestampToDateString((int) ($newToken['createdAt'] ?? 0))
            ];
            if ($newUrl === $usedUrl) {
                $this->logger->notice('Successfully renewed expired OTP.', $context);
            } else {
                $this->logger->notice('Successfully received new OTP from a fallback URL.', $context);
            }

            $this->session->set(SessionKey::OTP_TOKEN, $newToken);
            $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_OTP_EXPIRED);

            $this->session->unset(SessionKey::INVALID_OTP_COUNT);
            $this->session->unset(SessionKey::SHOW_SMS_LINK);

            return OtpVerificationResult::error(self::ERROR_OTP_EXPIRED, 'otp', false);
        }

        $this->logger->error('Failed to renew expired OTP and fallback URLs.', [
            'phone' => $phoneData['capi_format'] ?? null,
            'used_app' => $usedApp,
            'reference_no' => $refNo,
            'created_at' => $this->timestampToDateString($createdAt)
        ]);
        $this->session->set(SessionKey::ERROR_MESSAGE, self::ERROR_GENERIC);
        return OtpVerificationResult::error(self::ERROR_GENERIC, './', false, './');
    }

    /**
     * Extracts application names from their URLs.
     */
    private function getAppNamesFromUrls(array $urls): array
    {
        $appNames = [];
        foreach ($urls as $url) {
            if (!$url) {
                continue;
            }
            $parts = explode('/', trim($url, '/'));
            $appNames[] = end($parts);
        }
        return $appNames;
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

    /**
     * Converts a timestamp to a formatted date string.
     */
    private function timestampToDateString(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
