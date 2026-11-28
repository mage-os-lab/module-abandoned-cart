<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use Error;
use MageOS\AbandonedCart\Cron\SendReminders;
use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;

/**
 * Tests that an error on one cart is recorded on that cart and does not stop the run.
 */
class ReminderErrorTest extends TestCase
{
    /**
     * A PHP error (not an exception) while preparing a cart's reminder is recorded as a failed sending, to retry.
     */
    public function testErrorOnACartIsRecordedAndDoesNotEscape(): void
    {
        $quote = $this->createStub(Quote::class);
        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getId')->willReturn(7);
        $quote->method('getData')->willReturnMap([
            ['abandoned_reminder_token', null, 'secret'],
            ['reminder_email', null, 'guest@example.com'],
            ['abandoned_reminder_failures', null, 0],
        ]);
        $quote->method('getAllVisibleItems')->willThrowException(new Error('Division by zero'));

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('insert')
            ->with('mageos_abandoned_cart_issue', $this->callback(
                fn (array $row) => $row['reason'] === SendReminders::ISSUE_SEND_RETRY
            ));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturn('token');

        $cron = (new ReflectionClass(SendReminders::class))->newInstanceWithoutConstructor();
        $dependencies = [
            'config' => $this->createStub(ScopeConfigInterface::class),
            'logger' => $this->createStub(LoggerInterface::class),
            'emulation' => $this->createStub(Emulation::class),
            'resource' => $resource,
            'reminderLink' => new ReminderLink(
                $this->createStub(CartRepositoryInterface::class),
                $resource,
                $encryptor
            ),
        ];
        // Reflection may initialize readonly properties from outside their class.
        foreach ($dependencies as $name => $value) {
            (new ReflectionProperty(SendReminders::class, $name))->setValue($cron, $value);
        }

        (new ReflectionMethod(SendReminders::class, 'remind'))->invoke($cron, $quote, 1, 0);
    }
}
