<?php
// src/Controller/ThankYouController.php

namespace App\Controller;

use App\Service\OtpFlowState;
use App\Service\SessionService;
use App\Service\AnalyticsTrackerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enum\SessionKey;

class ThankYouController extends BaseController
{
    public function __construct(
        private array $config,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        protected SessionService $session,
        private OtpFlowState $otpFlowState
    ) {
    }

    /**
     * Displays the "Thank You" page and fires registration events.
     */
    public function showThankYouPage(Request $request): Response
    {
        // 1. Security Check: Ensure user completed OTP
        $regId = $this->otpFlowState->regId();
        $phone = $this->otpFlowState->phone();
        if ($regId === null || $phone === null) {
            return $this->redirect('./');
        }

        $customData = null;

        $pageViewEventId = $this->analyticsTracker->trackPageView(
            $request,
            '/thanks',
            'pgview-thanks-',
            null,
            false,
            false,
            SessionKey::PAGE_VIEW_ID_THANK_YOU
        );

        // Fire "CompleteRegistration" CAPI Event
        if ($phone->capiFormat !== '') {
            $this->analyticsTracker->trackCompleteRegistration($request, $phone, $regId);
            $customData = [
                'currency' => 'USD',
                'value' => (string) $phone->value
            ];
        }

        // 5. Prepare data for the view
        $data = [
            'config' => $this->config,
            'title' => 'Thank You',
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'testEventCode' => $this->config['facebook']['test_event_code'] ?? null,
            'pageViewEventId' => $pageViewEventId,
            'regId' => $regId,
            'phoneCapi' => $phone->capiFormat,
            'externalId' => $this->session->get(SessionKey::VISITOR_ID),
            'country' => 'lk',
            'eventData' => ($customData !== null) ? json_encode($customData) : null,
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
        ];

        $usedUrl = $this->otpFlowState->token()?->usedApiUrl ?? 'unknown';
        $this->logger->info('Thank You page reached. Conversion successful.', ['app' => $this->getAppNamesFromUrls([$usedUrl])[0]]);

        // 6. Clear the reg id and token to prevent re-firing
        $this->otpFlowState->finish();

        // 7. Render the view
        return $this->render('thanks', $data);
    }
}