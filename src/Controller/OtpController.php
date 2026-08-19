<?php
// src/Controller/OtpController.php

namespace App\Controller;

use App\Service\OtpApiInterface;
use App\Service\SessionService;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\CsrfService;
use App\Service\RateLimiterService;
use App\Service\AnalyticsTrackerService;
use App\Enum\SessionKey;
use App\Enum\ApiStatus;

class OtpController extends BaseController
{
    // Session Keys
    public const SESSION_OTP_TOKEN = SessionKey::OTP_TOKEN->value;
    public const SESSION_PHONE_DATA = SessionKey::PHONE_DATA->value;
    public const SESSION_ERROR = SessionKey::ERROR_MESSAGE->value;
    public const SESSION_PAGE_VIEW_ID = SessionKey::PAGE_VIEW_ID_OTP->value;
    public const SESSION_REG_ID = SessionKey::REG_ID->value;
    public const SESSION_LEAD_ID = SessionKey::LEAD_ID->value;
    public const SESSION_INVALID_OTP_COUNT = SessionKey::INVALID_OTP_COUNT->value;
    public const SESSION_SHOW_SMS_LINK = SessionKey::SHOW_SMS_LINK->value;

    // API Statuses
    public const OTP_SUCCESS = ApiStatus::SUCCESS->value;
    public const OTP_INVALID = 'Invalid OTP';
    public const OTP_NOT_FOUND = 'Could not find OTP';
    public const OTP_STATUS_EXPIRED = 'OTP request has being expired';
    public const SUB_STATUS_PENDING = 'INITIAL CHARGING PENDING';
    public const SUB_STATUS_REGISTERED = 'REGISTERED';

    // Error Messages
    private const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';
    private const ERROR_CSRF = 'Security check failed. Please try again.';
    private const ERROR_REGISTRATION_FAILED = 'Registration failed. Please try again.';
    private const ERROR_OTP_INVALID_LENGTH = 'Invalid OTP. Must be 6 digits.';
    private const ERROR_OTP_INVALID = 'Invalid OTP. Please enter the correct OTP.';
    private const ERROR_OTP_NEW = 'Please try again with the new OTP sent to your phone.';
    private const ERROR_OTP_EXPIRED = 'Your OTP expired. A new OTP has been sent to your phone.';
    private const ERROR_GENERIC = 'An error occurred. Please try again later.';


    // Platforms
    public const PLATFORM_MSPACE = 'mspace';

    public function __construct(
        private array $config,
        private OtpApiInterface $otpService,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        private SessionService $session,
        private CsrfService $csrfService,
        private RateLimiterService $rateLimiter
    ) {
    }

    /**
     * Displays the OTP entry form.
     */
    public function showOtpForm(Request $request): Response
    {
        $userInfo = $this->analyticsTracker->getUserInfo($request);

        // Security Check: Ensure user has a token from the previous step.
        if (!$this->session->has(self::SESSION_OTP_TOKEN)) {
            $this->logger->error('OTP form accessed without a token', $userInfo);
            return $this->redirect('./');
        }

        $this->analyticsTracker->trackPageView(
            $request,
            '/otp',
            'pgview-otp-',
            null,
            false,
            true,
            self::SESSION_PAGE_VIEW_ID
        );

        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        if ($this->session->has(self::SESSION_LEAD_ID) && isset($phoneData['capi_format'])) {
            $leadId = $this->session->get(self::SESSION_LEAD_ID);
            $this->analyticsTracker->trackLead($request, $phoneData, $leadId);
        }

        $smsNumber = $this->config['sms']['number'] ?? null;
        $smsKeyword = $this->config['sms']['keyword'] ?? null;

        // It makes sure that the sms link is only shown if the sms number and keyword are set
        // Backwards compatibility
        $showSmsLink = ($smsNumber && $smsKeyword) ? $this->session->get(self::SESSION_SHOW_SMS_LINK, false) : false;

        if ($showSmsLink) {
            $this->logger->notice('SMS link shown', [
                'number' => $smsNumber,
                'keyword' => $smsKeyword,
            ]);
        }

        // Prepare data for the view
        $data = [
            'config' => $this->config,
            'title' => 'OTP Form',
            'leadEventId' => $this->session->get(self::SESSION_LEAD_ID),
            'pageViewEventId' => $this->session->get(self::SESSION_PAGE_VIEW_ID),
            'phoneCapi' => $phoneData['capi_format'] ?? null,
            'testEventCode' => $this->config['facebook']['test_event_code'] ?? null,
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'externalId' => $this->session->get('visitor_id'),
            'country' => 'lk',
            'errorMessage' => $this->session->get(self::SESSION_ERROR),
            'csrfToken' => $this->csrfService->getToken(),
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
            'showSmsLink' => $showSmsLink,
            'smsNumber' => $smsNumber,
            'smsKeyword' => $smsKeyword,
        ];

        $this->session->unset(self::SESSION_ERROR);
        // Unset the flag so it never fires again
        $this->session->unset(self::SESSION_LEAD_ID);

        // Render the view
        return $this->render('otp_form', $data);
    }

