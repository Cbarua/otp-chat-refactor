<?php
// src/Controller/ThankYouController.php

namespace App\Controller;

use App\Service\OtpFlowState;
use App\Service\SessionService;
use App\Service\AnalyticsTrackerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

        // Fire the PageView and "CompleteRegistration" events
        $tracking = $this->analyticsTracker->trackThankYouPage($request, $phone, $regId);

        // 5. Prepare data for the view
        $data = [
            'config' => $this->config,
            'title' => 'Thank You',
        ] + $tracking;

        $usedUrl = $this->otpFlowState->token()?->usedApiUrl ?? 'unknown';
        $this->logger->info('Thank You page reached. Conversion successful.', ['app' => $this->getAppNamesFromUrls([$usedUrl])[0]]);

        // 6. Clear the reg id and token to prevent re-firing
        $this->otpFlowState->finish();

        // 7. Render the view
        return $this->render('thanks', $data);
    }
}