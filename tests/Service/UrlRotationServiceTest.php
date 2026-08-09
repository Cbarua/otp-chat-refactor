<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\UrlRotationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UrlRotationServiceTest extends TestCase
{
    private string $dbPath;
    private UrlRotationService $service;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_rotation_' . uniqid() . '.sqlite';
        $this->service = new UrlRotationService($this->dbPath, new NullLogger());
    }

    protected function tearDown(): void
    {
        // Close connection implicitly by destroying service
        unset($this->service);
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testIncrementSubmissionCount(): void
    {
        $platform = 'test_platform';

        // Initial state: should not rotate (count 0)
        $this->assertFalse($this->service->shouldRotate($platform));

        // 1st submission
        $this->service->incrementSubmissionCount($platform);
        $this->assertFalse($this->service->shouldRotate($platform));

        // 2nd submission
        $this->service->incrementSubmissionCount($platform);
        $this->assertFalse($this->service->shouldRotate($platform));

        // 3rd submission -> SHOULD ROTATE
        $this->service->incrementSubmissionCount($platform);
        $this->assertTrue($this->service->shouldRotate($platform));

        // 4th submission -> Back to normal
        $this->service->incrementSubmissionCount($platform);
        $this->assertFalse($this->service->shouldRotate($platform));
    }

    public function testGetRotatedUrls(): void
    {
        $defaultUrls = [
            'http://api.example.com/fail1',
            'http://api.example.com/fail2',
            'http://api.example.com/success',
            'http://api.example.com/other'
        ];

        $priorityNames = ['fail2', 'fail1'];

        $rotated = $this->service->getRotatedUrls($defaultUrls, $priorityNames);

        // Expected order: fail2, fail1, success, other
        $this->assertStringContainsString('fail2', $rotated[0]);
        $this->assertStringContainsString('fail1', $rotated[1]);
        $this->assertStringContainsString('success', $rotated[2]);
        $this->assertStringContainsString('other', $rotated[3]);

        $this->assertCount(4, $rotated);
    }

    public function testGetRotatedUrlsWithNoMatches(): void
    {
        $defaultUrls = ['url1', 'url2'];
        $priorityNames = ['nomatch'];

        $rotated = $this->service->getRotatedUrls($defaultUrls, $priorityNames);

        $this->assertEquals($defaultUrls, $rotated);
    }
}
