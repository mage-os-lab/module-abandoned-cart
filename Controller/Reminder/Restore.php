<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Controller\Reminder;

use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Puts a guest's abandoned cart back in the session from the link of a reminder, then opens the cart.
 *
 * A customer's cart is not put in the session: the customer finds it on the cart page once signed in. A signed-in
 * visitor keeps their own cart.
 */
class Restore implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param ReminderLink $reminderLink
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReminderLink $reminderLink,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $quote = $this->reminderLink->getQuote($this->request);
        if (!$quote || !$quote->getIsActive()) {
            $this->messages->addErrorMessage(__('This cart is no longer available.'));
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        $this->reminderLink->recordClick((int) $quote->getId());
        if (!$quote->getCustomerId() && !$this->customerSession->isLoggedIn()) {
            $this->checkoutSession->replaceQuote($quote);
        }
        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
