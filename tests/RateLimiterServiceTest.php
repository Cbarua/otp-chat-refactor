<?php

use PHPUnit\Framework\TestCase;
use App\Service\RateLimiterService;
use Psr\Log\LoggerInterface;

class RateLimiterServiceTest extends TestCase
{
    private string $dbPath;
    private RateLimiterService $rateLimiter;
    private $loggerMock;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_rate_limiter.db';
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->rateLimiter = new RateLimiterService($this->dbPath, $this->loggerMock);
    }

    protected function tearDown(): void
    {
        // Explicitly close connection if possible or just unlink
        unset($this->rateLimiter);
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testCheckAllowsRequestWithinLimit(): void
    {
        $key = 'test_key_1';
        $this->assertTrue($this->rateLimiter->check($key, 5, 60));
        $this->rateLimiter->increment($key);
        $this->assertTrue($this->rateLimiter->check($key, 5, 60));
    }

    public function testCheckBlocksRequestExceedingLimit(): void
    {
        $key = 'test_key_2';
        $maxAttempts = 2;

        $this->assertTrue($this->rateLimiter->check($key, $maxAttempts, 60));
        $this->rateLimiter->increment($key);

        $this->assertTrue($this->rateLimiter->check($key, $maxAttempts, 60));
        $this->rateLimiter->increment($key);

        // Now count is 2, check should return false
        $this->assertFalse($this->rateLimiter->check($key, $maxAttempts, 60));
    }

    public function testCheckResetsAfterWindowExpires(): void
    {
        $key = 'test_key_3';
        $maxAttempts = 1;
        $window = 1; // 1 second window

        $this->assertTrue($this->rateLimiter->check($key, $maxAttempts, $window));
        $this->rateLimiter->increment($key);

        $this->assertFalse($this->rateLimiter->check($key, $maxAttempts, $window));

        // Wait for window to expire
        sleep(2);

        $this->assertTrue($this->rateLimiter->check($key, $maxAttempts, $window));
    }

    public function testIncrementUpdatesCount(): void
    {
        $key = 'test_key_4';

        // Initial check creates the record with count 0
        $this->rateLimiter->check($key, 10, 60);

        $this->rateLimiter->increment($key);
        $this->rateLimiter->increment($key);

        // We can't easily inspect internal state without reflection or a getter, 
        // but we can verify behavior.
        // If we set limit to 2, next check should fail.

        $this->assertFalse($this->rateLimiter->check($key, 2, 60));
    }

    public function testInitializeDatabaseFailure(): void
    {
        $invalidPath = sys_get_temp_dir(); // Directory path causes open failure

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('DB Error'));

        new RateLimiterService($invalidPath, $this->loggerMock);
    }

    public function testCheckFailOpen(): void
    {
        // Create service with invalid path to force db to be null
        $service = new RateLimiterService(sys_get_temp_dir(), $this->loggerMock);

        // Should return true (allowed) when DB is down
        $this->assertTrue($service->check('key', 5, 60));

        // Increment should not throw
        $service->increment('key');
    }

    public function testDestructorClosesDatabase(): void
    {
        $dbPath = sys_get_temp_dir() . '/test_rate_limiter_destruct.db';
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        // Create service and let it go out of scope
        $service = new RateLimiterService($dbPath, $this->loggerMock);
        $service->check('test_key', 5, 60);

        // Explicitly call destructor
        unset($service);

        // Try to delete the file - should succeed if connection is closed
        $this->assertTrue(file_exists($dbPath));
        @unlink($dbPath);
        $this->assertFalse(file_exists($dbPath));
    }
}
