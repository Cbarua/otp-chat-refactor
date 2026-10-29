<?php
// src/Controller/FormController.php

namespace App\Controller;

use App\DTO\OtpFlowResult;
use App\Service\OtpFlowService;
use App\Service\SessionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\CsrfService;
use App\Service\AnalyticsTrackerService;
use App\Enum\SessionKey;

class FormController extends BaseController
{
    // Session Keys
    public const SESSION_ERROR = SessionKey::ERROR_MESSAGE->value;
    public const SESSION_ALREADY_REGISTERED = SessionKey::ALREADY_REGISTERED->value;
    public const SESSION_PAGE_VIEW_ID = SessionKey::PAGE_VIEW_ID->value;
    public const SESSION_VISITOR_ID = SessionKey::VISITOR_ID->value;
    public const SESSION_PHONE_DATA = SessionKey::PHONE_DATA->value;
    public const SESSION_FBC = SessionKey::FBC->value;
    public const SESSION_INVALID_OTP_COUNT = SessionKey::INVALID_OTP_COUNT->value;
    public const SESSION_SHOW_SMS_LINK = SessionKey::SHOW_SMS_LINK->value;

    // Error Messages
    private const ERROR_CSRF = OtpFlowService::ERROR_CSRF;

    public function __construct(
        private array $config,
        private OtpFlowService $otpFlowService,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        protected SessionService $session,
        private CsrfService $csrfService
    ) {
    }

    /**
     * Displays the initial phone number form.
     */
    public function showPhoneForm(Request $request): Response
    {
        $isInitialVisit = !($this->session->has(self::SESSION_ALREADY_REGISTERED) || $this->session->has(self::SESSION_ERROR));
        $this->analyticsTracker->trackPageView(
            $request,
            '/',
            'pgview-',
            null,
            $isInitialVisit,
            true
        );

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
        $userInfo = $this->analyticsTracker->getUserInfo($request);
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
            // Only rendering the form issues a token, so XHR reloads it.
            return $this->respond($request, OtpFlowResult::error(self::ERROR_CSRF, './'), './');
        }

        return $this->respond($request, $this->otpFlowService->requestOtp($request, (string) $rawPhone), './');
    }
}
