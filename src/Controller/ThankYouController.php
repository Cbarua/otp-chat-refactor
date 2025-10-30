<?php
// src/Controller/ThankYouController.php

namespace App\Controller;

use App\Service\FacebookCapiService;
use App\Utils\Helpers;

class ThankYouController
{
    private array $config;
    private FacebookCapiService $capiService;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->capiService = new FacebookCapiService($config);
    }

    /**
     * Displays the "Thank You" page and fires registration events.
     */
    public function showThankYouPage(): void
    {
        // 1. Security Check: Ensure user completed OTP
        if (empty($_SESSION['reg_id']) || empty($_SESSION['phone_data'])) {
            $this->redirect('/');
            return;
        }

        // 2. Get all data from session
        $regId = $_SESSION['reg_id'];
        $phoneData = $_SESSION['phone_data'];
        $userInfo = Helpers::getUserInfo();

        // 3. Prepare Event Data
        $customData = [
            'currency' => 'USD',
            'value' => $phoneData['value']
        ];

        // 4. Fire "CompleteRegistration" CAPI Event
        $this->capiService->sendEvent(
            'CompleteRegistration',
            $regId, // This ID is shared with the Pixel
            Helpers::getCurrentUrl(),
            $userInfo['ip'],
            $userInfo['useragent'],
            $phoneData['capi_format'],
            $customData
        );

        // 5. Prepare data for the view
        $data = [
            'config' => $this->config,
            'pixelId' => $this->config['facebook']['pixel_id'],
            'testEventCode' => $this->config['facebook']['test_event_code'],
            'regId' => $regId, // For Pixel deduplication
            'phoneCapi' => $phoneData['capi_format'], // For Pixel Advanced Matching
            'eventData' => json_encode($customData) // For Pixel event
        ];

        // 6. Clear session to prevent re-firing
        unset($_SESSION['reg_id']);
        unset($_SESSION['lead_id']);
        unset($_SESSION['otp_ref_no']);
        // We keep 'phone_data' just in case, but clear sensitive IDs

        // 7. Render the view
        $this->render('thanks', $data);
    }

    private function render(string $viewName, array $data = []): void
    {
        extract($data);
        require_once __DIR__ . "/../../templates/_layout_header.php";
        require_once __DIR__ . "/../../templates/{$viewName}.php";
        require_once __DIR__ . "/../../templates/_layout_footer.php";
    }

    private function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }
}