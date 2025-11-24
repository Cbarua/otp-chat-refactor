<?php

use PHPUnit\Framework\TestCase;
use App\Service\CsrfService;
use App\Service\SessionService;
use PHPUnit\Framework\MockObject\MockObject;

class CsrfServiceTest extends TestCase
{
    private MockObject|SessionService $sessionMock;
    private CsrfService $csrfService;
    private const SESSION_KEY = 'csrf_token';

    protected function setUp(): void
    {
        $this->sessionMock = $this->createMock(SessionService::class);
        $this->csrfService = new CsrfService($this->sessionMock);
    }

    public function testGetTokenGeneratesNewTokenIfNoneExists(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with(self::SESSION_KEY)
            ->willReturn(false);

        $this->sessionMock->expects($this->once())
            ->method('set')
            ->with(self::SESSION_KEY, $this->isType('string'));

        $this->sessionMock->expects($this->once())
            ->method('get')
            ->with(self::SESSION_KEY)
            ->willReturn('generated-token');

        $token = $this->csrfService->getToken();
        $this->assertEquals('generated-token', $token);
    }

    public function testGetTokenReturnsExistingToken(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with(self::SESSION_KEY)
            ->willReturn(true);

        $this->sessionMock->expects($this->never())
            ->method('set');

        $this->sessionMock->expects($this->once())
            ->method('get')
            ->with(self::SESSION_KEY)
            ->willReturn('existing-token');

        $token = $this->csrfService->getToken();
        $this->assertEquals('existing-token', $token);
    }

    public function testValidateReturnsTrueForValidToken(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with(self::SESSION_KEY)
            ->willReturn(true);

        $this->sessionMock->expects($this->once())
            ->method('get')
            ->with(self::SESSION_KEY)
            ->willReturn('valid-token');

        $this->assertTrue($this->csrfService->validate('valid-token'));
    }

    public function testValidateReturnsFalseForInvalidToken(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with(self::SESSION_KEY)
            ->willReturn(true);

        $this->sessionMock->expects($this->once())
            ->method('get')
            ->with(self::SESSION_KEY)
            ->willReturn('valid-token');

        $this->assertFalse($this->csrfService->validate('invalid-token'));
    }

    public function testValidateReturnsFalseIfSessionHasNoToken(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with(self::SESSION_KEY)
            ->willReturn(false);

        $this->assertFalse($this->csrfService->validate('some-token'));
    }

    public function testValidateReturnsFalseForEmptyToken(): void
    {
        $this->assertFalse($this->csrfService->validate(''));
        $this->assertFalse($this->csrfService->validate(null));
    }

    public function testRegenerateToken(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('set')
            ->with(self::SESSION_KEY, $this->isType('string'));

        $token = $this->csrfService->regenerateToken();
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }
}
