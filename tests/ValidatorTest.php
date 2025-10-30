<?php
// tests/ValidatorTest.php

use PHPUnit\Framework\TestCase;
use App\Utils\Validator;

class ValidatorTest extends TestCase
{
    private $carrierConfig;

    protected function setUp(): void
    {
        // Load the real carrier config for testing
        $this->carrierConfig = require __DIR__ . '/../config/carriers.php';
    }

    public function testValidSriLankanNumbers()
    {
        // Test a 'default' (Dialog/Airtel) number
        $phoneData = Validator::normalizePhone('0771234567', $this->carrierConfig, 'LK');
        $this->assertIsArray($phoneData);
        $this->assertEquals('tel:94771234567', $phoneData['telco_format']);
        $this->assertEquals('94771234567', $phoneData['capi_format']);
        $this->assertEquals('ideamart', $phoneData['platform']);
        $this->assertEquals(0.02, $phoneData['value']);

        // Test a 'Mobitel' number
        $phoneData = Validator::normalizePhone('0711234567', $this->carrierConfig, 'LK');
        $this->assertEquals('mspace', $phoneData['platform']);
        $this->assertEquals(0.01, $phoneData['value']);

        // Test a 'Hutch' number
        $phoneData = Validator::normalizePhone('0781234567', $this->carrierConfig, 'LK');
        $this->assertEquals('ideamart', $phoneData['platform']);
        $this->assertEquals(0.015, $phoneData['value']);
    }

    public function testInvalidSriLankanNumbers()
    {
        $this->assertNull(Validator::normalizePhone('12345', $this->carrierConfig, 'LK'));
        $this->assertNull(Validator::normalizePhone('077123456', $this->carrierConfig, 'LK')); // Too short
        $this->assertNull(Validator::normalizePhone('0881234567', $this->carrierConfig, 'LK')); // Invalid prefix
    }

    public function testOtpValidation()
    {
        $this->assertTrue(Validator::validateOtp('123456'));
        $this->assertTrue(Validator::validateOtp('999999'));
        $this->assertFalse(Validator::validateOtp('12345')); // Too short
        $this->assertFalse(Validator::validateOtp('1234567')); // Too long
        $this->assertFalse(Validator::validateOtp('abcdef')); // Not numeric
        $this->assertFalse(Validator::validateOtp('')); // Empty
    }
}