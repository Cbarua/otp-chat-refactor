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
use Psr\Log\LoggerInterface;

/**
 * Manages all communication with the Facebook Conversions API (CAPI).
 */
class FacebookCapiService
{
    private string $pixelId;
    private ?string $testEventCode;
    private bool $apiInitialized = false;
    private LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->pixelId = $config['facebook']['pixel_id'];
        $accessToken = $config['facebook']['capi_token'];
        $this->testEventCode = $config['facebook']['test_event_code'] ?? null;
        $this->logger = $logger;

        if (empty($this->pixelId) || empty($accessToken)) {
            $this->logger->error('FacebookCapiService: Pixel ID or Access Token is missing.');
            return;
        }

        try {
            Api::init(null, null, $accessToken);
            $this->apiInitialized = true;
        } catch (Exception $e) {
            $this->logger->error('FacebookCapiService Init Error', [
                'error' => $e->getMessage()
            ]);
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
     * Validates the format of Facebook cookies (fbp/fbc).
     * Format: fb.1.TIMESTAMP.ID
     *
     * @param string|null $value
     * @return bool
     */
    private function isValidFbCookie(?string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Regex Breakdown:
        // ^fb\.      -> Must start with "fb."
        // \d+        -> Followed by one or more digits (subdomain index)
        // \.         -> A dot
        // \d+        -> Timestamp (digits)
        // \.         -> A dot
        // [a-zA-Z0-9\._-]+ -> The ID (alphanumeric, dots, underscores, dashes allowed)
        // $          -> End of string
        return preg_match('/^fb\.\d+\.\d+\.[a-zA-Z0-9\._-]+$/', $value) === 1;
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

        // Get fbp/fbc from cookies or session (set by controller)
        $fbp = $_COOKIE['_fbp'] ?? $_SESSION['fbp'] ?? null;
        // Validate format before using
        if ($this->isValidFbCookie($fbp)) {
            $userData->setFbp($fbp);
        }
        
        $fbc = $_COOKIE['_fbc'] ?? $_SESSION['fbc'] ?? null;
        // If fbc is not in cookies or session, try to generate it from the fbclid query parameter.
        if (!$this->isValidFbCookie($fbc) && !empty($_GET['fbclid'])) { // fbclid is the Facebook Click ID
            // Note: We strictly use 'fb.1.' here as we are creating it on domain index 1
            $fbc = "fb.1." . round(microtime(true) * 1000) . "." . $_GET['fbclid'];
            setcookie('_fbc', $fbc, time() + 90 * 86400, '/'); // Set for 90 days
        }
        
        // Only set if we have a valid fbc now (either from cookie or just generated)
        if ($this->isValidFbCookie($fbc)) {
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
            $this->logger->error("CAPI Error: sendEvent called but API not initialized.");
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
            $this->logger->info("CAPI Event Sent", [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'url' => $eventSourceUrl,
                'user_data' => json_encode($userData->normalize()),
                'response' => $decoded
            ]);
            return $decoded;

        } catch (Exception $e) {
            $this->logger->error("CAPI SendEvent Error", [
                'event_name' => $eventName,
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}