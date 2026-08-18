<?php
// tests/PhoneNumberTest.php

use PHPUnit\Framework\TestCase;
use App\DTO\PhoneNumber;

class PhoneNumberTest extends TestCase
{
    public function testInstantiateAndGetters(): void
    {
        $phone = new PhoneNumber(
            rawNumber: '0771234567',
            telcoFormat: 'tel:94771234567',
            capiFormat: '94771234567',
            platform: 'ideamart',
            value: 0.018,
            country: 'LK'
        );

        $this->assertEquals('0771234567', $phone->rawNumber);
        $this->assertEquals('tel:94771234567', $phone->telcoFormat);
        $this->assertEquals('94771234567', $phone->capiFormat);
        $this->assertEquals('ideamart', $phone->platform);
        $this->assertEquals(0.018, $phone->value);
        $this->assertEquals('LK', $phone->country);
    }

    public function testArrayAccessAndBackwardCompatibility(): void
    {
        $phone = new PhoneNumber(
            rawNumber: '0771234567',
            telcoFormat: 'tel:94771234567',
            capiFormat: '94771234567',
            platform: 'ideamart',
            value: 0.018,
            country: 'LK'
        );

        $this->assertTrue(isset($phone['telco_format']));
        $this->assertTrue(isset($phone['capi_format']));
        $this->assertTrue(isset($phone['platform']));
        $this->assertTrue(isset($phone['value']));

        $this->assertEquals('tel:94771234567', $phone['telco_format']);
        $this->assertEquals('94771234567', $phone['capi_format']);
        $this->assertEquals('ideamart', $phone['platform']);
        $this->assertEquals(0.018, $phone['value']);
    }

    public function testFromArrayFactory(): void
    {
        $data = [
            'raw_number' => '0771234567',
            'telco_format' => 'tel:94771234567',
            'capi_format' => '94771234567',
            'platform' => 'ideamart',
            'value' => 0.02,
            'country' => 'LK'
        ];

        $phone = PhoneNumber::fromArray($data);
        $this->assertEquals('tel:94771234567', $phone->telcoFormat);
        $this->assertEquals(0.02, $phone->value);
    }

    public function testJsonSerialization(): void
    {
        $phone = new PhoneNumber(
            rawNumber: '0771234567',
            telcoFormat: 'tel:94771234567',
            capiFormat: '94771234567',
            platform: 'ideamart',
            value: 0.018,
            country: 'LK'
        );

        $json = json_encode($phone);
        $decoded = json_decode($json, true);

        $this->assertEquals('tel:94771234567', $decoded['telco_format']);
        $this->assertEquals('94771234567', $decoded['capi_format']);
        $this->assertEquals('ideamart', $decoded['platform']);
    }

    public function testImmutableMutationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $phone = new PhoneNumber('0771234567', 'tel:94771234567', '94771234567', 'ideamart', 0.018);
        $phone['capi_format'] = 'changed';
    }
}
