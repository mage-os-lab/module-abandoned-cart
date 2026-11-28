<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use MageOS\AbandonedCart\Cron\PurgeHistory;
use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the attribution window of orders and the purge of the reminder history.
 */
class HistoryRetentionTest extends TestCase
{
    /** @var array<int, array{string, array<string, mixed>, array<int|string, mixed>}> */
    private array $updates = [];

    /** @var array<int, array{string, array<int|string, mixed>}> */
    private array $deletes = [];

    /**
     * An order is credited only to a last reminder sent within the window.
     */
    public function testOrderIsCreditedOnlyWithinTheWindow(): void
    {
        $this->link()->recordOrder(12, 34, 56.7, 7);

        [, $data, $where] = $this->updates[0];
        $this->assertSame(['order_id' => 34, 'base_order_total' => 56.7], $data);
        $this->assertSame(99, $where['entity_id = ?']);
        $this->assertEqualsWithDelta(time() - 7 * 86400, strtotime($where['sent_at >= ?'] . ' UTC'), 5);
    }

    /**
     * With a window of 0 days the order is credited however late it comes, as before 1.5.
     */
    public function testNoWindowCreditsAnyLateOrder(): void
    {
        $this->link()->recordOrder(12, 34, 56.7);

        $this->assertSame(['entity_id = ?' => 99], $this->updates[0][2]);
    }

    /**
     * The purge deletes the reminders and the history older than the retention, and nothing else.
     */
    public function testPurgeDeletesRowsOlderThanTheRetention(): void
    {
        $this->assertSame(2, $this->purge('30')->execute());

        $this->assertSame(
            ['mageos_abandoned_cart_reminder', 'mageos_abandoned_cart_issue'],
            array_column($this->deletes, 0)
        );
        $cutoff = $this->deletes[0][1]['sent_at < ?'];
        $this->assertEqualsWithDelta(time() - 30 * 86400, strtotime($cutoff . ' UTC'), 5);
        $this->assertSame($cutoff, $this->deletes[1][1]['created_at < ?']);
    }

    /**
     * A retention of 0 days keeps everything.
     */
    public function testZeroRetentionKeepsEverything(): void
    {
        $this->assertSame(0, $this->purge('0')->execute());
        $this->assertSame([], $this->deletes);
    }

    /**
     * Purge cron with the retention setting $days.
     *
     * @param string $days
     * @return PurgeHistory
     */
    private function purge(string $days): PurgeHistory
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($days);
        return new PurgeHistory($config, $this->resource());
    }

    /**
     * Reminder link service on a database whose last reminder of any cart is 99.
     *
     * @return ReminderLink
     */
    private function link(): ReminderLink
    {
        return new ReminderLink(
            $this->createStub(CartRepositoryInterface::class),
            $this->resource(),
            $this->createStub(EncryptorInterface::class)
        );
    }

    /**
     * Resource connection that records updates and deletes, each delete removing one row.
     *
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('99');
        $connection->method('update')->willReturnCallback(function (string $table, array $data, array $where): int {
            $this->updates[] = [$table, $data, $where];
            return 1;
        });
        $connection->method('delete')->willReturnCallback(function (string $table, array $where): int {
            $this->deletes[] = [$table, $where];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }
}
