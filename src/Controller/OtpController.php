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

    // API Statuses
    public const OTP_SUCCESS = 'success';
    public const OTP_INVALID = 'Invalid OTP';
    public const SUB_STATUS_PENDING = 'INITIAL CHARGING PENDING';
    public const SUB_STATUS_REGISTERED = 'REGISTERED';

    // Error Messages
    private const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';
    private const ERROR_CSRF = 'Security check failed. Please try again.';
    private const ERROR_INVALID_OTP = 'Invalid OTP. Must be 6 digits.';
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
            return $this->redirect('/');
        }

        if ($this->capiService !== null) {
            // Handles pageview and lead events
            // Sets event ids needed for the view
            $this->handleCapiEvents($request);
        }

        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);

        // Prepare data for the view
        $data = [
            'config' => $this->config,
            'leadEventId' => $this->session->get(self::SESSION_LEAD_ID),
            'pageViewEventId' => $this->session->get(self::SESSION_PAGE_VIEW_ID),
            'phoneCapi' => $phoneData['capi_format'] ?? null,
            'testEventCode' => $this->config['facebook']['test_event_code'] ?? null,
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'errorMessage' => $this->session->get(self::SESSION_ERROR),
            'csrfToken' => $this->csrfService->getToken(),
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
        // 0. Validate CSRF Token
        if (!$this->csrfService->validate($request->request->get('csrf_token'))) {
            $this->logger->warning('CSRF token validation failed on OTP form submission.');
            $this->session->set(self::SESSION_ERROR, self::ERROR_CSRF);
            return $this->redirect('/otp');
        }

        // 1. Rate Limiting Check
        // Limit: 5 attempts per session per 10 minutes (600 seconds)
        $visitorId = $this->session->get('visitor_id', 'unknown');
        $rateLimitKey = 'otp_verification:' . $visitorId;

        if (!$this->rateLimiter->check($rateLimitKey, 5, 600)) {
            $this->logger->warning('Rate limit exceeded for OTP verification.', ['visitor_id' => $visitorId]);
            $this->session->set(self::SESSION_ERROR, self::ERROR_RATE_LIMIT);
            return $this->redirect('/otp');
        }
        $this->rateLimiter->increment($rateLimitKey);

        // 2. Sanity check session data
        $token = $this->session->get(self::SESSION_OTP_TOKEN);
        if (empty($token)) {
            $this->logger->error('OTP submission without a valid token.');
            return $this->redirect('/');
        }

        // 3. Validate user input
        $rawOtp = $request->request->get('otp', '');
        $this->logger->info('OTP form submitted', ['raw_otp' => $rawOtp, 'ref_no' => $token['referenceNo'] ?? 'N/A']);

        if (!Validator::validateOtp($rawOtp)) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_INVALID_OTP);
            $this->logger->warning(self::ERROR_INVALID_OTP, ['otp' => $rawOtp]);
            return $this->redirect('/otp');
        }

        // 4. Verify OTP and handle the response
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
        $isErrorRedirect = $this->session->has(self::SESSION_ERROR);

        // Trigger PageView only if it's not a redirect showing an error
        if (!$isErrorRedirect) {
            try {
                $pageViewEventId = "pgview-otp-" . bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $pageViewEventId = "pgview-otp-" . uniqid();
            }
            $this->session->set(self::SESSION_PAGE_VIEW_ID, $pageViewEventId);

            $this->logger->info('New PageView triggered. /otp', ['page_view_id' => $pageViewEventId]);

            $this->capiService->sendEvent('PageView', $pageViewEventId, $request->getUri(), $userInfo['ip'], $userInfo['useragent']);
        } else {
            $this->logger->info('Rendering /otp to display error, skipping new PageView.');
        }

        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        // Fire a pending Lead event if it exists
        if ($this->session->has(self::SESSION_LEAD_ID) && isset($phoneData['capi_format'])) {
            $this->logger->info('Lead event flag found. Firing CAPI + Pixel.');
            $this->capiService->sendEvent(
                'Lead',
                $this->session->get(self::SESSION_LEAD_ID),
                $request->getUri(),
                $userInfo['ip'],
                $userInfo['useragent'],
                $phoneData['capi_format']
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

        if (($response['status'] ?? null) === self::OTP_INVALID) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_INVALID);
            return $this->redirect('/otp');
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
            return $this->redirect('/otp');
        }

        $isSubscribed = ($response['subscriptionStatus'] ?? null) === self::SUB_STATUS_PENDING || ($response['subscriptionStatus'] ?? null) === self::SUB_STATUS_REGISTERED;

        if ($isSubscribed || $platform === self::PLATFORM_MSPACE) {
            try {
                $regId = "reg-" . bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $regId = "reg-" . uniqid();
            }
            $this->session->set(self::SESSION_REG_ID, $regId);
            return $this->redirect('/thanks');
        } else {
            $this->session->set(self::SESSION_ERROR, 'Registration failed. Please try again.');
            return $this->redirect('/otp');
        }
    }

    /**
     * Handles a failed verification by attempting to get a new OTP from a fallback URL.
     */
    private function handleFailedVerification(Request $request, array $token): Response
    {
        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        $subscriberId = $phoneData['telco_format'] ?? null;
        $platform = $token['platform'] ?? null;
        $failedUrl = $token['usedApiUrl'] ?? null;

        if (empty($subscriberId) || empty($platform) || empty($failedUrl)) {
            $this->logger->error('No fallback possible due to missing data.', [
                'has_subscriber_id' => !empty($subscriberId),
                'has_platform' => !empty($platform),
                'has_failed_url' => !empty($failedUrl)
            ]);
            $this->session->set(self::SESSION_ERROR, 'An error occurred. Please try again later.');
            return $this->redirect('/otp');
        }

        $userInfo = $this->userInfoService->get($request);
        $metaData = array_merge(['client' => 'WEBAPP', 'appCode' => $request->getUri()], $userInfo);

        // Call the service, excluding the URL that just failed.
        $response = $this->otpService->getOtp($platform, $subscriberId, $metaData, [$failedUrl]);

        // Check if the service returned a new token
        if (($response['status'] ?? null) === 'success') {
            $this->logger->info('Successfully received new OTP from a fallback URL.');
            // Store the new token and send the user back to try again.
            $this->session->set(self::SESSION_OTP_TOKEN, $response['verificationToken']);
            $this->session->set(self::SESSION_ERROR, self::ERROR_OTP_NEW);
        } else {
            $this->logger->error('All fallback OTP requests failed after a verification error.');
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
        }

        return $this->redirect('/otp');
    }
}