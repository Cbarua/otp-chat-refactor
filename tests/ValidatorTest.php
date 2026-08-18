<?php
// tests/ValidatorTest.php

use PHPUnit\Framework\TestCase;
use App\Utils\Validator;
use Psr\Log\LoggerInterface;

class ValidatorTest extends TestCase
{
    private $carrierConfig;
    private $loggerMock;

    protected function setUp(): void
    {
        // Load the real carrier config for testing
        $this->carrierConfig = require __DIR__ . '/../config/carriers.php';
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    public function testValidSriLankanNumbers(): void
    {
        $this->loggerMock->expects($this->never())->method('warning');

        // Test a 'Dialog' number (Default range: 0.015 to 0.02)
        $phoneData = Validator::normalizePhone('0771234567', $this->carrierConfig, 'LK', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('tel:94771234567', $phoneData['telco_format']);
        $this->assertEquals('94771234567', $phoneData['capi_format']);
        $this->assertEquals('ideamart', $phoneData['platform']);
        $this->assertGreaterThanOrEqual(0.015, $phoneData['value']);
        $this->assertLessThanOrEqual(0.02, $phoneData['value']);

        // Test a 'Dialog' number starting with '7' (9 digits total)
        $phoneData = Validator::normalizePhone('771234567', $this->carrierConfig, 'LK', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('tel:94771234567', $phoneData['telco_format']);
        $this->assertEquals('94771234567', $phoneData['capi_format']);
        $this->assertEquals('ideamart', $phoneData['platform']);

        // Test a 'Airtel' number (Default range: 0.015 to 0.02)
        $phoneData = Validator::normalizePhone('0751234567', $this->carrierConfig, 'LK', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('tel:94751234567', $phoneData['telco_format']);
        $this->assertEquals('94751234567', $phoneData['capi_format']);
        $this->assertEquals('ideamart', $phoneData['platform']);
        $this->assertGreaterThanOrEqual(0.015, $phoneData['value']);
        $this->assertLessThanOrEqual(0.02, $phoneData['value']);

        // Test a 'Mobitel' number (Mobitel range: 0.0 to 0.01)
        $phoneData = Validator::normalizePhone('0711234567', $this->carrierConfig, 'LK', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('mspace', $phoneData['platform']);
        $this->assertGreaterThanOrEqual(0.0, $phoneData['value']);
        $this->assertLessThanOrEqual(0.01, $phoneData['value']);

        // Test a 'Hutch' number (Hutch range: 0.01 to 0.015)
        $phoneData = Validator::normalizePhone('0781234567', $this->carrierConfig, 'LK', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('ideamart', $phoneData['platform']);
        $this->assertGreaterThanOrEqual(0.01, $phoneData['value']);
        $this->assertLessThanOrEqual(0.015, $phoneData['value']);
    }

    public function testInvalidSriLankanNumbers(): void
    {
        $this->loggerMock->expects($this->exactly(4))
            ->method('warning')
            ->with($this->stringContains('Validation failed'));

        $this->assertNull(Validator::normalizePhone('12345', $this->carrierConfig, 'LK', $this->loggerMock));
        $this->assertNull(Validator::normalizePhone('077123456', $this->carrierConfig, 'LK', $this->loggerMock)); // Too short
        $this->assertNull(Validator::normalizePhone('07712345678', $this->carrierConfig, 'LK', $this->loggerMock)); // Too long
        $this->assertNull(Validator::normalizePhone('0881234567', $this->carrierConfig, 'LK', $this->loggerMock)); // Invalid prefix
    }

    public function testValidBangladeshNumbers(): void
    {
        $this->loggerMock->expects($this->never())->method('warning');

        // Test a 'Airtel' number
        $phoneData = Validator::normalizePhone('01612345678', $this->carrierConfig, 'BD', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('tel:8801612345678', $phoneData['telco_format']);
        $this->assertEquals('8801612345678', $phoneData['capi_format']);
        $this->assertEquals('bdapps', $phoneData['platform']);
        $this->assertEquals(0.01, $phoneData['value']);

        // Test a 'Robi' number
        $phoneData = Validator::normalizePhone('01812345678', $this->carrierConfig, 'BD', $this->loggerMock);
        $this->assertInstanceOf(\App\DTO\PhoneNumber::class, $phoneData);
        $this->assertEquals('bdapps', $phoneData['platform']);
        $this->assertEquals(0.01, $phoneData['value']);
    }

    public function testInvalidBangladeshNumbers(): void
    {
        $this->loggerMock->expects($this->exactly(4))
            ->method('warning')
            ->with($this->stringContains('Validation failed'));

        $this->assertNull(Validator::normalizePhone('12345', $this->carrierConfig, 'BD', $this->loggerMock));
        $this->assertNull(Validator::normalizePhone('0171234567', $this->carrierConfig, 'BD', $this->loggerMock)); // Too short
        $this->assertNull(Validator::normalizePhone('017123456789', $this->carrierConfig, 'BD', $this->loggerMock)); // Too long
        $this->assertNull(Validator::normalizePhone('02812345678', $this->carrierConfig, 'BD', $this->loggerMock)); // Invalid prefix
    }

    public function testUnconfiguredCountry(): void
    {
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Country code not configured'));

        $this->assertNull(Validator::normalizePhone('1234567890', $this->carrierConfig, 'XX', $this->loggerMock));
    }

    public function testOtpValidation(): void
    {
        $this->assertTrue(Validator::validateOtp('123456'));
        $this->assertTrue(Validator::validateOtp('999999'));
        $this->assertFalse(Validator::validateOtp('12345')); // Too short
        $this->assertFalse(Validator::validateOtp('1234567')); // Too long
        $this->assertFalse(Validator::validateOtp('abcdef')); // Not numeric
        $this->assertFalse(Validator::validateOtp('')); // Empty
    }
}