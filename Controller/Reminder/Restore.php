<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Controller\Reminder;

use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Copies the items of a guest's abandoned cart into the visitor's own cart from the link of a reminder, then opens
 * the cart.
 *
 * The reminded cart itself never goes into the session: whoever holds it (the link may have been forwarded) would
 * share the visitor's checkout data. It is closed instead, its reminders credited to the visitor's cart. A customer's
 * cart is not touched: the customer finds it on the cart page once signed in. A signed-in visitor keeps their own cart.
 */
class Restore implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param ReminderLink $reminderLink
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param CartRepositoryInterface $quoteRepository
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReminderLink $reminderLink,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory,
        private readonly ScopeConfigInterface $config
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
        $utm = $this->utm($quote);
        if (!$quote->getCustomerId() && !$this->customerSession->isLoggedIn()
            && (int) $this->checkoutSession->getQuoteId() !== (int) $quote->getId()
        ) {
            $this->copyIntoSessionCart($quote);
        }
        // The campaign parameters follow to the cart page: the redirect itself runs no analytics script.
        return $this->redirectFactory->create()->setPath('checkout/cart', ['_query' => $utm]);
    }

    /**
     * Campaign parameters for the cart page: those of the cart's last reminder when the setting is on
     *
     * They are rebuilt rather than read from the link, as Varnish strips utm_* parameters before Magento sees them.
     * Parameters the link still carries are kept for a link sent while the setting was on.
     *
     * @param Quote $quote
     * @return array<string,string>
     */
    private function utm(Quote $quote): array
    {
        $utm = $this->reminderLink->utmFrom($this->request->getParams());
        $step = $this->config->isSetFlag(
            'mageos_abandoned_cart/general/utm',
            ScopeInterface::SCOPE_STORE,
            $quote->getStoreId()
        ) ? $this->reminderLink->lastStep((int) $quote->getId()) : 0;
        return $step ? $this->reminderLink->utm($step) + $utm : $utm;
    }

    /**
     * Merge the items of the reminded cart into the session cart, which takes over its reminder count, and close it
     *
     * @param Quote $quote
     */
    private function copyIntoSessionCart(Quote $quote): void
    {
        $current = $this->checkoutSession->getQuote();
        $current->merge($quote)
            ->setData('abandoned_reminder_count', $quote->getData('abandoned_reminder_count'))
            ->setData('abandoned_reminder_sent_at', $quote->getData('abandoned_reminder_sent_at'))
            ->collectTotals();
        $this->quoteRepository->save($current);
        $this->checkoutSession->setQuoteId($current->getId());
        $this->reminderLink->moveReminders((int) $quote->getId(), (int) $current->getId());
        $this->quoteRepository->save($quote->setIsActive(false));
    }
}
