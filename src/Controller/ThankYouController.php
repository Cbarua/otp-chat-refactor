<?php
// src/Controller/ThankYouController.php

namespace App\Controller;

use App\Service\SessionService;
use App\Service\AnalyticsTrackerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enum\SessionKey;

class ThankYouController extends BaseController
{
    // Session Keys
    public const SESSION_REG_ID = SessionKey::REG_ID->value;
    public const SESSION_PHONE_DATA = SessionKey::PHONE_DATA->value;
    public const SESSION_OTP_TOKEN = SessionKey::OTP_TOKEN->value;

    public function __construct(
        private array $config,
        private AnalyticsTrackerService $analyticsTracker,
        private LoggerInterface $logger,
        private SessionService $session
    ) {
    }

    /**
     * Displays the "Thank You" page and fires registration events.
     */
    public function showThankYouPage(Request $request): Response
    {
        // 1. Security Check: Ensure user completed OTP
        if (!$this->session->has(self::SESSION_REG_ID) || !$this->session->has(self::SESSION_PHONE_DATA)) {
            return $this->redirect('./');
        }

        $regId = $this->session->get(self::SESSION_REG_ID);
        $phoneData = $this->session->get(self::SESSION_PHONE_DATA, []);
        $customData = null;

        $pageViewEventId = $this->analyticsTracker->trackPageView(
            $request,
            '/thanks',
            'pgview-thanks-',
            null,
            false,
            false,
            'page_view_id_thanks'
        );

        // Fire "CompleteRegistration" CAPI Event
        if (!empty($regId) && !empty($phoneData['capi_format'])) {
            $this->analyticsTracker->trackCompleteRegistration($request, $phoneData, $regId);
            $customData = [
                'currency' => 'USD',
                'value' => (string) ($phoneData['value'] ?? '0.01')
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
            'phoneCapi' => $phoneData['capi_format'] ?? null,
            'externalId' => $this->session->get('visitor_id'),
            'country' => 'lk',
            'eventData' => ($customData !== null) ? json_encode($customData) : null,
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
        ];

        $otpToken = $this->session->get(self::SESSION_OTP_TOKEN);
        $usedUrl = (is_array($otpToken) || $otpToken instanceof \ArrayAccess) ? ($otpToken['usedApiUrl'] ?? 'unknown') : 'unknown';
        $this->logger->info('Thank You page reached. Conversion successful.', ['app' => $this->getAppNamesFromUrls([$usedUrl])[0]]);

        // 6. Clear session to prevent re-firing
        $this->session->unset(self::SESSION_REG_ID);
        $this->session->unset(self::SESSION_OTP_TOKEN);
        // We keep 'phone_data' just in case, but clear sensitive IDs

        // 7. Render the view
        return $this->render('thanks', $data);
    }
}