<?php
// src/Service/FacebookCapiService.php

namespace App\Service;

// Use statements for the Facebook SDK classes
use FacebookAds\Api;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Exception;

/**
 * Manages all communication with the Facebook Conversions API (CAPI).
 */
class FacebookCapiService
{
    private string $pixelId;
    private ?string $testEventCode;
    private string $logPath;
    private bool $apiInitialized = false;

    public function __construct(array $config)
    {
        $this->pixelId = $config['facebook']['pixel_id'];
        $accessToken = $config['facebook']['capi_token'];
        $this->testEventCode = $config['facebook']['test_event_code'] ?? null;
        $this->logPath = $config['log_path']['capi'];

        if (empty($this->pixelId) || empty($accessToken)) {
            error_log('FacebookCapiService: Pixel ID or Access Token is missing.');
            return;
        }

        try {
            Api::init(null, null, $accessToken);
            $this->apiInitialized = true;
        } catch (Exception $e) {
            error_log('FacebookCapiService Init Error: ' . $e->getMessage());
        }
    }

    /**
     * Hashes a user identifier (phone/email) using SHA-256.
     * @param string $value
     * @return string
     */
    private function hashIdentifier(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    /**
     * Builds a UserData object for the SDK.
     *
     * @param string $clientIp
     * @param string $clientUserAgent
     * @param string|null $phoneNormalized (e.g., 94771234567)
     * @return UserData
     */
    private function buildUserData(string $clientIp, string $clientUserAgent, ?string $phoneNormalized = null): UserData
    {
        $userData = new UserData();

        if (!empty($phoneNormalized)) {
            $userData->setPhone($this->hashIdentifier($phoneNormalized));
        }

        $userData->setClientIpAddress($clientIp);
        $userData->setClientUserAgent($clientUserAgent);

        // Get fbp/fbc from session (set by controller) or cookies
        $fbp = $_SESSION['fbp'] ?? $_COOKIE['_fbp'] ?? null;
        if (!empty($fbp)) {
            $userData->setFbp($fbp);
        }
        
        $fbc = $_SESSION['fbc'] ?? $_COOKIE['_fbc'] ?? null;
        // Logic to generate fbc from fbclid is removed for simplicity.
        // The controller should capture this from the query and store in session.
        if (empty($fbc) && !empty($_GET['fbclid'])) {
             $fbc = "fb.1." . round(microtime(true) * 1000) . "." . $_GET['fbclid'];
             setcookie('_fbc', $fbc, time() + 90 * 86400, '/'); // Set for 90 days
        }
        if (!empty($fbc)) {
            $userData->setFbc($fbc);
        }

        return $userData;
    }

    /**
     * Send a single server-side event to Facebook.
     *
     * @param string $eventName
     * @param string $eventId (For deduplication)
     * @param string $eventSourceUrl
     * @param string $clientIp
     * @param string $clientUserAgent
     * @param string|null $phoneNormalized
     * @param array|null $customData (e.g., ['value' => 0.01, 'currency' => 'USD'])
     * @return array|null
     * @throws Exception
     */
    public function sendEvent(
        string $eventName,
        string $eventId,
        string $eventSourceUrl,
        string $clientIp,
        string $clientUserAgent,
        ?string $phoneNormalized = null,
        ?array $customData = null
    ): ?array {
        if (!$this->apiInitialized) {
            error_log("CAPI Error: sendEvent called but API not initialized.");
            return null;
        }

        $userData = $this->buildUserData($clientIp, $clientUserAgent, $phoneNormalized);

        $event = new Event();
        $event->setEventName($eventName);
        $event->setEventTime(time());
        $event->setUserData($userData);
        $event->setEventSourceUrl($eventSourceUrl);
        $event->setActionSource('website');
        $event->setEventId($eventId); // For deduplication

        if ($customData !== null) {
            $customDataObject = new CustomData();
            if (isset($customData['value'])) {
                $customDataObject->setValue(floatval($customData['value']));
            }
            if (isset($customData['currency'])) {
                $customDataObject->setCurrency($customData['currency']);
            }
            $event->setCustomData($customDataObject);
        }

        $events = [$event];
        $eventRequest = new EventRequest($this->pixelId);
        $eventRequest->setEvents($events);

        if ($this->testEventCode) {
            $eventRequest->setTestEventCode($this->testEventCode);
        }

        try {
            $response = $eventRequest->execute();
            $decoded = json_decode($response, true);
            
            // Log CAPI response
            file_put_contents($this->logPath, 
                date('c') . " event_id:$eventId" . 
                " userdata:" . json_encode($userData->normalize()) .
                " response:" . $response . 
                " url: $eventSourceUrl" . PHP_EOL, 
                FILE_APPEND
            );
            return $decoded;

        } catch (Exception $e) {
            error_log("CAPI SendEvent Error: " . $e->getMessage());
            return null;
        }
    }
}