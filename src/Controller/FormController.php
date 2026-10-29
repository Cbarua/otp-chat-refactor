<?php
// src/Controller/FormController.php

namespace App\Controller;

use App\DTO\OtpFlowResult;
use App\Service\OtpFlowService;
use App\Service\OtpFlowState;
use App\Service\SessionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\CsrfService;
use App\Service\AnalyticsTrackerService;
use App\Enum\SessionKey;

class FormController extends BaseController
{
    // Error Messages
    private const ERROR_CSRF = OtpFlowService::ERROR_CSRF;

    public function __construct(
        private array $config,
        private OtpFlowService $otpFlowService,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        protected SessionService $session,
        private CsrfService $csrfService,
        private OtpFlowState $otpFlowState
    ) {
    }

    /**
     * Displays the initial phone number form.
     */
    public function showPhoneForm(Request $request): Response
    {
        $isInitialVisit = !($this->session->has(SessionKey::ALREADY_REGISTERED) || $this->session->has(SessionKey::ERROR_MESSAGE));
        $this->analyticsTracker->trackPageView(
            $request,
            '/',
            'pgview-',
            null,
            $isInitialVisit,
            true
        );

        // Prepare data for the view
        $data = [
            'config' => $this->config,
            'title' => 'Welcome',
            'pageViewEventId' => $this->session->get(SessionKey::PAGE_VIEW_ID),
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'testEventCode' => $this->config['facebook']['test_event_code'] ?? null,
            'errorMessage' => $this->session->get(SessionKey::ERROR_MESSAGE),
            'alreadyRegistered' => $this->session->get(SessionKey::ALREADY_REGISTERED),
            'phoneCapi' => $this->otpFlowState->phone()?->capiFormat,
            'externalId' => $this->session->get(SessionKey::VISITOR_ID),
            'country' => 'lk',
            'csrfToken' => $this->csrfService->getToken(),
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
        ];

        // Clear session data after displaying it
        $this->session->unset(SessionKey::ERROR_MESSAGE);
        $this->session->unset(SessionKey::ALREADY_REGISTERED);

        // Returning to the form clears the invalid OTP count and SMS fallback
        $this->otpFlowState->resetAttempts();

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
