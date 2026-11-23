<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Observer;

use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Credits an order placed from a reminded cart to the cart's last reminder, for the reminder report.
 */
class RecordOrder implements ObserverInterface
{
    /**
     * @param ReminderLink $reminderLink
     */
    public function __construct(
        private readonly ReminderLink $reminderLink
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
                (float) $order->getBaseGrandTotal()
            );
        }
    }
}
