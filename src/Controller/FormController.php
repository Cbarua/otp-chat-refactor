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
use App\Service\UrlRotationService;

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
    private const API_ERROR_MAX_REQUESTS = 'maximum number of otp requests reached';
    private const ALREADY_REGISTERED = 'You are already registered!';

    // Error Messages
    private const ERROR_INVALID_PHONE = 'Invalid phone number. Example: 0771234567';
    private const ERROR_MAX_REQUESTS = 'Maximum number of OTP requests reached for :number. Please try again in :minutes minutes';
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
    private ?UrlRotationService $urlRotationService;

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
        RateLimiterService $rateLimiter,
        ?UrlRotationService $urlRotationService = null
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
        $this->urlRotationService = $urlRotationService;
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

        // Clear invalid OTP count and SMS link flag if exists when returning to the form
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
        $rawPhone = $request->request->get('mobile', '');
        $isAjax = $request->isXmlHttpRequest();

        $data = ['raw_phone' => $rawPhone] + $userInfo;
        if ($isAjax) {
            $this->logger->info('Phone form submitted via AJAX', $data);
        } else {
            $this->logger->info('Phone form submitted', $data);
        }

        // 0. Validate CSRF Token
        $csrfToken = $request->request->get('csrf_token');
        if (!$this->csrfService->validate($csrfToken)) {
            $this->logger->warning('CSRF token validation failed on phone form submission.', ['csrf_token' => $csrfToken]);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_CSRF]);
            }
            $this->session->set(self::SESSION_ERROR, self::ERROR_CSRF);
            return $this->redirect('./');
        }

        // 1. Rate Limiting Check
        // Limit: 5 attempts per IP per minute
        $rateLimitKey = 'phone_submission:' . $userInfo['ip'];
        if (!$this->rateLimiter->check($rateLimitKey, 5, 60)) {
            $this->logger->warning('Rate limit exceeded for phone submission.', ['rate_limit_key' => $rateLimitKey]);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_RATE_LIMIT]);
            }
            $this->session->set(self::SESSION_ERROR, self::ERROR_RATE_LIMIT);
            return $this->redirect('./');
        }
        $this->rateLimiter->increment($rateLimitKey);

        // 2. Validate phone number
        $phoneData = Validator::normalizePhone($rawPhone, $this->carrierConfig, 'LK', $this->logger);
        if ($phoneData === null) {
            $this->logger->warning('Invalid phone number submitted', ['raw_phone' => $rawPhone]);
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::ERROR_INVALID_PHONE]);
            }
            $this->session->set(self::SESSION_ERROR, self::ERROR_INVALID_PHONE);
            return $this->redirect('./');
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
            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => $errorMessage]);
            }
            $this->session->set(self::SESSION_ERROR, $errorMessage);
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
        $isSameNumber = ($existingPhoneData['capi_format'] ?? '') === ($phoneData['capi_format'] ?? null);
        $isRecent = false;
        if (is_array($existingToken)) {
            $tokenCreatedAt = $existingToken['createdAt'] ?? 0;
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
            if ($isAjax) {
                return $this->json(['status' => 'success', 'redirect' => 'otp']);
            }
            return $this->redirect('otp');
        }

        $metaData = array_merge(['client' => 'WEBAPP', 'appCode' => $request->getUri()], $userInfo);

        // URL Rotation Logic
        $customUrls = null;
        $platform = $phoneData['platform'];
        $rotationPlatform = $this->config['api']['otp_rotation_platform'] ?? null;
        $excludedPhones = $this->config['api']['otp_rotation_excluded_phones'] ?? [];
        
        if ($this->urlRotationService !== null && $platform === $rotationPlatform && !in_array($rawPhone, $excludedPhones)) {
            $this->urlRotationService->incrementSubmissionCount($platform);

            if ($this->urlRotationService->shouldRotate($platform)) {
                $defaultUrls = $this->config['api'][$platform] ?? [];
                $priorityNames = $this->config['api']['otp_url_priority'] ?? [];
                $customUrls = $this->urlRotationService->getRotatedUrls($defaultUrls, $priorityNames);
                $this->logger->info('URL Rotation triggered', ['platform' => $platform, 'urls' => $customUrls]);
            }
            $response = $this->otpService->getOtp($phoneData['platform'], $phoneData['telco_format'], $metaData, [], $customUrls);
            return $this->handleOtpApiResponse($request, $response, $phoneData);
        }

        $response = $this->otpService->getOtp($phoneData['platform'], $phoneData['telco_format'], $metaData);

        // 5. Handle the final API response
        return $this->handleOtpApiResponse($request, $response, $phoneData);
    }

    /**
     * Fires PageView events and logs the user visit.
     */
    private function trackVisit(Request $request): void
    {
        $isInitialVisit = !($this->session->has(self::SESSION_ALREADY_REGISTERED) ||$this->session->has(self::SESSION_ERROR));

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
            $this->logger->notice('Rendering / to display error, skipping new PageView.', ['error' => $this->session->get(self::SESSION_ERROR)]);
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
            'phone_for_matching' => $phoneForMatching
        ]);
    }

    /**
     * Handles the final API response after attempting to get an OTP.
     */
    private function handleOtpApiResponse(Request $request, array $response, array $phoneData): Response
    {
        $isAjax = $request->isXmlHttpRequest();

        // Success condition is now based on the 'status' key from our service
        if (($response['status'] ?? null) === 'success') {
            // SUCCESS: OTP was requested.
            $leadId = $this->generateRandomId("lead-");
            $this->session->set(self::SESSION_LEAD_ID, $leadId);
            $this->session->set(self::SESSION_PHONE_DATA, $phoneData);
            // Store the opaque token for the verification step.
            $this->session->set(self::SESSION_OTP_TOKEN, $response['verificationToken']);

            if ($isAjax) {
                return $this->json(['status' => 'success', 'redirect' => 'otp']);
            }
            return $this->redirect('otp');
        }

        // FAILURE: No URL succeeded.
        $statusDetail = strtolower($response['statusDetail'] ?? '');

        // Check statusDetail for "already registered"
        if ($statusDetail === self::API_STATUS_ALREADY_REGISTERED) {
            // Only log if ALL failed attempts were due to "already registered"
            $failedAttempts = $response['failedAttempts'] ?? [];
            $alreadyRegisteredUrls = [];
            foreach ($failedAttempts as $attempt) {
                if (($attempt['response']['statusDetail'] ?? '') === self::API_STATUS_ALREADY_REGISTERED) {
                    $alreadyRegisteredUrls[] = $attempt['base_url'];
                }
            }

            // Scope is "all" if ALL failed attempts were due to "already registered"
            // added \ before count() for compiler optimization
            $scope = \count($alreadyRegisteredUrls) === \count($failedAttempts) ? 'all' : 'some';

            $this->logger->notice("User already registered on $scope of the services for this platform.", [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
                'already_registered_apps' => $this->getAppNamesFromUrls($alreadyRegisteredUrls),
                'final_app' => $this->getAppNamesFromUrls([$response['finalUrl'] ?? ''])[0],
            ]);

            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => self::API_STATUS_ALREADY_REGISTERED]);
            }

            $this->session->set(self::SESSION_ALREADY_REGISTERED, self::ALREADY_REGISTERED);
            $this->session->set(self::SESSION_PHONE_DATA, $phoneData);

        // Check statusDetail for "temporary system error"
        } elseif (str_contains($statusDetail, self::API_ERROR_TEMPORARY_FAILURE)) {
            $smsConfig = $this->getSmsConfig($request);
            $smsNumber = $smsConfig['number'] ?? null;
            $smsKeyword = $smsConfig['keyword'] ?? null;

            if (!($smsNumber && $smsKeyword)) {
                $this->logger->error('Temporary system error encountered', [
                    'platform' => $phoneData['platform'],
                    'final_app' => $this->getAppNamesFromUrls([$response['finalUrl'] ?? ''])[0],
                ]);
                if ($isAjax) {
                    return $this->json(['status' => 'error', 'message' => self::ERROR_GENERIC]);
                }
                $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
                return $this->redirect('./');
            }

            $leadId = $this->generateRandomId("lead-");
            $this->session->set(self::SESSION_LEAD_ID, $leadId);
            $this->session->set(self::SESSION_PHONE_DATA, $phoneData);
            $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
            $this->session->set(self::SESSION_OTP_TOKEN, true); // Dummy value to indicate OTP step
            $this->logger->notice('Temporary system error encountered, showing SMS fallback link.', [
                'platform' => $phoneData['platform'],
                'final_app' => $this->getAppNamesFromUrls([$response['finalUrl'] ?? ''])[0],
            ]);

            if ($isAjax) {
                return $this->json([
                    'status' => 'error', 
                    'message' => 'Temporary system error', 
                    'showSmsLink' => true, 
                    'redirect' => 'otp'
                ]);
            }
            return $this->redirect('otp');
        } elseif (str_contains($statusDetail, self::API_ERROR_MAX_REQUESTS)) {
            $rawPhone = $request->request->get('mobile', '');
            $this->rateLimiter->block('otp_max_requests:' . $phoneData['capi_format'], 3600);

            $smsConfig = $this->getSmsConfig($request);
            $smsNumber = $smsConfig['number'] ?? null;
            $smsKeyword = $smsConfig['keyword'] ?? null;

            if ($smsNumber && $smsKeyword) {
                $leadId = $this->generateRandomId("lead-");
                $this->session->set(self::SESSION_LEAD_ID, $leadId);
                $this->session->set(self::SESSION_PHONE_DATA, $phoneData);
                $this->session->set(self::SESSION_SHOW_SMS_LINK, true);
                $this->session->set(self::SESSION_OTP_TOKEN, true); // Dummy value to indicate OTP step
                $this->logger->notice('Maximum OTP requests reached, showing SMS fallback link.', [
                    'platform' => $phoneData['platform'],
                    'phone' => $phoneData['capi_format'],
                ]);

                if ($isAjax) {
                    return $this->json([
                        'status' => 'error',
                        'message' => 'Maximum number of OTP requests reached',
                        'showSmsLink' => true,
                        'redirect' => 'otp'
                    ]);
                }
                return $this->redirect('otp');
            }

            $remainingMinutes = 60;
            $errorMessage = str_replace([':number', ':minutes'], [$rawPhone, $remainingMinutes], self::ERROR_MAX_REQUESTS);
            $this->logger->notice('Maximum OTP requests reached without SMS fallback configured.', [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
            ]);

            if ($isAjax) {
                return $this->json(['status' => 'error', 'message' => $errorMessage]);
            }
            $this->session->set(self::SESSION_ERROR, $errorMessage);
            return $this->redirect('./');
        } else {
            $this->session->set(self::SESSION_ERROR, self::ERROR_GENERIC);
            $this->logger->error('All OTP request attempts failed for the user.', [
                'platform' => $phoneData['platform'],
                'phone' => $phoneData['capi_format'],
                ...$response
            ]);
        }

        if ($isAjax) {
            // Clear error messages
            $this->session->unset(self::SESSION_ERROR);
            $this->session->unset(self::SESSION_ALREADY_REGISTERED);
            return $this->json(['status' => 'error', 'message' => self::ERROR_GENERIC]);
        }
        return $this->redirect('./');
    }

    /**
     * Retrieves SMS configuration, respecting test override cookies if present.
     */
    private function getSmsConfig(Request $request): ?array
    {
        if ($request->cookies->has('test_disable_sms')) {
            return null;
        }
        return $this->config['sms'] ?? null;
    }
}