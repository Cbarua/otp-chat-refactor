<?php

class MockApiController
{
    private string $logFile;
    private array $requestInput = [];

    public function __construct()
    {
        if (isset($_COOKIE['TEST_LOG_DIR']) && isset($_COOKIE['APP_ENV']) && $_COOKIE['APP_ENV'] === 'testing') {
            $testClass = $_COOKIE['TEST_CLASS_NAME'] ?? 'UnknownTest';
            $dir = $_COOKIE['TEST_LOG_DIR'] . '/' . $testClass;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $this->logFile = $dir . '/mock_api.log';
        } else {
            $this->logFile = __DIR__ . '/../../logs/mock_api.log';
        }
    }

    public function handleRequest(string $gateway, string $action): void
    {
        // Capture input once
        $rawInput = file_get_contents('php://input');
        $this->requestInput = json_decode($rawInput, true) ?? [];

        if ($action === 'getOtp') {
            $this->handleGetOtp($gateway, $this->requestInput);
        } elseif ($action === 'verifyOtp') {
            $this->handleVerifyOtp($this->requestInput, $gateway);
        } else {
            $this->sendResponse(404, ['status' => 'error', 'message' => 'Action not found']);
        }
    }

    private function handleGetOtp(string $gateway, array $input): void
    {
        // Dynamic Gateway Logic
        // If gateway name contains "fail" (case-insensitive), simulate failure.
        // If gateway name contains "testapi" (case-insensitive), send the response to the test API.
        // Otherwise, simulate success.

        if (stripos($gateway, 'fail') !== false) {
            $this->sendResponse(200, [
                'statusCode' => 'E1000',
                'statusDetail' => 'Mock API Error (Simulated Failure for ' . $gateway . ')'
            ]);
        } elseif (stripos($gateway, 'testapi') !== false) {
            // send to the test API
            $url = 'http://telco.api/otp/request';
            $input = ['applicationId' => 'APP_12345',
                'password' => 'PASS_12345',
                'version' => '1.0'
            ] + $input;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            $this->sendResponse(200, json_decode($response, true));
        } else {
            $this->sendResponse(200, [
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
                'referenceNo' => 'mock-ref-' . uniqid(),
            ]);
        }
    }

    private function handleVerifyOtp(array $input, string $gateway = ''): void
    {
        $otp = $input['otp'] ?? '';

        if (stripos($gateway, 'testapi') !== false) {
            // send to the test API
            $url = 'http://telco.api/otp/verify';
            $input = ['applicationId' => 'APP_12345',
                'password' => 'PASS_12345',
                'version' => '1.0'
            ] + $input;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);

            // old otp api use this to set status
            $responseArray = json_decode($response, true);
            $statusCode = $responseArray['statusCode'] ?? '';
            $responseArray['status'] = $statusCode === 'S1000' ? 'success' : $responseArray['statusDetail'];
            curl_close($ch);
            $this->sendResponse(200, $responseArray);
        }

        if ($otp === '999999') {
            $this->sendResponse(200, [
                'status' => 'success',
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
                'subscriptionStatus' => 'REGISTERED'
            ]);
        } elseif ($otp === '000000') {
            // Simulate a system error during verification to trigger fallback
            $this->sendResponse(200, [
                'status' => 'error',
                'statusCode' => 'E9999',
                'statusDetail' => 'Simulated System Error'
            ]);
        } elseif ($otp === '777777') {
            // Simulate expired reference number
            $this->sendResponse(200, [
                'status' => 'OTP request has being expired',
                'statusCode' => 'E1851',
                'statusDetail' => 'OTP request has being expired'
            ]);
        } else {
            $this->sendResponse(200, [
                'status' => 'Invalid OTP',
                'statusCode' => 'E1001',
                'statusDetail' => 'Invalid OTP'
            ]);
        }
    }

    private function sendResponse(int $httpCode, array $data): void
    {
        // Log the transaction before exiting
        $this->logTransaction($httpCode, $data);

        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function logTransaction(int $httpCode, array $responseData): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown URI';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'Unknown Method';

        $requestBody = json_encode($this->requestInput, JSON_PRETTY_PRINT);
        $responseBody = json_encode($responseData, JSON_PRETTY_PRINT);

        $logEntry = <<<LOG
================================================================================
[$timestamp] Request Processed
================================================================================
URI: $uri
Method: $method
--------------------------------------------------------------------------------
[REQUEST BODY]
$requestBody
--------------------------------------------------------------------------------
[RESPONSE - $httpCode]
$responseBody
================================================================================

LOG;

        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
}
