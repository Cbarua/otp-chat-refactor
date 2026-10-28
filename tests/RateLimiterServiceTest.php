<?php

use PHPUnit\Framework\TestCase;
use App\Service\RateLimiterService;
use Psr\Log\LoggerInterface;

class RateLimiterServiceTest extends TestCase
{
    private \SQLite3 $db;
    private RateLimiterService $rateLimiter;
    private $loggerMock;

    protected function setUp(): void
    {
        $this->db = new \SQLite3(':memory:');
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->rateLimiter = new RateLimiterService($this->db, $this->loggerMock);
    }

    protected function tearDown(): void
    {
        $this->db->close();
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

    public function testResetAtIsStoredAsText(): void
    {
        $key = 'test_format_key';
        $this->rateLimiter->check($key, 5, 60);

        $resetAt = $this->db->querySingle("SELECT reset_at FROM rate_limits WHERE key = '$key'");

        // Assert it is a string and looks like a date
        $this->assertIsString($resetAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $resetAt);
    }

    public function testClearRemovesKey(): void
    {
        $key = 'test_clear_key';
        $this->rateLimiter->check($key, 5, 60);
        $this->rateLimiter->increment($key);

        // Verify it exists
        $count = $this->db->querySingle("SELECT count(*) FROM rate_limits WHERE key = '$key'");
        $this->assertEquals(1, $count);

        // Clear it
        $this->rateLimiter->clear($key);

        // Verify it's gone
        $count = $this->db->querySingle("SELECT count(*) FROM rate_limits WHERE key = '$key'");
        $this->assertEquals(0, $count);
    }

    public function testBlockAndGetRemainingSeconds(): void
    {
        $key = 'otp_max_requests:94771234567';

        // Initially remaining seconds is 0
        $this->assertEquals(0, $this->rateLimiter->getRemainingSeconds($key));

        // Block key for 3600 seconds
        $this->rateLimiter->block($key, 3600);

        // Remaining seconds should be positive (around 3600)
        $remaining = $this->rateLimiter->getRemainingSeconds($key);
        $this->assertGreaterThan(3590, $remaining);
        $this->assertLessThanOrEqual(3600, $remaining);
    }
}
