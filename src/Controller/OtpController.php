<?php
// src/Controller/OtpController.php

namespace App\Controller;

use App\Service\OtpApiInterface;
use App\Service\SessionService;
use App\Service\UserInfoService;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;
use App\Service\FacebookCapiService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\CsrfService;
use App\Service\RateLimiterService;

class OtpController extends BaseController
{
    // Session Keys
    public const SESSION_OTP_TOKEN = 'otp_token';
    public const SESSION_PHONE_DATA = 'phone_data';
    public const SESSION_ERROR = 'error_message';
    public const SESSION_PAGE_VIEW_ID = 'page_view_id_otp';
    public const SESSION_REG_ID = 'reg_id';
    public const SESSION_LEAD_ID = 'lead_id';
    public const SESSION_INVALID_OTP_COUNT = 'invalid_otp_count';
    public const SESSION_SHOW_SMS_LINK = 'show_sms_link';

    // API Statuses
    public const OTP_SUCCESS = 'success';
    public const OTP_INVALID = 'Invalid OTP';
    public const OTP_NOT_FOUND = 'Could not find OTP';
    public const SUB_STATUS_PENDING = 'INITIAL CHARGING PENDING';
    public const SUB_STATUS_REGISTERED = 'REGISTERED';

    // Error Messages
    private const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';
    private const ERROR_CSRF = 'Security check failed. Please try again.';
    private const ERROR_OTP_INVALID_LENGTH = 'Invalid OTP. Must be 6 digits.';
    private const ERROR_OTP_INVALID = 'Invalid OTP. Please enter the correct OTP.';
    private const ERROR_OTP_NEW = 'Please try again with the new OTP sent to your phone.';
    private const ERROR_GENERIC = 'An error occurred. Please try again later.';


    // Platforms
    public const PLATFORM_MSPACE = 'mspace';

    private array $config;
    private OtpApiInterface $otpService;
    private LoggerInterface $logger;
    private ?FacebookCapiService $capiService;
    private UserInfoService $userInfoService;
    private SessionService $session;
    private CsrfService $csrfService;
    private RateLimiterService $rateLimiter;

    public function __construct(
        array $config,
        OtpApiInterface $otpService,
        ?FacebookCapiService $capiService,
        LoggerInterface $logger,
        UserInfoService $userInfoService,
        SessionService $sessionService,
        CsrfService $csrfService,
        RateLimiterService $rateLimiter
    ) {
        $this->config = $config;
        $this->otpService = $otpService;
        $this->logger = $logger;
        $this->capiService = $capiService;
        $this->userInfoService = $userInfoService;
        $this->session = $sessionService;
        $this->csrfService = $csrfService;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Displays the OTP entry form.
     */
    public function showOtpForm(Request $request): Response
    {
        // Security Check: Ensure user has a token from the previous step.
        if (!$this->session->has(self::SESSION_OTP_TOKEN)) {
            $this->logger->error('OTP form accessed without a token');
            return $this->redirect('./');
        }

        if ($this->capiService !== null) {
            // Handles pageview and lead events
            // Sets event ids needed for the view
            $this->handleCapiEvents($request);
        }

        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);

        $smsNumber = $this->config['sms']['number'] ?? null;
        $smsKeyword = $this->config['sms']['keyword'] ?? null;

        // It makes sure that the sms link is only shown if the sms number and keyword are set
        // Backwards compatibility
        $showSmsLink = ($smsNumber && $smsKeyword) ? $this->session->get(self::SESSION_SHOW_SMS_LINK, false) : false;

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

        if (!$this->checkRateLimit($visitorId)) {
            $this->logger->warning('Rate limit exceeded.', ['visitor_id' => $visitorId]);
            return $this->handleFailedVerification($request, $token, true);
        }

        return $this->processOtp($request, $token);
    }

    private function validateOtpRequest(Request $request): ?Response
    {
        if (!$this->csrfService->validate($request->request->get('csrf_token'))) {
            $this->logger->warning('CSRF token validation failed on OTP form submission.');
            $this->session->set(self::SESSION_ERROR, self::ERROR_CSRF);
            return $this->redirect('otp');
        }

        if (!$this->session->has(self::SESSION_OTP_TOKEN)) {
            $this->logger->error('OTP submission without a valid token.');
            return $this->redirect('./');
        }

        return null;
    }

