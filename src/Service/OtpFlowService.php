<?php
// src/Service/OtpFlowService.php
declare(strict_types=1);

namespace App\Service;

use App\DTO\OtpFlowResult;
use App\DTO\PhoneNumber;
use App\Enum\OtpRequestOutcome;
use App\Enum\OtpVerifyOutcome;
use App\Enum\SessionKey;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the complete OTP flow: the request step (rate limiting, phone validation, token reuse,
 * the OTP request with app fallback, SMS fallback) and the verification step (rate limiting,
 * API verification, attempt counting, SMS fallback triggering, token renewal and app fallback).
 *
 * Both steps return an OtpFlowResult and leave the flash message to the controller's respond().
 */
class OtpFlowService
{
    // Error Messages
    public const ALREADY_REGISTERED = 'You are already registered!';
    public const ERROR_INVALID_PHONE = 'Invalid phone number. Example: 0771234567';
    public const ERROR_MAX_REQUESTS = 'Maximum number of OTP requests reached for :number. Please try again in :minutes minutes';
    public const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';
    public const ERROR_CSRF = 'Security check failed. Please try again.';
    public const ERROR_REGISTRATION_FAILED = 'Registration failed. Please try again.';
    public const ERROR_OTP_INVALID_LENGTH = 'Invalid OTP. Must be 6 digits.';
    public const ERROR_OTP_INVALID = 'Invalid OTP. Please enter the correct OTP.';
    public const ERROR_OTP_NEW = 'Please try again with the new OTP sent to your phone.';
    public const ERROR_OTP_EXPIRED = 'Your OTP expired. A new OTP has been sent to your phone.';
    public const ERROR_GENERIC = 'An error occurred. Please try again later.';

    public function __construct(
        private array $config,
        private array $carrierConfig,
        private OtpApiService $otpService,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        private SessionService $session,
        private RateLimiterService $rateLimiter,
        private OtpFlowState $otpFlowState
    ) {
    }

    /**
     * Processes a phone number submission: rate limits, validates the number, reuses a fresh
     * OTP token or requests a new one, and stores the state for the OTP step in the session.
     */
    public function requestOtp(Request $request, string $rawPhone): OtpFlowResult
    {
        $userInfo = $this->analyticsTracker->getUserInfo($request);

        // 1. Rate Limiting Check
        // Limit: 5 attempts per IP per minute
        $rateLimitKey = 'phone_submission:' . $userInfo['ip'];
        if (!$this->rateLimiter->check($rateLimitKey, 5, 60)) {
            $this->logger->warning('Rate limit exceeded for phone submission.', ['rate_limit_key' => $rateLimitKey]);
            return OtpFlowResult::error(self::ERROR_RATE_LIMIT);
        }
        $this->rateLimiter->increment($rateLimitKey);

        // 2. Validate phone number
        $phoneData = Validator::normalizePhone($rawPhone, $this->carrierConfig, 'LK', $this->logger);
        if ($phoneData === null) {
            $this->logger->warning('Invalid phone number submitted', ['raw_phone' => $rawPhone]);
            return OtpFlowResult::error(self::ERROR_INVALID_PHONE);
        }

        // Check if number is blocked due to Max OTP requests (60 minute block)
        $remainingSeconds = $this->rateLimiter->getRemainingSeconds('otp_max_requests:' . $phoneData['capi_format']);
        if ($remainingSeconds > 0) {
            $remainingMinutes = max(1, (int) ceil($remainingSeconds / 60));
            $errorMessage = str_replace([':number', ':minutes'], [$rawPhone, $remainingMinutes], self::ERROR_MAX_REQUESTS);
            $this->logger->warning('Phone number blocked due to max OTP requests limit', [
                'phone' => $phoneData['capi_format'],
                'remaining_seconds' => $remainingSeconds,
                'remaining_minutes' => $remainingMinutes
            ]);
            return OtpFlowResult::error($errorMessage);
        }

        // 3. Log visit with phone, store FB cookies
        $this->analyticsTracker->logVisitWithPhone($request, $phoneData);

        // 4. Request OTP from the API. The service handles platform-specific endpoints.
        // Check if we have a valid recent OTP for this number to avoid redundant calls
        $existingToken = $this->otpFlowState->token();

        // Check if phone matches and token is valid (less than 5 minutes old)
        $isSameNumber = $this->otpFlowState->phone()?->capiFormat === $phoneData->capiFormat;
        $isRecent = false;
        if ($existingToken !== null) {
            $tokenCreatedAt = $existingToken->createdAt;
            $tokenAge = time() - $tokenCreatedAt;
            $isRecent = $tokenAge < 300;
        }

        if ($isSameNumber && $isRecent) {
            $this->logger->notice('Reusing existing valid OTP token', [
                'phone' => $phoneData['capi_format'],
                'created_at' => $this->timestampToDateString($tokenCreatedAt),
                'now' => $this->timestampToDateString(time()),
                'age' => $tokenAge
            ]);
            // Skip API call and reuse existing flow
            return OtpFlowResult::success('otp');
        }

        $metaData = array_merge(['client' => 'WEBAPP', 'appCode' => $request->getUri()], $userInfo);
        $response = $this->otpService->getOtp($phoneData['platform'], $phoneData['telco_format'], $metaData);

        // 5. Handle the final API response
        return $this->handleOtpApiResponse($rawPhone, $response, $phoneData);
    }

