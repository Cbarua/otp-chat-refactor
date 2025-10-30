<?php
// src/Controller/OtpController.php

namespace App\Controller;

use App\Service\OtpApiInterface;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;
use App\Service\FacebookCapiService;
use App\Utils\Helpers;

class OtpController
{
    private array $config;
    private OtpApiInterface $otpService;
    private LoggerInterface $logger;
    private FacebookCapiService $capiService;

    public function __construct(
        array $config, 
        OtpApiInterface $otpService, 
        FacebookCapiService $capiService,
        LoggerInterface $logger
    )
    {
        $this->config = $config;
        $this->otpService = $otpService;
        $this->logger = $logger;
        $this->capiService = $capiService;
    }

    /**
     * Displays the OTP entry form.
     */
    public function showOtpForm(): void
    {
        // Security Check: Ensure user has a refNo from the previous step.
        if (empty($_SESSION['otp_ref_no'])) {
            $this->logger->error('OTP form accessed without refNo');
            $this->redirect('/');
            return;
        }

        $userInfo = Helpers::getUserInfo();
        
        $isErrorRedirect = isset($_SESSION['error_message']);
        $pageViewEventId = null;

        if (!$isErrorRedirect) {
            $this->logger->info('New PageView triggered. /otp');

            $pageViewEventId = "pgview-otp-" . uniqid();
            $_SESSION['page_view_id_otp'] = $pageViewEventId;

            // Fire the PageView CAPI event
            $this->capiService->sendEvent(
                'PageView',
                $pageViewEventId,
                Helpers::getCurrentUrl(),
                $userInfo['ip'],
                $userInfo['useragent']
            );

        } else {
            $this->logger->info('Rendering /otp to display error, skipping new PageView.');
        }

        $leadEventId = null;
        // Check if the lead event is pending
        if (isset($_SESSION['lead_id'], $_SESSION['phone_data']['capi_format'])) {
            $this->logger->info('Lead event flag found. Firing CAPI + Pixel.');
            
            $leadEventId = $_SESSION['lead_id'];

            // Fire the "Lead" CAPI Event
            $this->capiService->sendEvent(
                'Lead',
                $leadEventId, 
                Helpers::getCurrentUrl(),
                $userInfo['ip'],
                $userInfo['useragent'],
                $_SESSION['phone_data']['capi_format']
            );

            // Unset the flag so it never fires again
            unset($_SESSION['lead_id']);
        }

        // Prepare data for the view
        $data = [
            'config' => $this->config,
            'leadEventId' => $leadEventId,
            'phoneCapi' => $_SESSION['phone_data']['capi_format'], // For FB Lead Pixel
            'testEventCode' => $this->config['facebook']['test_event_code'],
            'pixelId' => $this->config['facebook']['pixel_id'],
            'errorMessage' => $_SESSION['error_message'] ?? null,
        ];
        
        unset($_SESSION['error_message']);

        // Render the view
        $this->render('otp_form', $data);
    }

    /**
     * Handles the submission of the OTP.
     */
    public function handleOtpForm(): void
    {
        // Get data from session (secure)
        $refNo = $_SESSION['otp_ref_no'] ?? null;
        $platform = $_SESSION['phone_data']['platform'] ?? null;

        // Security check
        if (empty($refNo) || empty($platform)) {
            $this->logger->error('OTP submission without valid session data', [
                'otp_ref_no' => $_SESSION['otp_ref_no'] ?? null,
                'phone_data' => $_SESSION['phone_data'] ?? null
            ]);
            $this->redirect('/');
            return;
        }

        $rawOtp = $_POST['otp'] ?? '';

        $this->logger->info('OTP form submitted', [
            'raw_otp' => $rawOtp,
            'otp_ref_no' => $_SESSION['otp_ref_no']
        ]);

        // Validate OTP format
        if (!Validator::validateOtp($rawOtp)) {
            $_SESSION['error_message'] = 'Invalid PIN. Must be 6 digits.';
            $this->logger->warning($_SESSION['error_message']);
            $this->redirect('/otp');
            return;
        }

        // Verify OTP with the API
        $response = $this->otpService->verifyOtp($platform, $refNo, $rawOtp);
        $this->logger->info('OTP verification attempted', [
            'platform' => $platform,
            'ref_no' => $refNo,
            'response' => $response
        ]);

        // Handle API Response
        if ($response['status'] === 'success') {
            // SUCCESS
            // Check subscription status
            $isSubscribed = $response['subscriptionStatus'] === 'INITIAL CHARGING PENDING';
            
            // 'mspace' platform has legacy code
            if ($isSubscribed || $platform === 'mspace') {
                // Generate a unique registration ID for FB events
                $_SESSION['reg_id'] = "reg-" . uniqid();
                
                // Note: The CAPI event is *not* fired here.
                // We move it to ThankYouController to fire alongside the Pixel event.
                // This fixes the event mismatch bug.
                
                $this->redirect('/thanks');
            } else {
                // Handle cases like 'NOT REGISTERED', etc.
                $_SESSION['error_message'] = 'Registration failed. Please try again.';
                $this->redirect('/otp');
            }
        } else {
            if ($response['status'] === 'Invalid OTP') {
                $_SESSION['error_message'] = 'Invalid OTP. Please try again.';
            } else {
                $_SESSION['error_message'] = 'An error occurred. Please try again later.';
            }
            $this->redirect('/otp');
        }
    }

    protected function render(string $viewName, array $data = []): void
    {
        extract($data);
        require_once __DIR__ . "/../../templates/_layout_header.php";
        require_once __DIR__ . "/../../templates/{$viewName}.php";
        require_once __DIR__ . "/../../templates/_layout_footer.php";
    }

    protected function redirect(string $url): void
    {
        $this->logger->info("Redirecting to {$url}");
        header("Location: {$url}");
        exit;
    }
}