    private function processOtp(Request $request, array $token): Response
    {
        $rawOtp = $request->request->get('otp', '');
        $this->logger->info('OTP form submitted', ['raw_otp' => $rawOtp, 'ref_no' => $token['referenceNo'] ?? 'N/A']);

        if (!Validator::validateOtp($rawOtp)) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID_LENGTH);
            $this->logger->warning(self::ERROR_OTP_INVALID_LENGTH, ['otp' => $rawOtp]);
            return $this->redirect('otp');
        }

        $response = $this->otpService->verifyOtp($token, $rawOtp);
        $this->logger->info('OTP verification attempted', [
            'token' => $token,
            'response' => $response
        ]);

        return $this->handleVerificationResponse($request, $response, $token);
    }

    /**
     * Handles CAPI events for the OTP page view.
     */
    private function handleCapiEvents(Request $request): void
    {
        $userInfo = $this->userInfoService->get($request);
        $this->handlePageViewEvent($request, $userInfo);
        $this->handleLeadEvent($request, $userInfo);
    }

    private function handlePageViewEvent(Request $request, array $userInfo): void
    {
        $isErrorRedirect = $this->session->has(self::SESSION_ERROR);

        // Trigger PageView only if it's not a redirect showing an error
        if (!$isErrorRedirect) {
            $pageViewEventId = $this->generateRandomId('pgview-otp-');
            $this->session->set(self::SESSION_PAGE_VIEW_ID, $pageViewEventId);

            $this->logger->info('New PageView triggered. /otp', ['page_view_id' => $pageViewEventId]);

            $visitorId = $this->session->get('visitor_id');
            $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
            $phoneForMatching = $phoneData['capi_format'] ?? null;

            // Retrieve fbp/fbc from session (set in FormController)
            $fbp = $this->session->get('fbp');
            $fbc = $this->session->get('fbc');

            $userDataArray = [
                'ip' => $userInfo['ip'],
                'agent' => $userInfo['useragent'],
                'phone' => $phoneForMatching,
                'fbp' => $fbp,
                'fbc' => $fbc,
                'external_id' => $visitorId,
                'country' => 'lk'
            ];

            $this->capiService->sendEvent('PageView', $pageViewEventId, $request->getUri(), $userDataArray);
        } else {
            $this->logger->info('Rendering /otp to display error, skipping new PageView.');
        }
    }

    private function handleLeadEvent(Request $request, array $userInfo): void
    {
        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        // Fire a pending Lead event if it exists
        if ($this->session->has(self::SESSION_LEAD_ID) && isset($phoneData['capi_format'])) {
            $this->logger->info('Lead event flag found. Firing CAPI + Pixel.');

            $visitorId = $this->session->get('visitor_id');
            $phoneForMatching = $phoneData['capi_format'];

            // Retrieve fbp/fbc from session
            $fbp = $this->session->get('fbp');
            $fbc = $this->session->get('fbc');

            $userDataArray = [
                'ip' => $userInfo['ip'],
                'agent' => $userInfo['useragent'],
                'phone' => $phoneForMatching,
                'fbp' => $fbp,
                'fbc' => $fbc,
                'external_id' => $visitorId,
                'country' => 'lk'
            ];

            $this->capiService->sendEvent(
                'Lead',
                $this->session->get(self::SESSION_LEAD_ID),
                $request->getUri(),
                $userDataArray
            );
            // Unset lead event id after data for view is prepared
        }
    }

    /**
     * Processes the API response after an OTP verification attempt.
     */
    private function handleVerificationResponse(Request $request, array $response, array $token): Response
    {
        if (($response['status'] ?? null) === self::OTP_SUCCESS) {
            $platform = $token['platform'] ?? ($this->session->get(self::SESSION_PHONE_DATA)['platform'] ?? null);
            return $this->handleSuccessfulVerification($response, $platform);
        }

        // If SMS config is set, show sms link
        // Backward compatibility
        if (!empty($this->config['sms']['number']) && !empty($this->config['sms']['keyword'])) {
            if (($response['status'] ?? null) === self::OTP_INVALID) {
                // Increment invalid OTP count
                $count = $this->session->get(self::SESSION_INVALID_OTP_COUNT, 0) + 1;
                $this->session->set(self::SESSION_INVALID_OTP_COUNT, $count);

                if ($count >= 3) {
                    $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
                } else {
                    $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID);
                }

                return $this->redirect('otp');
            }

            if (($response['status'] ?? null) === self::OTP_NOT_FOUND) {
                $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
                return $this->redirect('otp');
            }
        }

        if (($response['status'] ?? null) === self::OTP_INVALID) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID);
            return $this->redirect('otp');
        }

        // For all other errors, log the failure and attempt to use a fallback API
        $this->logger->warning('OTP verification failed with an unexpected status.', [
            'status' => $response['status'] ?? 'N/A',
            'response' => $response,
        ]);
        return $this->handleFailedVerification($request, $token);
    }

    /**
     * Handles the logic for a successful OTP verification.
     */
    private function handleSuccessfulVerification(array $response, ?string $platform): Response
    {
        if (!$platform) {
            $this->logger->error('Could not determine platform for successful verification.');
            $this->session->set(self::SESSION_ERROR, 'Registration failed. Please try again.');
            return $this->redirect('otp');
        }

        $isSubscribed = ($response['subscriptionStatus'] ?? null) === self::SUB_STATUS_PENDING || ($response['subscriptionStatus'] ?? null) === self::SUB_STATUS_REGISTERED;

        if ($isSubscribed || $platform === self::PLATFORM_MSPACE) {
            $regId = $this->generateRandomId('reg-');
            $this->session->set(self::SESSION_REG_ID, $regId);
            // Clear invalid OTP count and SMS link flag
            $this->session->unset(self::SESSION_INVALID_OTP_COUNT);
            $this->session->unset(self::SESSION_SHOW_SMS_LINK);
            return $this->redirect('thanks');
        } else {
            $this->session->set(self::SESSION_ERROR, 'Registration failed. Please try again.');
            return $this->redirect('otp');
        }
    }

    /**
     * Handles a failed verification by attempting to get a new OTP from a fallback URL.
     * 
     * @param bool $isRateLimit Whether this fallback is triggered by a rate limit exhaustion.
     */
    private function handleFailedVerification(Request $request, array $token, bool $isRateLimit = false): Response
    {
        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        $subscriberId = $phoneData['telco_format'] ?? null;
        $platform = $token['platform'] ?? $phoneData['platform'] ?? null;
        $failedUrl = $token['usedApiUrl'] ?? null;

        if (empty($subscriberId) || empty($platform) || empty($failedUrl)) {
            $this->logger->error('No fallback possible due to missing data.', [
                'has_subscriber_id' => !empty($subscriberId),
                'has_platform' => !empty($platform),
                'has_failed_url' => !empty($failedUrl)
            ]);
            $this->session->set(self::SESSION_ERROR, 'An error occurred. Please try again later.');
            return $this->redirect('otp');
        }

        // Retrieve previously failed URLs from the token and add the current one
        $previouslyFailedUrls = $token['failedUrls'] ?? [];
        $allFailedUrls = array_unique(array_merge($previouslyFailedUrls, [$failedUrl]));

        $response = $this->attemptFallback($platform, $subscriberId, $request, $allFailedUrls);

        if (($response['status'] ?? null) === 'success') {
            return $this->handleFallbackSuccess($response, $allFailedUrls);
        }

        return $this->handleFallbackFailure($isRateLimit);
    }

    private function attemptFallback(string $platform, string $subscriberId, Request $request, array $allFailedUrls): array
    {
        $userInfo = $this->userInfoService->get($request);
        $metaData = array_merge(['client' => 'WEBAPP', 'appCode' => $request->getUri()], $userInfo);

        return $this->otpService->getOtp($platform, $subscriberId, $metaData, $allFailedUrls);
    }

    private function handleFallbackSuccess(array $response, array $allFailedUrls): Response
    {
        $this->logger->info('Successfully received new OTP from a fallback URL.');

        $visitorId = $this->session->get('visitor_id', 'unknown');
        $this->rateLimiter->clear('otp_verification:' . $visitorId);

        $newToken = $response['verificationToken'];
        $newToken['failedUrls'] = $allFailedUrls;

        $this->session->set(self::SESSION_OTP_TOKEN, $newToken);
        $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_NEW);

        // Reset invalid OTP count on new OTP
        $this->session->unset(self::SESSION_INVALID_OTP_COUNT);
        $this->session->unset(self::SESSION_SHOW_SMS_LINK);

        return $this->redirect('otp');
    }

    private function handleFallbackFailure(bool $isRateLimit): Response
    {
        $this->logger->error('All fallback OTP requests failed after a verification error.');

        if ($isRateLimit) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_RATE_LIMIT);
        } else {
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
        }

        return $this->redirect('otp');
    }

    private function generateRandomId(string $prefix): string
    {
        try {
            return $prefix . bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return $prefix . uniqid();
        }
    }

    private function checkRateLimit(string $visitorId): bool
    {
        $rateLimitKey = 'otp_verification:' . $visitorId;
        if ($this->rateLimiter->check($rateLimitKey, 5, 600)) {
            $this->rateLimiter->increment($rateLimitKey);
            return true;
        }
        return false;
    }
}