    /**
     * Handles the final API response after attempting to get an OTP.
     */
    private function handleOtpApiResponse(string $rawPhone, array $response, PhoneNumber $phoneData): OtpFlowResult
    {
        $outcome = $response['outcome'];

        if ($outcome === OtpRequestOutcome::SENT) {
            // SUCCESS: OTP was requested.
            $this->otpFlowState->startVerificationStep($phoneData, $response['verificationToken']);
            return OtpFlowResult::success('otp');
        }

        // FAILURE: No URL succeeded.
        if ($outcome === OtpRequestOutcome::ALREADY_REGISTERED) {
            $failedAttempts = $response['failedAttempts'] ?? [];
            $alreadyRegisteredUrls = $response['alreadyRegisteredUrls'] ?? [];

            // Scope is "all" if ALL failed attempts were due to "already registered"
            $scope = \count($alreadyRegisteredUrls) === \count($failedAttempts) ? 'all' : 'some';

            $this->logger->notice("User already registered on $scope of the services for this platform.", [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
                'already_registered_apps' => $this->getAppNamesFromUrls($alreadyRegisteredUrls),
                'final_app' => $this->getAppNamesFromUrls([$response['finalUrl'] ?? ''])[0] ?? '',
            ]);

            $this->otpFlowState->rememberPhone($phoneData);
            return OtpFlowResult::alreadyRegistered(self::ALREADY_REGISTERED);
        }

        if ($outcome === OtpRequestOutcome::TEMPORARY_FAILURE) {
            $smsNumber = $this->config['sms']['number'] ?? null;
            $smsKeyword = $this->config['sms']['keyword'] ?? null;

            if (!($smsNumber && $smsKeyword)) {
                $this->logger->error('Temporary system error encountered', [
                    'platform' => $phoneData['platform'],
                    'final_app' => $this->getAppNamesFromUrls([$response['finalUrl'] ?? ''])[0] ?? '',
                ]);
                return OtpFlowResult::error(self::ERROR_GENERIC);
            }

            $this->otpFlowState->startVerificationStep($phoneData, null);
            $this->logger->notice('Temporary system error encountered, showing SMS fallback link.', [
                'platform' => $phoneData['platform'],
                'final_app' => $this->getAppNamesFromUrls([$response['finalUrl'] ?? ''])[0] ?? '',
            ]);
            return OtpFlowResult::smsFallback('Temporary system error');
        }

        if ($outcome === OtpRequestOutcome::MAX_REQUESTS) {
            $this->rateLimiter->block('otp_max_requests:' . $phoneData['capi_format'], 3600);

            $smsNumber = $this->config['sms']['number'] ?? null;
            $smsKeyword = $this->config['sms']['keyword'] ?? null;

            if ($smsNumber && $smsKeyword) {
                $this->otpFlowState->startVerificationStep($phoneData, null);
                $this->logger->notice('Maximum OTP requests reached, showing SMS fallback link.', [
                    'platform' => $phoneData['platform'],
                    'phone' => $phoneData['capi_format'],
                ]);
                return OtpFlowResult::smsFallback('Maximum number of OTP requests reached');
            }

            $errorMessage = str_replace([':number', ':minutes'], [$rawPhone, 60], self::ERROR_MAX_REQUESTS);
            $this->logger->notice('Maximum OTP requests reached without SMS fallback configured.', [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
            ]);
            return OtpFlowResult::error($errorMessage);
        }

        $this->logger->error('All OTP request attempts failed for the user.', [
            'platform' => $phoneData['platform'],
            'phone' => $phoneData['capi_format'],
            ...$response
        ]);
        return OtpFlowResult::error(self::ERROR_GENERIC);
    }

