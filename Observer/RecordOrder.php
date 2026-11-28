<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Observer;

use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Credits an order placed from a reminded cart to the cart's last reminder, for the reminder report, when it comes
 * within the attribution window of that reminder.
 */
class RecordOrder implements ObserverInterface
{
    public const CONFIG_DAYS = 'mageos_abandoned_cart/history/attribution_days';

    /**
     * @param ReminderLink $reminderLink
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly ReminderLink $reminderLink,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');
        if ($quote->getData('abandoned_reminder_count') > 0) {
            $this->reminderLink->recordOrder(
                (int) $quote->getId(),
                (int) $order->getId(),
                (float) $order->getBaseGrandTotal(),
                (int) $this->config->getValue(self::CONFIG_DAYS)
            );
        }
    }
}
