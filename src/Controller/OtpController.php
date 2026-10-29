<?php
// src/Controller/OtpController.php
declare(strict_types=1);

namespace App\Controller;

use App\DTO\OtpFlowResult;
use App\Enum\SessionKey;
use App\Service\AnalyticsTrackerService;
use App\Service\CsrfService;
use App\Service\OtpFlowService;
use App\Service\OtpFlowState;
use App\Service\SessionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller managing OTP form presentation and submission orchestration.
 */
class OtpController extends BaseController
{
    // Error Messages
    private const ERROR_CSRF = OtpFlowService::ERROR_CSRF;
    private const ERROR_SESSION_EXPIRED = 'Session expired. Please try again.';

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
     * Displays the OTP entry form.
     */
    public function showOtpForm(Request $request): Response
    {
        $userInfo = $this->analyticsTracker->getUserInfo($request);

        // Security Check: Ensure user has a token from the previous step.
        if (!$this->otpFlowState->inVerificationStep()) {
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
            SessionKey::PAGE_VIEW_ID_OTP
        );

        $phone = $this->otpFlowState->phone();
        $leadId = $this->otpFlowState->takeLeadId();
        if ($leadId !== null && $phone !== null) {
            $this->analyticsTracker->trackLead($request, $phone, $leadId);
        }

        $smsNumber = $this->config['sms']['number'] ?? null;
        $smsKeyword = $this->config['sms']['keyword'] ?? null;

        // Ensure SMS link is only shown if SMS number and keyword are configured
        $showSmsLink = $smsNumber && $smsKeyword && $this->otpFlowState->inSmsFallback();

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
            'leadEventId' => $leadId,
            'pageViewEventId' => $this->session->get(SessionKey::PAGE_VIEW_ID_OTP),
            'phoneCapi' => $phone?->capiFormat,
            'testEventCode' => $this->config['facebook']['test_event_code'] ?? null,
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'externalId' => $this->session->get(SessionKey::VISITOR_ID),
            'country' => 'lk',
            'errorMessage' => $this->session->get(SessionKey::ERROR_MESSAGE),
            'csrfToken' => $this->csrfService->getToken(),
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
            'showSmsLink' => $showSmsLink,
            'smsNumber' => $smsNumber,
            'smsKeyword' => $smsKeyword,
        ];

        $this->session->unset(SessionKey::ERROR_MESSAGE);

        return $this->render('otp_form', $data);
    }

    /**
     * Handles the submission of the OTP.
     */
    public function handleOtpForm(Request $request): Response
    {
        $result = $this->validateOtpRequest($request)
            ?? $this->otpFlowService->processVerification($request, (string) $request->request->get('otp', ''));

        return $this->respond($request, $result, 'otp');
    }

    /**
     * Validates CSRF token and session OTP token validity before processing.
     */
    private function validateOtpRequest(Request $request): ?OtpFlowResult
    {
        $csrfToken = $request->request->get('csrf_token');
        if (!$this->csrfService->validate($csrfToken)) {
            $this->logger->warning('CSRF token validation failed on OTP form submission.', ['csrf_token' => $csrfToken]);
            // load the otp page for new csrf token
            return OtpFlowResult::error(self::ERROR_CSRF, 'otp');
        }

        if (!$this->otpFlowState->inVerificationStep()) {
            $this->logger->error('OTP submission without a token.');
            return OtpFlowResult::error(self::ERROR_SESSION_EXPIRED, './', flash: false);
        }

        // SMS fallback entered at the request step holds no token.
        if ($this->otpFlowState->token() === null) {
            $this->logger->error('Invalid OTP token structure in session.');
            return OtpFlowResult::error(self::ERROR_SESSION_EXPIRED, './', flash: false);
        }

        return null;
    }
}
