<?php
// src/Controller/FormController.php

namespace App\Controller;

// Import services and utils
use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use App\Service\UserLoggerInterface;
use App\Utils\Helpers;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;

class FormController
{
    private array $config;
    private array $carrierConfig;
    private FacebookCapiService $capiService;
    private OtpApiInterface $otpService;
    private UserLoggerInterface $userLogger;
    private LoggerInterface $logger;

    public function __construct(
        array $config,
        array $carrierConfig,
        OtpApiInterface $otpService, 
        UserLoggerInterface $userLogger,
        FacebookCapiService $capiService,
        LoggerInterface $logger
    )
    {
        $this->config = $config;
        $this->carrierConfig = $carrierConfig;

        // Initialize all required services
        // JUST ASSIGN them, don't create them
        $this->otpService = $otpService;
        $this->userLogger = $userLogger;
        $this->capiService = $capiService;
        $this->logger = $logger;
    }

    /**
     * Displays the initial phone number form.
     */
    public function showPhoneForm(): void
    {
        // Check if we are just showing an error from a redirect
        $isErrorRedirect = isset($_SESSION['error_message']);

        // Get user info for CAPI PageView
        $userInfo = Helpers::getUserInfo();
        $pageViewEventId = null;

        // Only fire PageView if not an error redirect
        if (!$isErrorRedirect) {
            // Generate a unique PageView ID for FB events
            $pageViewEventId = "pgview-" . uniqid();
            $_SESSION['page_view_id'] = $pageViewEventId;
            
            $this->logger->info('New PageView triggered. /', [
                'page_view_id' => $pageViewEventId
            ]);

            // Fire the PageView CAPI event
            // I'll add visitor_id as external_id later
            $this->capiService->sendEvent(
                'PageView',
                $pageViewEventId,
                Helpers::getCurrentUrl(),
                $userInfo['ip'],
                $userInfo['useragent']
            );
            
            // Log this visit (without phone number)
            $this->userLogger->logVisit($_SESSION['visitor_id'], $userInfo['ip'], $userInfo['useragent']);

        } else {
            $this->logger->info('Rendering form to display error, skipping new PageView event.');
        }

        // Prepare data for the view
        $data = [
            'config' => $this->config,
            'pageViewEventId' => $pageViewEventId,
            'testEventCode' => $this->config['facebook']['test_event_code'],
            'pixelId' => $this->config['facebook']['pixel_id'],
            'errorMessage' => $_SESSION['error_message'] ?? null,
            'phoneCapi' => $_SESSION['phone_data']['capi_format'] ?? null,
        ];
        
        // Clear the error message after displaying it
        unset($_SESSION['error_message']);

        // Render the view
        $this->render('phone_form', $data);
    }

    /**
     * Handles the submission of the phone number form.
     */
    public function handlePhoneForm(): void
    {
        $rawPhone = $_POST['mobile'] ?? '';
        $userInfo = Helpers::getUserInfo();

        $this->logger->info('Phone form submitted', [
            'raw_phone' => $rawPhone,
            'user_info' => $userInfo
        ]);

        // 1. Validate and normalize the phone number
        $phoneData = Validator::normalizePhone($rawPhone, $this->carrierConfig, 'LK');

        if ($phoneData === null) {
            // Invalid phone number
            $_SESSION['error_message'] = 'Invalid phone number. Example: 0771234567';
            $this->logger->warning('Invalid phone number submitted', [
                'raw_phone' => $rawPhone
            ]);
            $this->redirect('/');
            return;
        }

        // 2. Log the visit (now with a phone number)
        $this->userLogger->logVisit(
            $_SESSION['visitor_id'],
            $userInfo['ip'], 
            $userInfo['useragent'], 
            $phoneData['capi_format']
        );

        // 3. Store fbp/fbc cookies from the form post into session
        $_SESSION['fbp'] = $_POST['fbp'] ?? null;
        $_SESSION['fbc'] = $_POST['fbc'] ?? null;

        // 4. Request OTP from the API
        $metaData = array_merge([
            'client' => 'WEBAPP',
            'appCode' => Helpers::getCurrentUrl()
        ], $userInfo);
        
        $response = $this->otpService->getOtp(
            $phoneData['platform'],
            $phoneData['telco_format'],
            $metaData
        );

        $this->logger->info('OTP API response', [
            'response' => $response
        ]);

        // 5. Handle API Response
        if (isset($response['referenceNo'])) {
            // SUCCESS
            // Securely store data in session, NOT URL
            // Lead event is fired on OTP form display
            $_SESSION['lead_id'] = "lead-" . uniqid();
            $_SESSION['phone_data'] = $phoneData;
            $_SESSION['otp_ref_no'] = $response['referenceNo'];

            $this->redirect('/otp');
        } else {
            // FAILURE
            //
            if ($response['statusDetail'] === 'user already registered') {
                $_SESSION['error_message'] = 'You are already registered!';
                $_SESSION['phone_data'] = $phoneData;
            } else {
                $_SESSION['error_message'] = 'An error occurred. Please try again later.';
            }
            $this->redirect('/');
        }
    }

    /**
     * Helper to render a view.
     */
    protected function render(string $viewName, array $data = []): void
    {
        // Make $data keys available as variables in the view
        extract($data);
        
        // This simple template engine just includes the files
        require_once __DIR__ . "/../../templates/_layout_header.php";
        require_once __DIR__ . "/../../templates/{$viewName}.php";
        require_once __DIR__ . "/../../templates/_layout_footer.php";
    }

    /**
     * Helper to redirect.
     */
    protected function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }
}