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
use FacebookAds\ParamBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Manages all communication with the Facebook Conversions API (CAPI).
 */
class FacebookCapiService
{
    private ?string $pixelId;
    private ?string $testEventCode;
    private bool $apiInitialized = false;
    private LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->pixelId = $config['facebook']['pixel_id'] ?? null;
        $accessToken = $config['facebook']['capi_token'] ?? null;
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
     * @param array $userDataArray Data to populate UserData (ip, agent, phone, fbp, fbc, external_id, country)
     * @return UserData
     */
    private function buildUserData(array $userDataArray): UserData
    {
        $userData = new UserData();

        if (!empty($userDataArray['phone'])) {
            $userData->setPhone($this->hashIdentifier($userDataArray['phone']));
        }

        if (!empty($userDataArray['ip'])) {
            $userData->setClientIpAddress($userDataArray['ip']);
        }

        if (!empty($userDataArray['agent'])) {
            $userData->setClientUserAgent($userDataArray['agent']);
        }

        if (!empty($userDataArray['fbp']) && $this->isValidFbCookie($userDataArray['fbp'])) {
            $userData->setFbp($userDataArray['fbp']);
        }

        if (!empty($userDataArray['fbc']) && $this->isValidFbCookie($userDataArray['fbc'])) {
            $userData->setFbc($userDataArray['fbc']);
        }

        if (!empty($userDataArray['external_id'])) {
            $userData->setExternalId($this->hashIdentifier($userDataArray['external_id']));
        }

        if (!empty($userDataArray['country'])) {
            $userData->setCountryCode($this->hashIdentifier($userDataArray['country']));
        }

        return $userData;
    }

    /**
     * Send a single server-side event to Facebook.
     *
     * @param string $eventName
     * @param string $eventId (For deduplication)
     * @param string $eventSourceUrl
     * @param array $userDataArray ['ip', 'agent', 'phone', 'fbp', 'fbc', 'external_id', 'country']
     * @param array|null $customData (e.g., ['value' => 0.01, 'currency' => 'USD'])
     * @return array|null
     */
    public function sendEvent(
        string $eventName,
        string $eventId,
        string $eventSourceUrl,
        array $userDataArray,
        ?array $customData = null
    ): ?array {
        if (!$this->apiInitialized) {
            $this->logger->error("CAPI Error: sendEvent called but API not initialized.");
            return null;
        }

        $userData = $this->buildUserData($userDataArray);

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
                // 'user_data' => $userDataArray, // Log the raw array for debugging
                'user_data' => json_encode($userData->normalize()), // Log the normalized array for debugging and accuracy
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


    /**
     * Processes the incoming request to extract CAPI parameters using the ParamBuilder library.
     *
     * @param Request $request
     * @return array
     */
    public function processRequest(Request $request): array
    {
        $host = $request->getHost();
        // ParamBuilder expects a list of domains to match against.
        // We use the current host.
        $domains = [$host];

        $paramBuilder = new ParamBuilder($domains);

        // processRequest expects:
        // (string $host, array $query_params, array $cookies, ?string $referer, ?string $x_forwarded_for, ?string $remote_address)
        $paramBuilder->processRequest(
            $host,
            $request->query->all(),
            $request->cookies->all(),
            $request->headers->get('referer'),
            $request->headers->get('x-forwarded-for'),
            $request->server->get('REMOTE_ADDR')
        );

        return [
            'fbc' => $paramBuilder->getFbc(),
            'fbp' => $paramBuilder->getFbp(),
            // We can also retrieve improved client_ip_address if needed
            'client_ip_address' => $paramBuilder->getClientIpAddress()
        ];
    }
}