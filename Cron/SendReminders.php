<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Cron;

use Exception;
use MageOS\AbandonedCart\Model\ReminderLink;
use MageOS\AbandonedCart\Model\ReminderMail;
use MageOS\AbandonedCart\Model\Source\Recipients;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
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
 * Guest carts are reminded once the e-mail typed at checkout is saved on the cart or on one of its addresses.
 * Every e-mail carries a link, and a one-click List-Unsubscribe header, that stops the reminders for its address.
 * An address gets at most MAX_PER_ADDRESS reminders a week whatever the number of carts, so carts made up with
 * someone else's address cannot flood it. A reminder whose sending fails is tried again after each of RETRY_HOURS,
 * then passed over.
 */
class SendReminders
{
    private const CONFIG = 'mageos_abandoned_cart/';
    private const STEPS = 3;

    /** Carts left longer than this are never reminded, so enabling the module does not mail years-old carts. */
    private const MAX_AGE_HOURS = 720;

    private const MAX_PER_ADDRESS = 3;
    private const ADDRESS_WINDOW_HOURS = 168;

    /** Hours to wait before each new try of a reminder whose sending failed; after the last one it is passed over. */
    public const RETRY_HOURS = [1, 6];

    /**
     * @param CollectionFactory $quoteCollection
     * @param ScopeConfigInterface $config
     * @param LoggerInterface $logger
     * @param UrlInterface $url
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param ItemResolverInterface $itemResolver
     * @param PriceCurrencyInterface $priceCurrency
     * @param ResourceConnection $resource
     * @param ReminderLink $reminderLink
     * @param ReminderMail $mail
     */
    public function __construct(
        private readonly CollectionFactory $quoteCollection,
        private readonly ScopeConfigInterface $config,
        private readonly LoggerInterface $logger,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly ItemResolverInterface $itemResolver,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ResourceConnection $resource,
        private readonly ReminderLink $reminderLink,
        private readonly ReminderMail $mail
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
     * A cart with nothing left to buy (products disabled or out of stock), or whose address already got its weekly
     * reminders, gets the step recorded without an e-mail, so it does not come back at every run and hold a place
     * in the batch. A cart whose e-mail fails keeps its step and comes back after the delays of RETRY_HOURS.
     *
     * @param Quote $quote
     * @param int $step
     * @param int $sent
     */
    private function remind(Quote $quote, int $step, int $sent): void
    {
        $storeId = (int) $quote->getStoreId();
        $quoteId = (int) $quote->getId();
        $secret = (string) $quote->getData('abandoned_reminder_token') ?: bin2hex(random_bytes(32));
        $email = (string) $quote->getData('reminder_email');
        $emailHash = $this->reminderLink->emailHash($email);
        $link = [
            '_scope' => $storeId,
            '_secure' => true,
            '_nosid' => true,
            '_query' => ['id' => $quoteId, 'token' => $this->reminderLink->token($quoteId, $secret)],
        ];
        $cartLink = $link;
        if ($this->websiteValue('general/utm', (int) $quote->getStore()->getWebsiteId())) {
            $cartLink['_query'] += $this->reminderLink->utm($step);
        }
        $mailed = false;
        $failed = false;
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $items = $this->items($quote);
            if ($items && $this->sentToAddress($emailHash) < self::MAX_PER_ADDRESS) {
                $this->mail->send($storeId, $step, $email, [
                    'customer_name' => $this->customerName($quote),
                    'items' => $items,
                    'subtotal' => $this->price($quote, array_sum(array_column($items, 'amount'))),
                    'cart_url' => $this->url->getUrl('abandonedcart/reminder/restore', $cartLink),
                    'unsubscribe_url' => $this->url->getUrl('abandonedcart/reminder/unsubscribe', $link),
                ]);
                $mailed = true;
            }
        } catch (Exception $e) {
            $failed = true;
            $this->logger->error('Abandoned cart reminder failed for quote ' . $quoteId, ['exception' => $e]);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        $retry = $failed ? $this->retry((int) $quote->getData('abandoned_reminder_failures')) : [];
        $connection = $this->resource->getConnection();
        // updated_at is set to itself: MySQL would otherwise bump it and restart the delays of the next reminder.
        $connection->update(
            $this->resource->getTableName('quote'),
            ['abandoned_reminder_token' => $secret, 'updated_at' => new Expression('updated_at')] + ($retry ?: [
                'abandoned_reminder_count' => $sent + 1,
                'abandoned_reminder_sent_at' => gmdate('Y-m-d H:i:s'),
                'abandoned_reminder_failures' => 0,
                'abandoned_reminder_retry_at' => null,
            ]),
            ['entity_id = ?' => $quoteId]
        );
        if ($mailed) {
            $connection->insert(
                $this->resource->getTableName('mageos_abandoned_cart_reminder'),
                ['quote_id' => $quoteId, 'store_id' => $storeId, 'step' => $step, 'email_hash' => $emailHash]
            );
        }
    }

    /**
     * Columns that keep due a reminder that already failed $failures times and just failed again
     *
     * The reminder waits the next delay of RETRY_HOURS; once they are used up nothing is returned and the reminder
     * is recorded as passed over.
     *
     * @param int $failures
     * @return array<string,int|string>
     */
    public function retry(int $failures): array
    {
        if (!isset(self::RETRY_HOURS[$failures])) {
            return [];
        }
        return [
            'abandoned_reminder_failures' => $failures + 1,
            'abandoned_reminder_retry_at' => gmdate('Y-m-d H:i:s', time() + self::RETRY_HOURS[$failures] * 3600),
        ];
    }

    /**
     * Reminders sent to the address over the last week
     *
     * @param string $emailHash
     * @return int
     */
    private function sentToAddress(string $emailHash): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('mageos_abandoned_cart_reminder'), ['COUNT(*)'])
                ->where('email_hash = ?', $emailHash)
                ->where('sent_at > ?', $this->hoursAgo(self::ADDRESS_WINDOW_HOURS))
        );
    }

    /**
     * Active carts of the website unchanged for $delay hours that got $sent reminders, the last one $gap hours ago
     *
     * The recipient is the e-mail of the cart (customers, and guests of checkouts that save it there as soon as it is
     * typed) or else the one of the cart address changed last. Oldest carts first, so a backlog is served in order.
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
                ->order(['qa.updated_at DESC', 'qa.address_id DESC'])
                ->limit(1)
        ));
        $quotes->addFieldToFilter('is_active', ['eq' => 1])
            ->addFieldToFilter('store_id', ['in' => $storeIds])
            ->addFieldToFilter('items_count', ['gt' => 0])
            ->addFieldToFilter('abandoned_reminder_count', ['eq' => $sent])
            ->addFieldToFilter('updated_at', ['lt' => $this->hoursAgo($delay)])
            ->addFieldToFilter('updated_at', ['gt' => $this->hoursAgo(self::MAX_AGE_HOURS)])
            ->addFieldToFilter(
                'abandoned_reminder_retry_at',
                [['null' => true], ['lt' => $this->hoursAgo(0)]]
            )
            ->setOrder('updated_at', Collection::SORT_ORDER_ASC)
            ->setOrder('entity_id', Collection::SORT_ORDER_ASC)
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
            ->where('NOT EXISTS (' . $connection->select()
                ->from(['optout' => $quotes->getTable('mageos_abandoned_cart_optout')], [new Expression('1')])
                ->where("optout.email_hash = SHA2(LOWER(TRIM($email)), 256)")
                ->assemble() . ')');
        if ($this->websiteValue('general/recipients', $websiteId) !== Recipients::ALL) {
            $select->where('EXISTS (' . $connection->select()
                ->from(['ns' => $quotes->getTable('newsletter_subscriber')], [new Expression('1')])
                ->join(['ns_store' => $quotes->getTable('store')], 'ns_store.store_id = ns.store_id', [])
                ->where("ns.subscriber_email = $email")
                ->where('ns.subscriber_status = ?', Subscriber::STATUS_SUBSCRIBED)
                ->where('ns_store.website_id = ?', $websiteId)
                ->assemble() . ')');
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
     * Name of the customer account, empty for a guest: a name typed at checkout could carry someone else's text
     *
     * @param Quote $quote
     * @return string
     */
    private function customerName(Quote $quote): string
    {
        return $quote->getCustomerId()
            ? trim($quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname())
            : '';
    }

    /**
     * Items still for sale, as lines of the e-mail
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
            $items[] = $this->mail->item(
                $product,
                (string) $item->getName(),
                (float) $item->getQty(),
                $this->price($quote, $amount),
                $amount
            );
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
