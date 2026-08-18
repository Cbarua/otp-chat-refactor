<?php
// tests/SessionServiceTest.php

use PHPUnit\Framework\TestCase;
use App\Service\SessionService;
use App\Enum\SessionKey;
use App\Enum\ApiStatus;

class SessionServiceTest extends TestCase
{
    private SessionService $session;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->session = new SessionService();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSetAndGetWithStringKey(): void
    {
        $this->session->set('test_key', 'test_value');
        $this->assertTrue($this->session->has('test_key'));
        $this->assertEquals('test_value', $this->session->get('test_key'));
    }

    public function testSetAndGetWithSessionKeyEnum(): void
    {
        $this->session->set(SessionKey::OTP_TOKEN, 'token_123');
        $this->assertTrue($this->session->has(SessionKey::OTP_TOKEN));
        $this->assertEquals('token_123', $this->session->get(SessionKey::OTP_TOKEN));
        $this->assertEquals('token_123', $_SESSION['otp_token']);
    }

    public function testUnsetAndRemoveWithEnum(): void
    {
        $this->session->set(SessionKey::PHONE_DATA, ['phone' => '123']);
        $this->assertTrue($this->session->has(SessionKey::PHONE_DATA));

        $this->session->remove(SessionKey::PHONE_DATA);
        $this->assertFalse($this->session->has(SessionKey::PHONE_DATA));
        $this->assertNull($this->session->get(SessionKey::PHONE_DATA));
    }

    public function testGetDefaultWhenKeyMissing(): void
    {
        $this->assertEquals('default_val', $this->session->get('missing_key', 'default_val'));
        $this->assertEquals([], $this->session->get(SessionKey::PHONE_DATA, []));
    }

    public function testAllReturnsSessionArray(): void
    {
        $this->session->set('a', 1);
        $this->session->set(SessionKey::VISITOR_ID, 'v_123');

        $all = $this->session->all();
        $this->assertEquals(1, $all['a']);
        $this->assertEquals('v_123', $all['visitor_id']);
    }

    public function testApiStatusEnumValues(): void
    {
        $this->assertEquals('success', ApiStatus::SUCCESS->value);
        $this->assertEquals('error', ApiStatus::ERROR->value);
        $this->assertEquals('user already registered', ApiStatus::ALREADY_REGISTERED->value);
        $this->assertEquals('temporary system error', ApiStatus::TEMPORARY_FAILURE->value);
        $this->assertEquals('maximum number of otp requests reached', ApiStatus::MAX_REQUESTS_REACHED->value);
    }
}
