<?php
// src/Controller/FormController.php

namespace App\Controller;

use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use App\Service\SessionService;
use App\Service\UserLoggerInterface;
use App\Service\UserInfoService;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\CsrfService;
use App\Service\RateLimiterService;

class FormController extends BaseController
{
    // Session Keys
    public const SESSION_ERROR = 'error_message';
    public const SESSION_ALREADY_REGISTERED = 'already_registered';
    public const SESSION_PAGE_VIEW_ID = 'page_view_id';
    public const SESSION_VISITOR_ID = 'visitor_id';
    public const SESSION_PHONE_DATA = 'phone_data';
    public const SESSION_FBP = 'fbp';
    public const SESSION_FBC = 'fbc';
    public const SESSION_LEAD_ID = 'lead_id';
    public const SESSION_OTP_TOKEN = 'otp_token';
    public const SESSION_INVALID_OTP_COUNT = 'invalid_otp_count';
    public const SESSION_SHOW_SMS_LINK = 'show_sms_link';

    // API Statuses
    private const API_STATUS_ALREADY_REGISTERED = 'user already registered';
    private const API_ERROR_TEMPORARY_FAILURE = 'temporary system error';
    private const ALREADY_REGISTERED = 'You are already registered!';

    // Error Messages
    private const ERROR_INVALID_PHONE = 'Invalid phone number. Example: 0771234567';
    private const ERROR_GENERIC = 'An error occurred. Please try again later.';
    private const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';
    private const ERROR_CSRF = 'Security check failed. Please try again.';

    private array $config;
    private array $carrierConfig;
    private ?FacebookCapiService $capiService;
    private OtpApiInterface $otpService;
    private UserLoggerInterface $userLogger;
    private LoggerInterface $logger;
    private UserInfoService $userInfoService;
    private SessionService $session;
    private CsrfService $csrfService;
    private RateLimiterService $rateLimiter;

    public function __construct(
        array $config,
        array $carrierConfig,
        OtpApiInterface $otpService,
        UserLoggerInterface $userLogger,
        ?FacebookCapiService $capiService,
        LoggerInterface $logger,
        UserInfoService $userInfoService,
        SessionService $sessionService,
        CsrfService $csrfService,
        RateLimiterService $rateLimiter
    ) {
        $this->config = $config;
        $this->carrierConfig = $carrierConfig;
        $this->otpService = $otpService;
        $this->userLogger = $userLogger;
        $this->capiService = $capiService;
        $this->logger = $logger;
        $this->userInfoService = $userInfoService;
        $this->session = $sessionService;
        $this->csrfService = $csrfService;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Displays the initial phone number form.
     */
    public function showPhoneForm(Request $request): Response
    {
        $this->trackVisit($request);

        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);

        // Prepare data for the view
        $data = [
            'config' => $this->config,
            'title' => 'Welcome',
            'pageViewEventId' => $this->session->get(self::SESSION_PAGE_VIEW_ID),
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'testEventCode' => $this->config['facebook']['test_event_code'] ?? null,
            'errorMessage' => $this->session->get(self::SESSION_ERROR),
            'alreadyRegistered' => $this->session->get(self::SESSION_ALREADY_REGISTERED),
            'phoneCapi' => $phoneData['capi_format'] ?? null,
            'externalId' => $this->session->get(self::SESSION_VISITOR_ID),
            'country' => 'lk',
            'csrfToken' => $this->csrfService->getToken(),
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
        ];

        // Clear session data after displaying it
        $this->session->unset(self::SESSION_ERROR);
        $this->session->unset(self::SESSION_ALREADY_REGISTERED);

        // Redirect from OTP page if SMS link flag is set
        // Clear invalid OTP count and SMS link flag if exists
        if ($this->session->has(self::SESSION_SHOW_SMS_LINK) || $this->session->has(self::SESSION_INVALID_OTP_COUNT)) {
            $this->session->unset(self::SESSION_INVALID_OTP_COUNT);
            $this->session->unset(self::SESSION_SHOW_SMS_LINK);
        }

        return $this->render('phone_form', $data);
    }

