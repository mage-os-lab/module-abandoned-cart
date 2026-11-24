<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use MageOS\AbandonedCart\Cron\SendReminders;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests when a reminder whose sending failed is tried again.
 */
class RetryTest extends TestCase
{
    /**
     * Each failure waits the next delay, later each time, and counts.
     */
    public function testFailedReminderWaitsLongerEachTime(): void
    {
        $previous = time();
        foreach (SendReminders::RETRY_HOURS as $failures => $hours) {
            $retry = $this->cron()->retry($failures);

            $this->assertSame($failures + 1, $retry['abandoned_reminder_failures']);
            $retryAt = strtotime($retry['abandoned_reminder_retry_at'] . ' UTC');
            $this->assertEqualsWithDelta(time() + $hours * 3600, $retryAt, 5);
            $this->assertGreaterThan($previous, $retryAt);
            $previous = $retryAt;
        }
    }

    /**
     * Once every delay is used up, nothing keeps the reminder due: it is passed over.
     */
    public function testReminderIsPassedOverAfterTheLastTry(): void
    {
        $this->assertSame([], $this->cron()->retry(count(SendReminders::RETRY_HOURS)));
    }

    /**
     * Cron without its dependencies: the retry rule uses none of them.
     *
     * @return SendReminders
     */
    private function cron(): SendReminders
    {
        return (new ReflectionClass(SendReminders::class))->newInstanceWithoutConstructor();
    }
}
