<?php
// src/Service/OtpApiService.php

namespace App\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use App\DTO\OtpVerificationToken;
use App\Enum\OtpRequestOutcome;
use App\Enum\OtpVerifyOutcome;

/**
 * Manages all communication with the third-party OTP provider, and is the only place that reads
 * the platform's vocabulary: replies are mapped to an outcome by statusCode, which means the same
 * on ideamart, mspace and bdapps (docs/provider_status_codes.md).
 */
class OtpApiService
{
    private const STATUS_SUCCESS = 'S1000';

    // Ideamart spells multi-word states with spaces, mspace with underscores.
    private const SUBSCRIBED_STATUSES = ['REGISTERED', 'INITIAL CHARGING PENDING', 'INITIAL_CHARGING_PENDING'];

    private array $apiConfig;
    private ClientInterface $client;
    private LoggerInterface $logger;
    private array $testCookies;

    /**
     * @param array $testCookies Cookies sent with every provider request (name => value); config/app.php
     *                           fills them only when TEST_HOOKS is on.
     */
    public function __construct(array $apiConfig, LoggerInterface $logger, ClientInterface $httpClient, array $testCookies = [])
    {
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
        $this->client = $httpClient;
        $this->testCookies = $testCookies;
    }

    /**
     * Requests an OTP from the platform's apps in order, skipping $excludeUrls, until one succeeds (app fallback).
     *
     * @param string $platform The platform identifier (e.g., 'ideamart', 'mspace').
     * @param string $subscriberId The 'tel:...' formatted number.
     * @param array $metaData Additional data for the API call.
     * @param array $excludeUrls Base URLs of apps not to try this time.
     * @return array 'outcome' (OtpRequestOutcome). Sent adds 'verificationToken' and 'originalResponse'; the other
     *               outcomes come from the last app's reply and add 'statusCode', 'statusDetail', 'failedAttempts',
     *               'alreadyRegisteredUrls', 'finalResponse' and 'finalUrl', unless no app could be tried ('message').
     */
    public function getOtp(string $platform, string $subscriberId, array $metaData, array $excludeUrls = []): array
    {
        $allBaseUrls = $this->apiConfig[$platform] ?? [];
        if (empty($allBaseUrls)) {
            $this->logger->critical('No API URLs configured for platform.', ['platform' => $platform]);
            return ['outcome' => OtpRequestOutcome::FAILED, 'message' => 'Url not found for platform.'];
        }

        // Filter out any URLs that should be excluded for this attempt
        $baseUrls = array_diff($allBaseUrls, $excludeUrls);
        if (empty($baseUrls)) {
            $this->logger->warning('All available API URLs for the platform were excluded.', [
                'platform' => $platform,
                'excluded_urls' => $excludeUrls
            ]);
            return ['outcome' => OtpRequestOutcome::FAILED, 'message' => 'No available API endpoints to try.'];
        }

        $lastResponse = [];
        $failedUrls = [];
        $failedAttempts = [];
        $alreadyRegisteredUrls = [];

        foreach ($baseUrls as $baseUrl) {
            $url = rtrim($baseUrl, '/') . '/getOtp.php';

            $payload = [
                'subscriberId' => $subscriberId,
                'applicationMetaData' => $metaData
            ];

            $response = $this->sendRequest($url, $payload);

            if (($response['statusCode'] ?? null) === self::STATUS_SUCCESS) {
                $this->logger->info('OTP request successful.', ['base_url' => $baseUrl, 'response' => $response]);

                // Return a structured success response with the token
                return [
                    'outcome' => OtpRequestOutcome::SENT,
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
            if ($this->requestOutcome($response) === OtpRequestOutcome::ALREADY_REGISTERED) {
                $alreadyRegisteredUrls[] = $baseUrl;
            }
        }

        // If the loop completes, all URLs have failed.
        $this->logger->warning('All OTP request URLs failed for subscriber.', [
            'subscriberId' => $subscriberId,
            'final_response' => $lastResponse
        ]);

        // Return the last failure with details
        return [
            'outcome' => $this->requestOutcome($lastResponse),
            'statusCode' => $lastResponse['statusCode'] ?? null,
            'statusDetail' => $lastResponse['statusDetail'] ?? null,
            'failedAttempts' => $failedAttempts,
            'alreadyRegisteredUrls' => $alreadyRegisteredUrls,
            'finalResponse' => $lastResponse,
            'finalUrl' => $baseUrls[count($baseUrls) - 1]
        ];
    }

    /**
     * Verifies an OTP with the app that issued the token.
     *
     * @param OtpVerificationToken $verificationToken The token from a successful getOtp call.
     * @param string $otp The 6-digit user-provided OTP.
     * @return array 'outcome' (OtpVerifyOutcome) and the app's reply as 'originalResponse', or 'message' when the
     *               token is unusable.
     */
    public function verifyOtp(OtpVerificationToken $verificationToken, string $otp): array
    {
        $baseUrl = $verificationToken->usedApiUrl;
        $referenceNo = $verificationToken->referenceNo;

        if (!$baseUrl || !$referenceNo) {
            $this->logger->critical('Invalid verification token provided to verifyOtp.', ['token' => $verificationToken]);
            return ['outcome' => OtpVerifyOutcome::PROVIDER_ERROR, 'message' => 'Invalid verification token.'];
        }

        $url = rtrim($baseUrl, '/') . '/verifyOtp.php';

        $payload = [
            'referenceNo' => $referenceNo,
            'otp' => $otp
        ];

        $response = $this->sendRequest($url, $payload);

        return ['outcome' => $this->verifyOutcome($response), 'originalResponse' => $response];
    }

    /**
     * Maps the last app's reply to an OTP request that did not succeed.
     */
    private function requestOutcome(array $response): OtpRequestOutcome
    {
        return match ($response['statusCode'] ?? null) {
            'E1351' => OtpRequestOutcome::ALREADY_REGISTERED,
            'E1603' => OtpRequestOutcome::TEMPORARY_FAILURE,
            'E1853' => OtpRequestOutcome::MAX_REQUESTS,
            default => OtpRequestOutcome::FAILED,
        };
    }

    /**
     * Maps an app's reply to an OTP verification.
     */
    private function verifyOutcome(array $response): OtpVerifyOutcome
    {
        return match ($response['statusCode'] ?? null) {
            self::STATUS_SUCCESS => \in_array($response['subscriptionStatus'] ?? null, self::SUBSCRIBED_STATUSES, true)
                ? OtpVerifyOutcome::VERIFIED
                : OtpVerifyOutcome::NOT_SUBSCRIBED,
            'E1850' => OtpVerifyOutcome::INVALID,
            'E1851' => OtpVerifyOutcome::EXPIRED,
            'E1852' => OtpVerifyOutcome::ATTEMPTS_EXHAUSTED,
            'E1854' => OtpVerifyOutcome::NOT_FOUND,
            default => OtpVerifyOutcome::PROVIDER_ERROR,
        };
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
            if (!empty($this->testCookies)) {
                $options['headers'] = [
                    'Cookie' => http_build_query($this->testCookies, '', '; ', PHP_QUERY_RFC3986)
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