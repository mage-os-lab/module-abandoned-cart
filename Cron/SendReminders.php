<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Cron;

use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends one reminder e-mail per abandoned cart of a registered customer subscribed to the newsletter
 * of the cart's website: the reminder is marketing e-mail, so it needs that consent and carries an unsubscribe link.
 *
 * shortcut: guest carts are skipped (no reliable e-mail before the order) and there is a single reminder;
 * add a guest restore link or a reminder sequence if needed.
 */
class SendReminders
{
    private const CONFIG = 'mageos_abandoned_cart/general/';

    /**
     * @param CollectionFactory $quoteCollection
     * @param ScopeConfigInterface $config
     * @param TransportBuilder $transportBuilder
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     * @param SubscriberFactory $subscriberFactory
     */
    public function __construct(
        private readonly CollectionFactory $quoteCollection,
        private readonly ScopeConfigInterface $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
        private readonly SubscriberFactory $subscriberFactory
    ) {
    }

    /**
     * Send abandoned cart mail
     *
     * A failed e-mail is not marked as sent, so it is retried on the next run.
     * Item names are escaped here because {{var}} inside a {{for}} loop is output raw by Magento's ForDirective.
     */
    public function execute(): void
    {
        if (!$this->config->isSetFlag(self::CONFIG . 'enabled')) {
            return;
        }
        $delay = max(1, (int) $this->config->getValue(self::CONFIG . 'delay_hours'));
        $quotes = $this->quoteCollection->create()
            ->addFieldToFilter('is_active', ['eq' => 1])
            ->addFieldToFilter('items_count', ['gt' => 0])
            ->addFieldToFilter('customer_id', ['notnull' => true])
            ->addFieldToFilter('customer_email', ['notnull' => true])
            ->addFieldToFilter('abandoned_reminder_sent_at', ['null' => true])
            ->addFieldToFilter('updated_at', ['lt' => gmdate('Y-m-d H:i:s', time() - $delay * 3600)])
            ->setPageSize(200);
        $quotes->getSelect()->where(
            'EXISTS (' . $quotes->getConnection()->select()
                ->from(['ns' => $quotes->getTable('newsletter_subscriber')], [new Expression('1')])
                ->join(['ns_store' => $quotes->getTable('store')], 'ns_store.store_id = ns.store_id', [])
                ->join(['quote_store' => $quotes->getTable('store')], 'quote_store.store_id = main_table.store_id', [])
                ->where('ns.customer_id = main_table.customer_id')
                ->where('ns.subscriber_status = ?', Subscriber::STATUS_SUBSCRIBED)
                ->where('ns_store.website_id = quote_store.website_id') . ')'
        );

        foreach ($quotes as $quote) {
            $storeId = (int) $quote->getStoreId();
            $subscriber = $this->subscriberFactory->create()
                ->loadByCustomer((int) $quote->getCustomerId(), (int) $quote->getStore()->getWebsiteId());
            $items = array_map(
                fn ($item) => [
                    'name' => $this->escaper->escapeHtml((string) $item->getName()),
                    'qty' => (float) $item->getQty(),
                ],
                $quote->getAllVisibleItems()
            );
            try {
                $this->transportBuilder
                    ->setTemplateIdentifier(
                        $this->config->getValue(self::CONFIG . 'template', ScopeInterface::SCOPE_STORE, $storeId)
                    )
                    ->setTemplateOptions(['area' => 'frontend', 'store' => $storeId])
                    ->setTemplateVars([
                        'customer_name' => trim($quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname()),
                        'items' => $items,
                        'unsubscribe_url' => $subscriber->getUnsubscriptionLink(),
                    ])
                    ->setFromByScope('sales', $storeId)
                    ->addTo($quote->getCustomerEmail())
                    ->getTransport()
                    ->sendMessage();
            } catch (Exception $e) {
                $this->logger->error(
                    'Abandoned cart reminder failed for quote ' . $quote->getId(),
                    ['exception' => $e]
                );
                continue;
            }
            $quotes->getConnection()->update(
                $quotes->getMainTable(),
                ['abandoned_reminder_sent_at' => gmdate('Y-m-d H:i:s')],
                ['entity_id = ?' => $quote->getId()]
            );
        }
    }
}