    /**
     * Processes an OTP submission for the given request and raw OTP string.
     */
    public function processVerification(Request $request, string $rawOtp): OtpFlowResult
    {
        $token = $this->otpFlowState->token();
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
            $this->logger->warning(self::ERROR_OTP_INVALID_LENGTH, ['otp' => $rawOtp]);
            return OtpFlowResult::error(self::ERROR_OTP_INVALID_LENGTH);
        }

        // Call OTP verification API
        $response = $this->otpService->verifyOtp($token, $rawOtp);
        $this->logger->info('OTP verification attempted', [
            'token' => $token,
            'otp' => $rawOtp,
            'outcome' => $response['outcome'],
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
    private function handleVerificationResponse(Request $request, array $response, array|\ArrayAccess $token): OtpFlowResult
    {
        $outcome = $response['outcome'];

        if ($outcome === OtpVerifyOutcome::VERIFIED) {
            return $this->handleSuccessfulVerification();
        }

        if ($outcome === OtpVerifyOutcome::NOT_SUBSCRIBED) {
            $this->logger->critical('Registration failed. Unexpected Subscription Status', [
                'platform' => $token['platform'] ?? $this->otpFlowState->phone()?->platform,
                'response' => $response,
            ]);
            return OtpFlowResult::error(self::ERROR_REGISTRATION_FAILED);
        }

        // Handle expired OTP specifically
        if ($outcome === OtpVerifyOutcome::EXPIRED) {
            return $this->handleExpiredToken($request, $token);
        }

        $appName = $this->getAppNamesFromUrls([$token['usedApiUrl'] ?? ''])[0] ?? '';

        // The app stopped accepting attempts for this OTP: get a new one from another app.
        if ($outcome === OtpVerifyOutcome::ATTEMPTS_EXHAUSTED) {
            $this->logger->notice('OTP attempts exhausted, trying another app.', ['app' => $appName, 'response' => $response]);
            return $this->handleFailedVerification($request, $token);
        }

        // If SMS config is set, show sms link
        $smsNumber = $this->config['sms']['number'] ?? null;
        $smsKeyword = $this->config['sms']['keyword'] ?? null;

        if ($smsNumber && $smsKeyword) {
            if ($outcome === OtpVerifyOutcome::INVALID) {
                $count = $this->otpFlowState->countInvalidOtp();
                $context = ['invalid_otp_count' => $count, 'app' => $appName, 'response' => $response];

                if ($count >= 3) {
                    $this->otpFlowState->setSmsFallback(true);
                    $this->logger->notice('3 invalid OTP attempts, showing SMS fallback link.', $context);
                    return OtpFlowResult::smsFallback(self::ERROR_OTP_INVALID);
                }

                $this->otpFlowState->setSmsFallback(false);
                $this->logger->notice('Invalid OTP attempt.', $context);
                return OtpFlowResult::error(self::ERROR_OTP_INVALID);
            }

            if ($outcome === OtpVerifyOutcome::NOT_FOUND) {
                $this->otpFlowState->setSmsFallback(true);
                $this->logger->notice('OTP not found, showing SMS fallback link.', [
                    'app' => $appName,
                    'response' => $response,
                ]);
                return OtpFlowResult::smsFallback('OTP not found.');
            }
        }

        // For invalid otp without SMS config
        if ($outcome === OtpVerifyOutcome::INVALID) {
            $this->logger->notice('Invalid OTP attempt.', ['app' => $appName, 'response' => $response]);
            return OtpFlowResult::error(self::ERROR_OTP_INVALID);
        }

        if ($outcome === OtpVerifyOutcome::NOT_FOUND) {
            $this->logger->notice('OTP not found.', ['app' => $appName, 'response' => $response]);
            // Get a new otp for the same url or fallback urls.
            return $this->handleExpiredToken($request, $token);
        }

        $this->logger->critical('OTP verification failed with an unexpected status.', ['app' => $appName, 'response' => $response]);
        return $this->handleFailedVerification($request, $token);
    }

    /**
     * Registers the visitor once the platform has accepted the OTP and reports a subscription.
     */
    private function handleSuccessfulVerification(): OtpFlowResult
    {
        $this->otpFlowState->register();
        return OtpFlowResult::success('thanks');
    }

    /**
     * Handles a failed verification by attempting to get a new OTP from a fallback URL.
     */
    private function handleFailedVerification(Request $request, array|\ArrayAccess $token, bool $isRateLimit = false): OtpFlowResult
    {
        $phoneData = $this->otpFlowState->phone();
        $subscriberId = $phoneData?->telcoFormat;
        $platform = $token['platform'] ?? $phoneData?->platform;
        $failedUrl = $token['usedApiUrl'] ?? null;

        if (empty($subscriberId) || empty($platform) || empty($failedUrl)) {
            $this->logger->critical('No fallback possible due to missing data.', [
                'subscriber_id' => $subscriberId,
                'platform' => $platform,
                'failed_url' => $failedUrl
            ]);
            return OtpFlowResult::error(self::ERROR_GENERIC, './');
        }

        // Retrieve previously failed URLs from the token and add the current one
        $previouslyFailedUrls = $token['failedUrls'] ?? [];
        $allFailedUrls = array_unique(array_merge($previouslyFailedUrls, [$failedUrl]));

        $response = $this->attemptFallback($platform, (string) $subscriberId, $request, $allFailedUrls);

        if ($response['outcome'] === OtpRequestOutcome::SENT) {
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
    private function handleFallbackSuccess(array $response, array $allFailedUrls): OtpFlowResult
    {
        $newToken = $response['verificationToken']->withFailedUrls($allFailedUrls);

        $this->logger->info(
            'Successfully received new OTP from a fallback URL.',
            ['app' => $this->getAppNamesFromUrls([$newToken['usedApiUrl'] ?? ''])[0] ?? '']
        );

        $visitorId = (string) $this->session->get(SessionKey::VISITOR_ID, 'unknown');
        $this->rateLimiter->clear('otp_verification:' . $visitorId);

        $this->otpFlowState->replaceToken($newToken);

        return OtpFlowResult::error(self::ERROR_OTP_NEW);
    }

    /**
     * Handles a failed OTP fallback response after all attempts have been exhausted.
     */
    private function handleFallbackFailure(bool $isRateLimit): OtpFlowResult
    {
        $errorMessage = $isRateLimit ? self::ERROR_RATE_LIMIT : self::ERROR_GENERIC;

        $this->logger->error('All fallback OTP requests failed after a verification error.', [
            'error' => $errorMessage
        ]);

        return OtpFlowResult::error($errorMessage);
    }

    /**
     * Handles an expired OTP token by attempting to retrieve a new one.
     */
    private function handleExpiredToken(Request $request, array|\ArrayAccess $token): OtpFlowResult
    {
        $usedUrl = $token['usedApiUrl'] ?? null;
        $refNo = $token['referenceNo'] ?? null;
        $createdAt = (int) ($token['createdAt'] ?? time());
        $platform = $token['platform'] ?? null;
        $phoneData = $this->otpFlowState->phone();
        $subscriberId = $phoneData?->telcoFormat;
        $platform ??= $phoneData?->platform;
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
            return OtpFlowResult::error(self::ERROR_GENERIC, './');
        }

        $response = $this->attemptFallback((string) $platform, (string) $subscriberId, $request, $failedUrls);

        if ($response['outcome'] === OtpRequestOutcome::SENT) {
            $newToken = $response['verificationToken'];
            $newUrl = $newToken['usedApiUrl'] ?? null;
            $context = [
                'phone' => $phoneData->capiFormat,
                'app' => $this->getAppNamesFromUrls([$newUrl])[0] ?? '',
                'reference_no' => $newToken['referenceNo'] ?? null,
                'created_at' => $this->timestampToDateString((int) ($newToken['createdAt'] ?? 0))
            ];
            if ($newUrl === $usedUrl) {
                $this->logger->notice('Successfully renewed expired OTP.', $context);
            } else {
                $this->logger->notice('Successfully received new OTP from a fallback URL.', $context);
            }

            $this->otpFlowState->replaceToken($newToken);

            return OtpFlowResult::error(self::ERROR_OTP_EXPIRED);
        }

        $this->logger->error('Failed to renew expired OTP and fallback URLs.', [
            'phone' => $phoneData->capiFormat,
            'used_app' => $usedApp,
            'reference_no' => $refNo,
            'created_at' => $this->timestampToDateString($createdAt)
        ]);
        return OtpFlowResult::error(self::ERROR_GENERIC, './');
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
     * Converts a timestamp to a formatted date string.
     */
    private function timestampToDateString(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
