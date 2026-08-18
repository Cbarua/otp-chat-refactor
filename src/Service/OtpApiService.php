<?php
// src/Service/OtpApiService.php

namespace App\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use App\DTO\OtpVerificationToken;

/**
 * Manages all communication with the third-party OTP provider.
 */
class OtpApiService implements OtpApiInterface
{
    private array $apiConfig;
    private ClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(array $apiConfig, LoggerInterface $logger, ClientInterface $httpClient)
    {
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
        $this->client = $httpClient;
    }

    /**
     * Requests an OTP from the provider for a specific platform.
     * It tries multiple URLs if necessary.
     *
     * @param string $platform The platform identifier (e.g., 'ideamart', 'mspace').
     * @param string $subscriberId The 'tel:...' formatted number.
     * @param array $metaData Additional data for the API call.
     * @return array The response. On success, includes 'referenceNo' and 'verificationToken'.
     */
    public function getOtp(string $platform, string $subscriberId, array $metaData, array $excludeUrls = []): array
    {
        $allBaseUrls = $this->apiConfig[$platform] ?? [];
        if (empty($allBaseUrls)) {
            $this->logger->critical('No API URLs configured for platform.', ['platform' => $platform]);
            return ['status' => 'error', 'message' => 'Url not found for platform.'];
        }

        // Filter out any URLs that should be excluded for this attempt
        $baseUrls = array_diff($allBaseUrls, $excludeUrls);
        if (empty($baseUrls)) {
            $this->logger->warning('All available API URLs for the platform were excluded.', [
                'platform' => $platform,
                'excluded_urls' => $excludeUrls
            ]);
            return ['status' => 'error', 'message' => 'No available API endpoints to try.'];
        }

        $lastResponse = [];
        $failedUrls = [];
        $failedAttempts = [];

        foreach ($baseUrls as $baseUrl) {
            $url = rtrim($baseUrl, '/') . '/getOtp.php';

            $payload = [
                'subscriberId' => $subscriberId,
                'applicationMetaData' => $metaData
            ];

            $response = $this->sendRequest($url, $payload);

            if (($response['statusCode'] ?? null) === 'S1000') {
                $this->logger->info('OTP request successful.', ['base_url' => $baseUrl, 'response' => $response]);

                // Return a structured success response with the token
                return [
                    'status' => 'success',
                    'verificationToken' => new OtpVerificationToken(
                        referenceNo: (string) $response['referenceNo'],
                        usedApiUrl: $baseUrl,
                        platform: $platform,
                        createdAt: time(),
                        failedUrls: $failedUrls
                    ),
                    'originalResponse' => $response
                ];
            }

            $lastResponse = $response; // Always store the last response

            // Capture failure details
            $failedAttempts[] = [
                'base_url' => $baseUrl,
                'response' => $response
            ];

            $this->logger->warning('OTP request to URL failed, trying next if available.', [
                'base_url' => $baseUrl,
                'response' => $response
            ]);
            $failedUrls[] = $baseUrl;
        }

        // If the loop completes, all URLs have failed.
        $this->logger->warning('All OTP request URLs failed for subscriber.', [
            'subscriberId' => $subscriberId,
            'final_response' => $lastResponse
        ]);

        // Return the last failure with details
        return [
            'status' => 'error',
            'statusCode' => $lastResponse['statusCode'] ?? null,
            'statusDetail' => $lastResponse['statusDetail'] ?? null,
            'failedAttempts' => $failedAttempts,
            'finalResponse' => $lastResponse,
            'finalUrl' => $baseUrls[count($baseUrls) - 1]
        ];
    }

    /**
     * Verifies an OTP with the provider using a token from the getOtp call.
     *
     * @param array $verificationToken The data bundle from a successful getOtp call.
     * @param string $otp The 6-digit user-provided OTP
     * @return array The JSON response as an array
     */
    public function verifyOtp(array $verificationToken, string $otp): array
    {
        $baseUrl = $verificationToken['usedApiUrl'] ?? null;
        $referenceNo = $verificationToken['referenceNo'] ?? null;

        if (!$baseUrl || !$referenceNo) {
            $this->logger->critical('Invalid verification token provided to verifyOtp.', ['token' => $verificationToken]);
            return ['status' => 'error', 'message' => 'Invalid verification token.'];
        }

        $url = rtrim($baseUrl, '/') . '/verifyOtp.php';

        $payload = [
            'referenceNo' => $referenceNo,
            'otp' => $otp
        ];

        $response = $this->sendRequest($url, $payload);
        $responseStatus = $response['status'] ?? 'error';

        // That's how it is set up in the app for now
        if ($responseStatus === 'success') {
            return [
                'status' => 'success',
                'subscriptionStatus' => $response['subscriptionStatus'] ?? null,
                'originalResponse' => $response
            ];
        }

        return [
            'status' => $responseStatus,
            'originalResponse' => $response
        ];
    }

    /**
     * The core Guzzle request handler.
     */
    private function sendRequest(string $url, array $payload): array
    {
        try {
            $this->logger->info('Sending API request', ['url' => $url, 'payload' => $payload]);

            $options = [
                'json' => $payload
            ];

            // Forward test environment cookies to Mock API if present
            $cookies = [];
            foreach (['TEST_LOG_DIR', 'TEST_CLASS_NAME', 'APP_ENV'] as $cookieName) {
                if (isset($_COOKIE[$cookieName])) {
                    $cookies[] = "{$cookieName}=" . rawurlencode($_COOKIE[$cookieName]);
                }
            }
            if (!empty($cookies)) {
                $options['headers'] = [
                    'Cookie' => implode('; ', $cookies)
                ];
            }

            $response = $this->client->request('POST', $url, $options);

            $body = $response->getBody()->getContents();
            $decodedBody = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->critical("Invalid JSON response from API", ['body' => $body]);
                return ['status' => 'error', 'message' => 'Invalid JSON response from API'];
            }

            if (!\is_array($decodedBody)) {
                $this->logger->critical("API response is not an array", ['body' => $body, 'decoded' => $decodedBody]);
                return ['status' => 'error', 'message' => 'Unexpected API response format'];
            }

            return $decodedBody;

        } catch (RequestException $e) {
            $this->logger->critical("OTP API RequestException", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            // Return a standard error format
            return [
                'status' => 'error',
                'message' => 'API request failed',
                'detail' => $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->logger->critical("OTP System Error", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return [
                'status' => 'error',
                'message' => 'A system error occurred',
                'detail' => $e->getMessage()
            ];
        }
    }
}