    /**
     * Handles the submission of the phone number form.
     */
    public function handlePhoneForm(Request $request): Response
    {
        $userInfo = $this->userInfoService->get($request);

        // 0. Validate CSRF Token
        if (!$this->csrfService->validate($request->request->get('csrf_token'))) {
            $this->logger->warning('CSRF token validation failed on phone form submission.', $userInfo);
            $this->session->set(self::SESSION_ERROR, self::ERROR_CSRF);
            return $this->redirect('./');
        }

        $rawPhone = $request->request->get('mobile', '');
        $this->logger->info('Phone form submitted', ['raw_phone' => $rawPhone, ...$userInfo]);

        // 1. Rate Limiting Check
        // Limit: 5 attempts per IP per minute
        $rateLimitKey = 'phone_submission:' . $userInfo['ip'];
        if (!$this->rateLimiter->check($rateLimitKey, 5, 60)) {
            $this->logger->warning('Rate limit exceeded for phone submission.', ['ip' => $userInfo['ip'], 'raw_phone' => $rawPhone]);
            $this->session->set(self::SESSION_ERROR, self::ERROR_RATE_LIMIT);
            return $this->redirect('./');
        }
        $this->rateLimiter->increment($rateLimitKey);

        // 2. Validate phone number
        $phoneData = Validator::normalizePhone($rawPhone, $this->carrierConfig, 'LK', $this->logger);
        if ($phoneData === null) {
            $this->session->set(self::SESSION_ERROR, self::ERROR_INVALID_PHONE);
            $this->logger->warning('Invalid phone number submitted', ['raw_phone' => $rawPhone]);
            return $this->redirect('./');
        }

        // 3. Log visit with phone, store FB cookies
        $this->userLogger->logVisit($this->session->get(self::SESSION_VISITOR_ID), $userInfo['ip'], $userInfo['useragent'], $phoneData['capi_format']);
        $this->session->set(self::SESSION_FBP, $request->request->get('fbp'));
        $this->session->set(self::SESSION_FBC, $request->request->get('fbc'));

        // 4. Request OTP from the API. The service now handles platform-specific endpoints.

        // Check if we have a valid recent OTP for this number to avoid redundant calls
        $existingToken = $this->session->get(self::SESSION_OTP_TOKEN);
        $existingPhoneData = $this->session->get(self::SESSION_PHONE_DATA);

        // Check if phone matches and token is valid (less than 5 minutes old)
        if (
            is_array($existingToken) &&
            is_array($existingPhoneData) &&
            ($existingPhoneData['capi_format'] ?? '') === ($phoneData['capi_format'] ?? '') &&
            isset($existingToken['createdAt']) &&
            (time() - $existingToken['createdAt'] < 300) // 5 minutes validity
        ) {
            $this->logger->notice('Reusing existing valid OTP token', [
                'phone' => $phoneData['capi_format'],
                'age' => time() - $existingToken['createdAt']
            ]);
            // Skip API call and reuse existing flow
            return $this->redirect('otp');
        }

        $metaData = array_merge(['client' => 'WEBAPP', 'appCode' => $request->getUri()], $userInfo);
        $response = $this->otpService->getOtp($phoneData['platform'], $phoneData['telco_format'], $metaData);

        // 5. Handle the final API response
        return $this->handleOtpApiResponse($response, $phoneData);
    }

    /**
     * Fires PageView events and logs the user visit.
     */
    private function trackVisit(Request $request): void
    {
        $isInitialVisit = !$this->session->has(self::SESSION_ALREADY_REGISTERED) && !$this->session->has(self::SESSION_ERROR);

        $userInfo = $this->userInfoService->get($request);
        $this->logger->info('New page visit. /', $userInfo);

        // 1. Log the initial anonymous visit to our own DB.
        if ($isInitialVisit) {
            $this->userLogger->logVisit(
                $this->session->get(self::SESSION_VISITOR_ID),
                $userInfo['ip'],
                $userInfo['useragent']
            );
        }

        // 2. Handle CAPI logic. Exit if CAPI is not configured.
        if ($this->capiService === null) {
            return;
        }

        // Only fire a new PageView for initial visit or already registered error.
        // Skip if there is any other error (Invalid Phone, Rate Limit, CSRF, Generic).
        if ($this->session->has(self::SESSION_ERROR)) {
            $this->logger->info('Skipping new PageView event due to session error.', ['error' => $this->session->get(self::SESSION_ERROR)]);
            return;
        }

        // For all other cases (initial visit, or redirect after "already registered"), fire PageView.
        $pageViewEventId = $this->generateRandomId("pgview-");
        $this->session->set(self::SESSION_PAGE_VIEW_ID, $pageViewEventId);

        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        $phoneForMatching = $phoneData['capi_format'] ?? null;
        $visitorId = $this->session->get(self::SESSION_VISITOR_ID);

        // processRequest returns array ['fbc' => ..., 'fbp' => ...]
        $capiParams = $this->capiService->processRequest($request);

        $fbc = $capiParams['fbc'] ?? null;
        $fbp = $capiParams['fbp'] ?? null;
        $clientIpAddress = $capiParams['client_ip_address'] ?? null;

        // Store in session for subsequent events (OTP, Lead)
        if ($fbc) {
            $this->session->set(self::SESSION_FBC, $fbc);
        }
        if ($fbp) {
            $this->session->set(self::SESSION_FBP, $fbp);
        }

        $userDataArray = [
            'ip' => $clientIpAddress ?? $userInfo['ip'],
            'agent' => $userInfo['useragent'],
            'phone' => $phoneForMatching,
            'fbp' => $fbp,
            'fbc' => $fbc,
            'external_id' => $visitorId,
        ];

        // Only add country if we have a valid phone number (implies local user)
        if (!empty($phoneForMatching)) {
            $userDataArray['country'] = 'lk';
        }

        $this->capiService->sendEvent(
            'PageView',
            $pageViewEventId,
            $request->getUri(),
            $userDataArray
        );
        $this->logger->info('New PageView triggered. /', [
            'page_view_id' => $pageViewEventId,
            'has_phone_for_matching' => !empty($phoneForMatching)
        ]);
    }

