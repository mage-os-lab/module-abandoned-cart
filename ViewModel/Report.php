<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\ViewModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Figures of the reminder report, per reminder, over the period picked in the page, and choices of its test e-mail.
 *
 * shortcut: revenue of websites with another base currency is summed as if in the default one; split the report
 * per website if a store needs it.
 */
class Report implements ArgumentInterface
{
    public const PERIODS = [7, 30, 90, 365];
    private const DEFAULT_PERIOD = 30;

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