    /**
     * Handles the submission of the OTP.
     */
    public function handleOtpForm(Request $request): Response
    {
        $errorResponse = $this->validateOtpRequest($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $token = $this->session->get(self::SESSION_OTP_TOKEN);
        $visitorId = $this->session->get('visitor_id', 'unknown');

        // if rate limit exceeded, attempt to get otp from another api
        if ($this->isRateLimitExceeded($visitorId)) {
            return $this->handleFailedVerification($request, $token, true);
        }

        $response = $this->processOtp($request, $token);

        // If it is an AJAX request, clear the SESSION_ERROR to prevent it from leaking into reloads
        if ($request->isXmlHttpRequest()) {
            $this->session->unset(self::SESSION_ERROR);
        }

        return $response;
    }

    private function validateOtpRequest(Request $request): ?Response
    {
        $csrfToken = $request->request->get('csrf_token');
        if (!$this->csrfService->validate($csrfToken)) {
            $this->logger->warning('CSRF token validation failed on OTP form submission.', ['csrf_token' => $csrfToken]);
            if ($request->isXmlHttpRequest()) {
                // load the otp page for new csrf token
                return $this->json(['status' => 'error', 'message' => self::ERROR_CSRF, 'redirect' => 'otp']);
            }
            $this->session->set(self::SESSION_ERROR, self::ERROR_CSRF);
            return $this->redirect('otp');
        }

        if (!$this->session->has(self::SESSION_OTP_TOKEN)) {
            $this->logger->error('OTP submission without a token.');
            if ($request->isXmlHttpRequest()) {
                return $this->json(['status' => 'error', 'message' => 'Session expired. Please try again.', 'redirect' => './']);
            }
            return $this->redirect('./');
        }

        $token = $this->session->get(self::SESSION_OTP_TOKEN);
        if (!is_array($token) && !($token instanceof \ArrayAccess)) {
            $this->logger->error('Invalid OTP token structure in session.', ['token_type' => gettype($token)]);
            if ($request->isXmlHttpRequest()) {
                return $this->json(['status' => 'error', 'message' => 'Session expired. Please try again.', 'redirect' => './']);
            }
            return $this->redirect('./');
        }

        return null;
    }

    private function processOtp(Request $request, array|\ArrayAccess $token): Response
    {
        $rawOtp = $request->request->get('otp', '');
        $isAjax = $request->isXmlHttpRequest();
        $context = ['raw_otp' => $rawOtp, 'ref_no' => $token['referenceNo'] ?? 'N/A'];

        $this->logger->info('OTP form submitted ' . ($isAjax ? 'via AJAX' : ''), $context);

        if (!Validator::validateOtp($rawOtp)) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID_LENGTH);
            $this->logger->warning(self::ERROR_OTP_INVALID_LENGTH, ['otp' => $rawOtp]);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_OTP_INVALID_LENGTH]);
            }
            return $this->redirect('otp');
        }

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
     * Processes the API response after an OTP verification attempt.
     */
    private function handleVerificationResponse(Request $request, array $response, array|\ArrayAccess $token): Response
    {
        $isAjax = $request->isXmlHttpRequest();
        $responseStatus = $response['status'] ?? null;

        if ($responseStatus === self::OTP_SUCCESS) {
            $platform = $token['platform'] ?? ($this->session->get(self::SESSION_PHONE_DATA)['platform'] ?? null);
            return $this->handleSuccessfulVerification($request, $response, $platform);
        }

        // Handle expired OTP specifically
        if ($responseStatus === self::OTP_STATUS_EXPIRED) {
            return $this->handleExpiredToken($request, $token);
        }

        $appName = $this->getAppNamesFromUrls([$token['usedApiUrl'] ?? ''])[0];

        // If SMS config is set, show sms link
        // Backward compatibility
        $smsNumber = $this->config['sms']['number'] ?? null;
        $smsKeyword = $this->config['sms']['keyword'] ?? null;

        if ($smsNumber && $smsKeyword) {
            if ($responseStatus === self::OTP_INVALID) {
                // Increment invalid OTP count
                $count = $this->session->get(self::SESSION_INVALID_OTP_COUNT, 0) + 1;
                $this->session->set(self::SESSION_INVALID_OTP_COUNT, $count);
                $context = ['invalid_otp_count' => $count, 'app' => $appName, 'response' => $response];

                if ($count >= 3) {
                    $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
                    $this->logger->notice('3 invalid OTP attempts, showing SMS fallback link.', $context);
                } else {
                    $this->session->set(self::SESSION_SHOW_SMS_LINK, false);
                    $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID);
                    $this->logger->notice('Invalid OTP attempt.', $context);
                }
 
                if ($isAjax) {
                    // otp_form shows the error and reload the page to display sms link.
                    return $this->json([
                        'status' => 'error', 
                        'message' => $this->session->get(self::SESSION_ERROR), 
                        'showSmsLink' => $this->session->get(self::SESSION_SHOW_SMS_LINK)
                    ]);
                }

                return $this->redirect('otp');
            }

            if ($responseStatus === self::OTP_NOT_FOUND) {
                $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
                $this->logger->notice('OTP not found, showing SMS fallback link.', [
                    'app' => $appName,
                    'response' => $response,
                ]);
                if ($isAjax) {
                    return $this->json(['status' => 'error', 'message' => 'OTP not found.', 'showSmsLink' => true]);
                }
                return $this->redirect('otp');
            }
        }

        // show error message to user for invalid otp
        // For all other errors, log the failure and attempt to use a fallback API
        if ($responseStatus === self::OTP_INVALID) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID);
            $this->logger->notice('Invalid OTP attempt.', ['app' => $appName, 'response' => $response]);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_OTP_INVALID]);
            }
            return $this->redirect('otp');
        } else if ($responseStatus === self::OTP_NOT_FOUND) {
            $this->logger->notice('OTP not found.', ['app' => $appName, 'response' => $response]);
            // Get a new otp for the same url or fallback urls.
            return $this->handleExpiredToken($request, $token);
        } else {
            $this->logger->critical('OTP verification failed with an unexpected status.', ['app' => $appName, 'response' => $response]);
            return $this->handleFailedVerification($request, $token);
        }
    }

    /**
     * Handles the logic for a successful OTP verification.
     */
    private function handleSuccessfulVerification(Request $request, array $response, ?string $platform): Response
    {
        $isAjax = $request->isXmlHttpRequest();
        // todo: need to update mspace apps to remove this check
        if (!$platform) {
            $this->logger->critical('Could not determine platform for successful verification.');
            $this->session->set(self::SESSION_ERROR, self::ERROR_REGISTRATION_FAILED);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_REGISTRATION_FAILED, 'redirect' => './']);
            }
            return $this->redirect('./');
        }

        $isSubscribed = \in_array(
            $response['subscriptionStatus'] ?? null, 
            [self::SUB_STATUS_REGISTERED, self::SUB_STATUS_PENDING], 
            true
        );

        // mspace platform has a magic pass because of legacy code
        if ($isSubscribed || $platform === self::PLATFORM_MSPACE) {
            $regId = $this->generateRandomId('reg-');
            $this->session->set(self::SESSION_REG_ID, $regId);
            // Clear invalid OTP count and SMS link flag
            $this->session->unset(self::SESSION_INVALID_OTP_COUNT);
            $this->session->unset(self::SESSION_SHOW_SMS_LINK);
            if ($isAjax) {
                return $this->json(['status' => 'success', 'redirect' => 'thanks']);
            }
            return $this->redirect('thanks');
        } else {
            $this->session->set(self::SESSION_ERROR, self::ERROR_REGISTRATION_FAILED);
            $this->logger->critical('Registration failed. Unexpected Subscription Status', [
                'platform' => $platform,
                'response' => $response,
            ]);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_REGISTRATION_FAILED]);
            }
            return $this->redirect('otp');
        }
    }

    /**
     * Handles a failed verification by attempting to get a new OTP from a fallback URL.
     * 
     * @param bool $isRateLimit Whether this fallback is triggered by a rate limit exhaustion.
     */
    private function handleFailedVerification(Request $request, array|\ArrayAccess $token, bool $isRateLimit = false): Response
    {
        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        $subscriberId = $phoneData['telco_format'] ?? null;
        $platform = $token['platform'] ?? $phoneData['platform'] ?? null;
        $failedUrl = $token['usedApiUrl'] ?? null;

        if (empty($subscriberId) || empty($platform) || empty($failedUrl)) {
            $this->logger->critical('No fallback possible due to missing data.', [
                'subscriber_id' => $subscriberId,
                'platform' => $platform,
                'failed_url' => $failedUrl
            ]);
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);

            if ($request->isXmlHttpRequest()) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_GENERIC, 'redirect' => './']);
            }

            return $this->redirect('./');
        }

        // Retrieve previously failed URLs from the token and add the current one
        $previouslyFailedUrls = $token['failedUrls'] ?? [];
        $allFailedUrls = array_unique(array_merge($previouslyFailedUrls, [$failedUrl]));

        $response = $this->attemptFallback($platform, $subscriberId, $request, $allFailedUrls);

        if (($response['status'] ?? null) === 'success') {
            return $this->handleFallbackSuccess($request, $response, $allFailedUrls);
        }

        return $this->handleFallbackFailure($request, $isRateLimit);
    }

    /**
     * Attempts to obtain a new OTP from an alternative API endpoint when the primary one fails.
     *
     * @param string $platform The platform identifier.
     * @param string $subscriberId The subscriber's identifier (phone number).
     * @param Request $request The current HTTP request object.
     * @param array $allFailedUrls A list of API URLs that have already failed.
     * @return array The API response.
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
     * Handles a successful OTP verification response.
     *
     * @param Request $request The current request.
     * @param array $response The API response containing the verification result.
     * @param array $allFailedUrls A list of API URLs that were attempted.
     * @return Response The response to redirect to.
     */
    private function handleFallbackSuccess(Request $request, array $response, array $allFailedUrls): Response
    {
        $newToken = $response['verificationToken'];
        if ($newToken instanceof \App\DTO\OtpVerificationToken) {
            $newToken = $newToken->withFailedUrls($allFailedUrls);
        } else {
            $newToken['failedUrls'] = $allFailedUrls;
        }

        $this->logger->info(
            'Successfully received new OTP from a fallback URL.',
            ['app' => $this->getAppNamesFromUrls([$newToken['usedApiUrl'] ?? ''])[0]]
        );

        $visitorId = $this->session->get('visitor_id', 'unknown');
        $this->rateLimiter->clear('otp_verification:' . $visitorId);

        $this->session->set(self::SESSION_OTP_TOKEN, $newToken);
        $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_NEW);

        // Reset invalid OTP count on new OTP
        $this->session->unset(self::SESSION_INVALID_OTP_COUNT);
        $this->session->unset(self::SESSION_SHOW_SMS_LINK);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['status' => 'error', 'message' => self::ERROR_OTP_NEW]);
        }
        return $this->redirect('otp');
    }

    /**
     * Handles a failed OTP verification response after all fallback attempts have been exhausted.
     *
     * @param Request $request The current request.
     * @param bool $isRateLimit Whether the failure was due to rate limiting.
     * @return Response The response to redirect to.
     */
    private function handleFallbackFailure(Request $request, bool $isRateLimit): Response
    {
        if ($isRateLimit) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_RATE_LIMIT);
        } else {
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
        }

        $this->logger->error('All fallback OTP requests failed after a verification error.', [
            'error' => $this->session->get(self::SESSION_ERROR)
        ]);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['status' => 'error', 'message' => $this->session->get(self::SESSION_ERROR)]);
        }

        return $this->redirect('otp');
    }
    /**
     * Checks if the visitor has exceeded the maximum number of OTP verification attempts.
     *
     * @param string $visitorId The ID of the visitor.
     * @return bool True if the visitor has exceeded the maximum number of attempts, false otherwise.
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
        $this->logger->warning('Rate limit exceeded. ', ['visitor_id' => $visitorId, 'max_attempts' => $maxAttempts, 'window' => $window]);
        return true;
    }

    /**
     * Handles an expired OTP token by attempting to retrieve a new one.
     *
     * @param Request $request The current HTTP request object.
     * @param array|\ArrayAccess $token The expired OTP token.
     * @return Response The response to redirect to.
     */
    private function handleExpiredToken(Request $request, array|\ArrayAccess $token): Response
    {
        $usedUrl = $token['usedApiUrl'] ?? null;
        $refNo = $token['referenceNo'] ?? null;
        $createdAt = $token['createdAt'] ?? time();
        $platform = $token['platform'] ?? null;
        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        $subscriberId = $phoneData['telco_format'] ?? null;
        $platform ??= $phoneData['platform'] ?? null;
        $usedApp = $this->getAppNamesFromUrls([$usedUrl])[0];

        $this->logger->notice('OTP reference expired during verification. Attempting to get a new one.', [
            'reference_no' => $refNo,
            'created_at' => $this->timestampToDateString($createdAt),
            'now' => $this->timestampToDateString(time()),
            'platform' => $platform,
            'used_app' => $usedApp,
        ]);

        // Do NOT exclude the current URL, as it might just be a token expiry, not a system failure.
        $failedUrls = [];
        $isAjax = $request->isXmlHttpRequest();

        if (empty($subscriberId) || empty($platform)) {
            $this->logger->critical('Cannot auto-renew expired OTP due to missing data.', [
                'subscriber_id' => $subscriberId,
                'platform' => $platform
            ]);
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);

            // Critical error, redirect to home page
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_GENERIC, 'redirect' => './']);
            }
            return $this->redirect('./');
        }

        $response = $this->attemptFallback($platform, $subscriberId, $request, $failedUrls);

        if (($response['status'] ?? null) === self::OTP_SUCCESS) {
            $newToken = $response['verificationToken'];
            $newUrl = $newToken['usedApiUrl'] ?? null;
            $context = [
                'phone' => $phoneData['capi_format'],
                'app' => $this->getAppNamesFromUrls([$newUrl])[0],
                'reference_no' => $newToken['referenceNo'],
                'created_at' => $this->timestampToDateString($newToken['createdAt'] ?? 0)
            ];
            if ($newUrl === $usedUrl) {
                $this->logger->notice('Successfully renewed expired OTP.', $context);
            } else {
                $this->logger->notice('Successfully received new OTP from a fallback URL.', $context);
            }

            $this->session->set(self::SESSION_OTP_TOKEN, $newToken);
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_EXPIRED);
            
            $this->session->unset(self::SESSION_INVALID_OTP_COUNT);
            $this->session->unset(self::SESSION_SHOW_SMS_LINK);

            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_OTP_EXPIRED]);
            }
            return $this->redirect('otp');
        }

        $this->logger->error('Failed to renew expired OTP and fallback URLs.', [
            'phone' => $phoneData['capi_format'],
            'used_app' => $usedApp,
            'reference_no' => $refNo,
            'created_at' => $this->timestampToDateString($createdAt)
        ]);
        $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
        if ($isAjax) {
            return $this->json(['status' => 'error', 'message' => self::ERROR_GENERIC, 'redirect' => './']);
        }
        return $this->redirect('./');
    }
}
