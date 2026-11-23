<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Cron;

use Exception;
use MageOS\AbandonedCart\Model\Source\Recipients;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Newsletter\Model\Subscriber;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\ResourceModel\Quote\Collection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends up to three reminder e-mails per abandoned cart, each with its own delay and template, website by website.
 *
 * Guest carts are reminded once the e-mail typed at checkout is saved on their address (after the shipping step).
 * Every e-mail carries a link, and a one-click List-Unsubscribe header, that stops the reminders for its address.
 */
class SendReminders
{
    private const CONFIG = 'mageos_abandoned_cart/';
    private const STEPS = 3;

    /** Carts left longer than this are never reminded, so enabling the module does not mail years-old carts. */
    private const MAX_AGE_HOURS = 720;

    /**
     * @param CollectionFactory $quoteCollection
     * @param ScopeConfigInterface $config
     * @param TransportBuilder $transportBuilder
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     * @param UrlInterface $url
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param Image $image
     * @param ItemResolverInterface $itemResolver
     * @param PriceCurrencyInterface $priceCurrency
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly CollectionFactory $quoteCollection,
        private readonly ScopeConfigInterface $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly Image $image,
        private readonly ItemResolverInterface $itemResolver,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Send the reminders that are due, with the settings of each website
     *
     * A cart gets at most one reminder per run, and reminder n+1 waits the gap between the two delays after
     * reminder n, so a cart found late (cron down) still gets its reminders spaced out.
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int) $website->getId();
            if (!$this->websiteValue('general/enabled', $websiteId)) {
                continue;
            }
            $storeIds = array_keys(array_filter(
                $this->storeManager->getStores(),
                fn ($store) => (int) $store->getWebsiteId() === $websiteId
            ));
            $previousDelay = 0;
            $sent = 0;
            foreach (range(1, self::STEPS) as $step) {
                $delay = (int) $this->websiteValue("reminder$step/delay_hours", $websiteId);
                // A reminder that is off, or not later than the previous one, is skipped and the next one moves up.
                if ($delay <= $previousDelay) {
                    continue;
                }
                foreach ($this->dueQuotes($websiteId, $storeIds, $sent, $delay, $delay - $previousDelay) as $quote) {
                    $this->remind($quote, $step, $sent);
                }
                $previousDelay = $delay;
                $sent++;
            }
        }
    }

    /**
     * Send reminder $step to the cart and record it
     *
     * A cart with nothing left to buy (products disabled or out of stock) gets the step recorded without an
     * e-mail, so it does not come back at every run and hold a place in the batch.
     *
     * @param Quote $quote
     * @param int $step
     * @param int $sent
     */
    private function remind(Quote $quote, int $step, int $sent): void
    {
        $storeId = (int) $quote->getStoreId();
        $token = (string) $quote->getData('abandoned_reminder_token') ?: bin2hex(random_bytes(32));
        $link = ['_scope' => $storeId, '_nosid' => true, '_query' => ['id' => $quote->getId(), 'token' => $token]];
        $items = [];
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $items = $this->items($quote);
            if ($items) {
                $this->send($quote, $step, $items, [
                    'cart_url' => $this->url->getUrl('abandonedcart/reminder/restore', $link),
                    'unsubscribe_url' => $this->url->getUrl('abandonedcart/reminder/unsubscribe', $link),
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Abandoned cart reminder failed for quote ' . $quote->getId(), ['exception' => $e]);
            return;
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        $connection = $this->resource->getConnection();
        // updated_at is set to itself: MySQL would otherwise bump it and restart the delays of the next reminder.
        $connection->update(
            $this->resource->getTableName('quote'),
            [
                'abandoned_reminder_count' => $sent + 1,
                'abandoned_reminder_sent_at' => gmdate('Y-m-d H:i:s'),
                'abandoned_reminder_token' => $token,
                'updated_at' => new Expression('updated_at'),
            ],
            ['entity_id = ?' => $quote->getId()]
        );
        if ($items) {
            $connection->insert(
                $this->resource->getTableName('mageos_abandoned_cart_reminder'),
                ['quote_id' => $quote->getId(), 'store_id' => $storeId, 'step' => $step]
            );
        }
    }

    /**
     * Send the e-mail of reminder $step
     *
     * @param Quote $quote
     * @param int $step
     * @param array<int,array<string,mixed>> $items
     * @param array<string,string> $urls
     */
    private function send(Quote $quote, int $step, array $items, array $urls): void
    {
        $storeId = (int) $quote->getStoreId();
        $transport = $this->transportBuilder
            ->setTemplateIdentifier(
                $this->config->getValue(self::CONFIG . "reminder$step/template", ScopeInterface::SCOPE_STORE, $storeId)
            )
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars($urls + [
                'customer_name' => $this->customerName($quote),
                'items' => $items,
                'subtotal' => $this->price($quote, array_sum(array_column($items, 'amount'))),
            ])
            ->setFromByScope('sales', $storeId)
            ->addTo((string) $quote->getData('reminder_email'))
            ->getTransport();
        $message = $transport->getMessage();
        // RFC 8058 one-click unsubscribe, which Gmail and Yahoo expect from bulk senders.
        if ($message instanceof EmailMessage) {
            $message->getSymfonyMessage()->getHeaders()
                ->addTextHeader('List-Unsubscribe', '<' . $urls['unsubscribe_url'] . '>')
                ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $transport->sendMessage();
    }

    /**
     * Active carts of the website unchanged for $delay hours that got $sent reminders, the last one $gap hours ago
     *
     * The recipient is the customer's e-mail or, for a guest, the one saved on a cart address.
     *
     * @param int $websiteId
     * @param int[] $storeIds
     * @param int $sent
     * @param int $delay
     * @param int $gap
     * @return Collection
     */
    private function dueQuotes(int $websiteId, array $storeIds, int $sent, int $delay, int $gap): Collection
    {
        $quotes = $this->quoteCollection->create();
        $connection = $quotes->getConnection();
        $email = new Expression(sprintf(
            "COALESCE(NULLIF(main_table.customer_email, ''), (%s))",
            $connection->select()
                ->from(['qa' => $quotes->getTable('quote_address')], ['email'])
                ->where('qa.quote_id = main_table.entity_id')
                ->where("qa.email IS NOT NULL AND qa.email <> ''")
                ->limit(1)
        ));
        $quotes->addFieldToFilter('is_active', ['eq' => 1])
            ->addFieldToFilter('store_id', ['in' => $storeIds])
            ->addFieldToFilter('items_count', ['gt' => 0])
            ->addFieldToFilter('abandoned_reminder_count', ['eq' => $sent])
            ->addFieldToFilter('updated_at', ['lt' => $this->hoursAgo($delay)])
            ->addFieldToFilter('updated_at', ['gt' => $this->hoursAgo(self::MAX_AGE_HOURS)])
            ->setPageSize(200);
        if ($sent > 0) {
            $quotes->addFieldToFilter('abandoned_reminder_sent_at', ['lt' => $this->hoursAgo($gap)]);
        }
        $minSubtotal = (float) $this->websiteValue('general/min_subtotal', $websiteId);
        if ($minSubtotal > 0) {
            $quotes->addFieldToFilter('base_subtotal', ['gteq' => $minSubtotal]);
        }
        $select = $quotes->getSelect()
            ->columns(['reminder_email' => $email])
            ->where("$email IS NOT NULL")
            ->where('NOT EXISTS ?', $connection->select()
                ->from(['optout' => $quotes->getTable('mageos_abandoned_cart_optout')], [new Expression('1')])
                ->where("optout.email = $email"));
        if ($this->websiteValue('general/recipients', $websiteId) !== Recipients::ALL) {
            $select->where('EXISTS ?', $connection->select()
                ->from(['ns' => $quotes->getTable('newsletter_subscriber')], [new Expression('1')])
                ->join(['ns_store' => $quotes->getTable('store')], 'ns_store.store_id = ns.store_id', [])
                ->where("ns.subscriber_email = $email")
                ->where('ns.subscriber_status = ?', Subscriber::STATUS_SUBSCRIBED)
                ->where('ns_store.website_id = ?', $websiteId));
        }
        return $quotes;
    }

    /**
     * Setting of the module at website scope
     *
     * @param string $path
     * @param int $websiteId
     * @return mixed
     */
    private function websiteValue(string $path, int $websiteId): mixed
    {
        return $this->config->getValue(self::CONFIG . $path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /**
     * Customer name, or the name on the address for a guest
     *
     * @param Quote $quote
     * @return string
     */
    private function customerName(Quote $quote): string
    {
        $name = trim($quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname());
        $address = $quote->getShippingAddress();
        return $name ?: trim($address->getFirstname() . ' ' . $address->getLastname());
    }

    /**
     * Items still for sale, escaped because {{var}} inside {{for}} is output raw by ForDirective
     *
     * Runs under the emulation of the cart's store, for its stock, image sizes and media URL.
     *
     * @param Quote $quote
     * @return array<int, array{name: string, qty: float, price: string, image_url: string, amount: float}>
     */
    private function items(Quote $quote): array
    {
        $items = [];
        /** @var Item $item */
        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item->getProduct()->isSalable()) {
                continue;
            }
            $amount = (float) ($item->getRowTotalInclTax() ?: $item->getRowTotal());
            /** @var Product $product */
            $product = $this->itemResolver->getFinalProduct($item);
            $items[] = [
                'name' => $this->escaper->escapeHtml((string) $item->getName()),
                'qty' => (float) $item->getQty(),
                'price' => $this->escaper->escapeHtml($this->price($quote, $amount)),
                'image_url' => $this->escaper->escapeUrl(
                    $this->image->init($product, 'cart_page_product_thumbnail')->getUrl()
                ),
                'amount' => $amount,
            ];
        }
        return $items;
    }

    /**
     * Amount in the cart's currency, as text
     *
     * @param Quote $quote
     * @param float $amount
     * @return string
     */
    private function price(Quote $quote, float $amount): string
    {
        return $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $quote->getStoreId(),
            $quote->getQuoteCurrencyCode()
        );
    }

    /**
     * UTC date $hours hours ago, in the format of the quote timestamps
     *
     * @param int $hours
     * @return string
     */
    private function hoursAgo(int $hours): string
    {
        return gmdate('Y-m-d H:i:s', time() - $hours * 3600);
    }
}
