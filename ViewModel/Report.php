<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\ViewModel;

use MageOS\AbandonedCart\Cron\SendReminders;
use MageOS\AbandonedCart\Observer\RecordOrder;
use Magento\Backend\Model\Auth\Session;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Figures of the reminder report, per reminder, over the period picked in the page, history of the reminders, and
 * choices of its test e-mail.
 *
 * shortcut: revenue of websites with another base currency is summed as if in the default one; split the report
 * per website if a store needs it.
 */
class Report implements ArgumentInterface
{
    public const PERIODS = [7, 30, 90, 365];
    private const DEFAULT_PERIOD = 30;
    public const HISTORY_PAGE_SIZE = 50;

    /**
     * @param RequestInterface $request
     * @param ResourceConnection $resource
     * @param PriceCurrencyInterface $priceCurrency
     * @param ScopeConfigInterface $config
     * @param StoreManagerInterface $storeManager
     * @param Session $authSession
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResourceConnection $resource,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ScopeConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly Session $authSession
    ) {
    }

    /**
     * Store views a test e-mail can be sent for, by id
     *
     * @return array<int,string>
     */
    public function getStores(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            $website = $this->storeManager->getWebsite($store->getWebsiteId())->getName();
            $stores[(int) $store->getId()] = $website . ' / ' . $store->getName();
        }
        return $stores;
    }

    /**
     * E-mail of the admin user, where the test e-mail goes
     *
     * @return string
     */
    public function getAdminEmail(): string
    {
        return (string) $this->authSession->getUser()?->getEmail();
    }

    /**
     * Days covered by the report, from the "days" parameter when it is one of the periods offered
     *
     * @return int
     */
    public function getDays(): int
    {
        $days = (int) $this->request->getParam('days');
        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /**
     * One row per reminder sent in the period, then a "total" row
     *
     * @return array<int|string, array{sent: int, clicked: int, orders: int, revenue: float}>
     */
    public function getRows(): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('mageos_abandoned_cart_reminder'), [
                'step',
                'sent' => 'COUNT(*)',
                'clicked' => 'COUNT(clicked_at)',
                'orders' => 'COUNT(order_id)',
                'revenue' => 'COALESCE(SUM(base_order_total), 0)',
            ])
            ->where('sent_at >= ?', gmdate('Y-m-d H:i:s', time() - $this->getDays() * 86400))
            ->group('step')
            ->order('step');
        $rows = [];
        $total = ['sent' => 0, 'clicked' => 0, 'orders' => 0, 'revenue' => 0.0];
        foreach ($connection->fetchAll($select) as $row) {
            $figures = [
                'sent' => (int) $row['sent'],
                'clicked' => (int) $row['clicked'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
            ];
            $rows[(int) $row['step']] = $figures;
            $total = [
                'sent' => $total['sent'] + $figures['sent'],
                'clicked' => $total['clicked'] + $figures['clicked'],
                'orders' => $total['orders'] + $figures['orders'],
                'revenue' => $total['revenue'] + $figures['revenue'],
            ];
        }
        return $rows ? $rows + ['total' => $total] : [];
    }

    /**
     * Days after a reminder within which an order is credited to it, 0 for no limit
     *
     * @return int
     */
    public function getAttributionDays(): int
    {
        return (int) $this->config->getValue(RecordOrder::CONFIG_DAYS);
    }

    /**
     * Page of the history shown, from the "p" parameter
     *
     * @return int
     */
    public function getHistoryPage(): int
    {
        return max(1, (int) $this->request->getParam('p'));
    }

    /**
     * Reminders sent and reminders not sent as planned in the period, newest first, one page
     *
     * Fetches one row more than the page size, so the page can tell whether an older page exists.
     *
     * @return array<int, array{at: string, quote_id: int, store_id: int, step: int, reason: ?string,
     *     coupon_code: ?string, clicked_at: ?string, increment_id: ?string}>
     */
    public function getHistory(): array
    {
        $connection = $this->resource->getConnection();
        $since = gmdate('Y-m-d H:i:s', time() - $this->getDays() * 86400);
        $sent = $connection->select()
            ->from(['r' => $this->resource->getTableName('mageos_abandoned_cart_reminder')], [
                'at' => 'r.sent_at',
                'quote_id' => 'r.quote_id',
                'store_id' => 'r.store_id',
                'step' => 'r.step',
                'coupon_code' => 'r.coupon_code',
                'clicked_at' => 'r.clicked_at',
            ])
            ->joinLeft(
                ['o' => $this->resource->getTableName('sales_order')],
                'o.entity_id = r.order_id',
                ['increment_id' => 'o.increment_id']
            )
            // A reminder sent without its coupon is one line: its "no coupon" issue comes with it. There is at most
            // one per cart and reminder, as the reminder itself.
            ->joinLeft(
                ['i' => $this->resource->getTableName('mageos_abandoned_cart_issue')],
                'i.quote_id = r.quote_id AND i.step = r.step AND '
                . $connection->quoteInto('i.reason = ?', SendReminders::ISSUE_NO_COUPON),
                ['reason' => 'i.reason']
            )
            ->where('r.sent_at >= ?', $since);
        // UNION ALL matches columns by position: same order as $sent, "reason" last.
        $issues = $connection->select()
            ->from($this->resource->getTableName('mageos_abandoned_cart_issue'), [
                'at' => 'created_at',
                'quote_id',
                'store_id',
                'step',
                'coupon_code' => new Expression('NULL'),
                'clicked_at' => new Expression('NULL'),
                'increment_id' => new Expression('NULL'),
                'reason',
            ])
            ->where('created_at >= ?', $since)
            ->where('reason <> ?', SendReminders::ISSUE_NO_COUPON);
        $select = $connection->select();
        $select->union([$sent, $issues], Select::SQL_UNION_ALL);
        $select->order(['at DESC', 'quote_id DESC', 'step DESC']);
        $select->limit(self::HISTORY_PAGE_SIZE + 1, ($this->getHistoryPage() - 1) * self::HISTORY_PAGE_SIZE);
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'at' => (string) $row['at'],
                'quote_id' => (int) $row['quote_id'],
                'store_id' => (int) $row['store_id'],
                'step' => (int) $row['step'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'coupon_code' => $row['coupon_code'] === null ? null : (string) $row['coupon_code'],
                'clicked_at' => $row['clicked_at'] === null ? null : (string) $row['clicked_at'],
                'increment_id' => $row['increment_id'] === null ? null : (string) $row['increment_id'],
            ];
        }
        return $rows;
    }

    /**
     * Outcome of a history row: sent, skipped or failed
     *
     * @param string|null $reason
     * @return Phrase
     */
    public function outcome(?string $reason): Phrase
    {
        return match ($reason) {
            null => __('E-mail sent'),
            SendReminders::ISSUE_NO_COUPON => __('E-mail sent, without a coupon'),
            SendReminders::ISSUE_SEND_RETRY, SendReminders::ISSUE_SEND_GIVEN_UP => __('Sending failed'),
            default => __('Not sent'),
        };
    }

    /**
     * Why a reminder was not sent as planned, for the history
     *
     * @param string $reason
     * @return Phrase
     */
    public function reason(string $reason): Phrase
    {
        return match ($reason) {
            SendReminders::ISSUE_NOTHING_FOR_SALE => __('No product of the cart is for sale any more.'),
            SendReminders::ISSUE_ADDRESS_LIMIT => __('The address already got 3 reminders this week.'),
            SendReminders::ISSUE_SEND_RETRY => __('The e-mail could not be sent; it will be tried again.'),
            SendReminders::ISSUE_SEND_GIVEN_UP => __(
                'The e-mail could not be sent, for the last time; the reminder is passed over.'
            ),
            SendReminders::ISSUE_NO_COUPON => __(
                'The rule is inactive, expired, not for this website or customer group, or could not generate a code.'
            ),
            default => __('Unknown reason: %1', $reason),
        };
    }

    /**
     * Share of $part in $whole, as text
     *
     * @param int $part
     * @param int $whole
     * @return string
     */
    public function rate(int $part, int $whole): string
    {
        return $whole ? round(100 * $part / $whole, 1) . ' %' : '-';
    }

    /**
     * Amount in the default base currency
     *
     * @param float $amount
     * @return string
     */
    public function price(float $amount): string
    {
        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            (string) $this->config->getValue(Currency::XML_PATH_CURRENCY_BASE)
        );
    }
}