    /**
     * Handles the final API response after attempting to get an OTP.
     */
    private function handleOtpApiResponse(array $response, array $phoneData): Response
    {
        // Success condition is now based on the 'status' key from our service
        if (($response['status'] ?? null) === 'success') {
            // SUCCESS: OTP was requested.
            $leadId = $this->generateRandomId("lead-");
            $this->session->set(self::SESSION_LEAD_ID, $leadId);
            $this->session->set(self::SESSION_PHONE_DATA, $phoneData);
            // Store the opaque token for the verification step.
            $this->session->set(self::SESSION_OTP_TOKEN, $response['verificationToken']);
            return $this->redirect('otp');
        }

        // FAILURE: No URL succeeded.
        $statusDetail = strtolower($response['statusDetail'] ?? '');

        // Check statusDetail for "already registered"
        if ($statusDetail === self::API_STATUS_ALREADY_REGISTERED) {
            $this->session->set(self::SESSION_ALREADY_REGISTERED, self::ALREADY_REGISTERED);
            $this->session->set(self::SESSION_PHONE_DATA, $phoneData);

            // Only log if ALL failed attempts were due to "already registered"
            $failedAttempts = $response['failedAttempts'] ?? [];
            $alreadyRegisteredUrls = [];
            foreach ($failedAttempts as $attempt) {
                if (($attempt['response']['statusDetail'] ?? '') === self::API_STATUS_ALREADY_REGISTERED) {
                    $alreadyRegisteredUrls[] = $attempt['url'];
                }
            }

            // Scope is "all" if ALL failed attempts were due to "already registered"
            // added \ before count() for compiler optimization
            $scope = \count($alreadyRegisteredUrls) === \count($failedAttempts) ? 'all' : 'some';

            $this->logger->notice("User already registered on $scope of the services for this platform.", [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
                'already_registered_urls' => $alreadyRegisteredUrls,
                'final_url' => $response['finalUrl'] ?? null,
            ]);

        // Check statusDetail for "temporary system error"
        } elseif (str_contains($statusDetail, self::API_ERROR_TEMPORARY_FAILURE)) {
            $smsNumber = $this->config['sms']['number'] ?? null;
            $smsKeyword = $this->config['sms']['keyword'] ?? null;

            if (!($smsNumber && $smsKeyword)) {
                $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
                $this->logger->error('Temporary system error encountered', [
                    'platform' => $phoneData['platform'],
                    'final_url' => $response['finalUrl'] ?? null,
                    'final_response' => $response['finalResponse'] ?? null
                ]);
                return $this->redirect('./');
            }

            $leadId = $this->generateRandomId("lead-");
            $this->session->set(self::SESSION_LEAD_ID, $leadId);
            $this->session->set(self::SESSION_PHONE_DATA, $phoneData);
            $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
            $this->session->set(self::SESSION_OTP_TOKEN, true); // Dummy value to indicate OTP step
            $this->logger->notice('Temporary system error encountered, showing SMS fallback link.', [
                'platform' => $phoneData['platform'],
                'final_url' => $response['finalUrl'] ?? null,
            ]);
            return $this->redirect('otp');
        } else {
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
            $this->logger->error('All OTP request attempts failed for the user.', [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
                ...$response
            ]);
        }
        return $this->redirect('./');
    }

    /**
     * Generates a random ID with a given prefix.
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