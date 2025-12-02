<?php

class MockApiController
{
    private string $logFile;
    private array $requestInput = [];

    public function __construct()
    {
        $this->logFile = __DIR__ . '/../../logs/mock_api.log';
    }

    public function handleRequest(string $gateway, string $action): void
    {
        // Capture input once
        $rawInput = file_get_contents('php://input');
        $this->requestInput = json_decode($rawInput, true) ?? [];

        if ($action === 'getOtp') {
            $this->handleGetOtp($gateway, $this->requestInput);
        } elseif ($action === 'verifyOtp') {
            $this->handleVerifyOtp($this->requestInput);
        } else {
            $this->sendResponse(404, ['status' => 'error', 'message' => 'Action not found']);
        }
    }

    private function handleGetOtp(string $gateway, array $input): void
    {
        // Dynamic Gateway Logic
        // If gateway name contains "fail" (case-insensitive), simulate failure.
        // Otherwise, simulate success.

        if (stripos($gateway, 'fail') !== false) {
            $this->sendResponse(200, [
                'statusCode' => 'E1000',
                'statusDetail' => 'Mock API Error (Simulated Failure for ' . $gateway . ')'
            ]);
        } else {
            $this->sendResponse(200, [
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
                'referenceNo' => 'mock-ref-' . uniqid(),
            ]);
        }
    }

    private function handleVerifyOtp(array $input): void
    {
        $otp = $input['otp'] ?? '';

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
