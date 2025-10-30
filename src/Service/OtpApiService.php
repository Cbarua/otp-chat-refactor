<?php
// src/Service/OtpApiService.php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Manages all communication with the third-party OTP provider.
 */
class OtpApiService implements OtpApiInterface
{
    private Client $client;
    private array $apiUrls;

    public function __construct(array $config)
    {
        $this->apiUrls = $config['api'];
        $this->client = new Client([
            'timeout' => 5.0, // Set a reasonable timeout
            'headers' => ['Content-Type' => 'application/json']
        ]);
    }

    /**
     * Requests an OTP from the provider.
     *
     * @param string $platform 'ideamart' or 'mspace'
     * @param string $subscriberId The 'tel:...' formatted number
     * @param array $metaData Additional data for the API call
     * @return array The JSON response as an array
     */
    public function getOtp(string $platform, string $subscriberId, array $metaData): array
    {
        $url = ($this->apiUrls[$platform] ?? $this->apiUrls['ideamart']) . 'getOtp.php';
        
        $payload = [
            'subscriberId' => $subscriberId,
            'applicationMetaData' => $metaData
        ];

        return $this->sendRequest($url, $payload);
    }

    /**
     * Verifies an OTP with the provider.
     *
     * @param string $platform 'ideamart' or 'mspace'
     * @param string $referenceNo The reference number from the getOtp call
     * @param string $otp The 6-digit user-provided OTP
     * @return array The JSON response as an array
     */
    public function verifyOtp(string $platform, string $referenceNo, string $otp): array
    {
        $url = ($this->apiUrls[$platform] ?? $this->apiUrls['ideamart']) . 'verifyOtp.php';
        
        $payload = [
            'referenceNo' => $referenceNo,
            'otp' => $otp
        ];
        
        return $this->sendRequest($url, $payload);
    }

    /**
     * The core Guzzle request handler.
     * This replaces the insecure cURL function.
     */
    private function sendRequest(string $url, array $payload): array
    {
        try {
            $response = $this->client->post($url, [
                'json' => $payload
            ]);

            $body = $response->getBody()->getContents();
            return json_decode($body, true) ?? ['status' => 'error', 'message' => 'Invalid JSON response'];

        } catch (RequestException $e) {
            // Log the error
            error_log("OTP API Error: " . $e->getMessage());
            
            // Return a standard error format
            return [
                'status' => 'error',
                'message' => 'API request failed',
                'detail' => $e->getMessage()
            ];
        } catch (\Exception $e) {
            error_log("OTP Service Error: " . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'A system error occurred',
                'detail' => $e->getMessage()
            ];
        }
    }
}