<?php
// src/Controller/ThankYouController.php

namespace App\Controller;

use App\Service\FacebookCapiService;
use App\Service\SessionService;
use App\Service\UserInfoService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ThankYouController extends BaseController
{
    // Session Keys
    public const SESSION_REG_ID = 'reg_id';
    public const SESSION_PHONE_DATA = 'phone_data';
    public const SESSION_OTP_TOKEN = 'otp_token';

    private array $config;
    private ?FacebookCapiService $capiService;
    private LoggerInterface $logger;
    private UserInfoService $userInfoService;
    private SessionService $session;

    public function __construct(
        array $config,
        ?FacebookCapiService $capiService,
        LoggerInterface $logger,
        UserInfoService $userInfoService,
        SessionService $sessionService
    ) {
        $this->config = $config;
        $this->capiService = $capiService;
        $this->logger = $logger;
        $this->userInfoService = $userInfoService;
        $this->session = $sessionService;
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
        $pageViewEventId = null;
        $customData = null;

        if ($this->capiService !== null) {
            // 2. Get user info for CAPI events
            $userInfo = $this->userInfoService->get($request);
            $visitorId = $this->session->get('visitor_id');
            $phoneForMatching = $phoneData['capi_format'] ?? null;
            $fbp = $this->session->get('fbp');
            $fbc = $this->session->get('fbc');
            
            $userDataArray = [
                'ip' => $userInfo['ip'],
                'agent' => $userInfo['useragent'],
                'phone' => $phoneForMatching,
                'external_id' => $visitorId,
                'fbp' => $fbp,
                'fbc' => $fbc,
                'country' => 'lk' // Default to LK
            ];

            $pageViewEventId = "pgview-thanks-" . uniqid();
            $this->session->set('page_view_id_thanks', $pageViewEventId);

            $this->logger->info('New PageView triggered. /thanks', [
                'page_view_id' => $pageViewEventId
            ]);

            // Fire the PageView CAPI event
            $this->capiService->sendEvent(
                'PageView',
                $pageViewEventId,
                $request->getUri(),
                $userDataArray
            );

            // Fire "CompleteRegistration" CAPI Event
            if (!empty($regId) && !empty($phoneForMatching)) {

                $customData = [
                    'currency' => 'USD',
                    'value' => $phoneData['value'] ?? '0.01' // FB Capi needs a value
                ];

                $this->logger->info('CompleteRegistration event flag found. Firing CAPI + Pixel.');

                $this->capiService->sendEvent(
                    'CompleteRegistration',
                    $regId,
                    $request->getUri(),
                    $userDataArray,
                    $customData
                );
            }
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
        $usedUrl = $otpToken['usedApiUrl'] ?? 'unknown';
        $this->logger->info('Thank You page reached. Conversion successful.', ['url' => $usedUrl]);

        // 6. Clear session to prevent re-firing
        $this->session->unset(self::SESSION_REG_ID);
        $this->session->unset(self::SESSION_OTP_TOKEN);
        // We keep 'phone_data' just in case, but clear sensitive IDs

        // 7. Render the view
        return $this->render('thanks', $data);
    }
}