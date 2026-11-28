<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use MageOS\AbandonedCart\Cron\SendReminders;
use MageOS\AbandonedCart\ViewModel\Report;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a reminder sent without its coupon is one line of the history, not two.
 */
class HistoryTest extends TestCase
{
    /** @var array<string, array<int, array<int|string, mixed>>> */
    private array $calls = [];

    /**
     * The reason "no coupon" comes with the reminder row, and is no separate issue row.
     */
    public function testReminderWithoutCouponIsOneLine(): void
    {
        $sent = $this->select('sent');
        $issues = $this->select('issues');
        $union = $this->select('union');
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls($sent, $issues, $union);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, string $value): string => str_replace('?', "'" . $value . "'", $text)
        );
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->report($resource)->getHistory();

        $joins = $this->calls['sent:joinLeft'];
        $issueJoin = array_values(array_filter(
            $joins,
            static fn (array $args): bool => $args[0] === ['i' => 'mageos_abandoned_cart_issue']
        ));
        $this->assertCount(1, $issueJoin);
        $this->assertStringContainsString("i.reason = '" . SendReminders::ISSUE_NO_COUPON . "'", $issueJoin[0][1]);
        $this->assertSame(['reason' => 'i.reason'], $issueJoin[0][2]);
        $this->assertContains(
            ['reason <> ?', SendReminders::ISSUE_NO_COUPON],
            array_map(static fn (array $args): array => array_slice($args, 0, 2), $this->calls['issues:where'])
        );

        // UNION ALL matches columns by position: both parts must list the same columns in the same order.
        $names = static fn (array $columns): array => array_map(
            static fn ($key, $value) => is_int($key) ? $value : $key,
            array_keys($columns),
            $columns
        );
        $sentColumns = array_merge(
            $names($this->calls['sent:from'][0][1]),
            ...array_map(static fn (array $args): array => $names($args[2]), $joins)
        );
        $this->assertSame($sentColumns, $names($this->calls['issues:from'][0][1]));
    }

    /**
     * The merged line reads "E-mail sent, without a coupon", and a reminder with its coupon stays "E-mail sent".
     */
    public function testOutcomeOfAReminderWithoutCoupon(): void
    {
        $report = $this->report($this->createStub(ResourceConnection::class));

        $this->assertSame('E-mail sent', (string) $report->outcome(null));
        $this->assertSame(
            'E-mail sent, without a coupon',
            (string) $report->outcome(SendReminders::ISSUE_NO_COUPON)
        );
    }

    /**
     * Select mock that records the calls made on it under $name
     *
     * @param string $name
     * @return Select
     */
    private function select(string $name): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'joinLeft', 'where', 'union', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $name, $method) {
                $this->calls[$name . ':' . $method][] = $args;
                return $select;
            });
        }
        return $select;
    }

    /**
     * Report view model on $resource
     *
     * @param ResourceConnection $resource
     * @return Report
     */
    private function report(ResourceConnection $resource): Report
    {
        return new Report(
            $this->createStub(RequestInterface::class),
            $resource,
            $this->createStub(PriceCurrencyInterface::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(Session::class)
        );
    }
}
