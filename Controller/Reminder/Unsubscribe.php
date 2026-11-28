<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Controller\Reminder;

use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Stops all cart reminders to the e-mail address the reminder whose link was followed went to.
 *
 * GET only shows a confirmation form, so a mail scanner that opens links unsubscribes nobody; its POST, and the
 * RFC 8058 one-click POST of the List-Unsubscribe header sent by the mailbox provider, record the opt-out. Neither
 * carries a usable form key: the token of the link is what authorizes them.
 */
class Unsubscribe implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param Http $request
     * @param ReminderLink $reminderLink
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     * @param RawFactory $rawFactory
     * @param PageFactory $pageFactory
     */
    public function __construct(
        private readonly Http $request,
        private readonly ReminderLink $reminderLink,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory,
        private readonly PageFactory $pageFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $emailHash = $this->emailHash();
        $isPost = $this->request->isPost();
        if ($isPost && $emailHash !== null) {
            $this->reminderLink->optOut($emailHash);
        }
        if ($isPost && $this->request->getParam('List-Unsubscribe') === 'One-Click') {
            return $this->rawFactory->create()->setHttpResponseCode($emailHash === null ? 400 : 200)->setContents('');
        }
        if ($emailHash === null) {
            $this->messages->addErrorMessage(__('This link is not valid.'));
            return $this->redirectFactory->create()->setPath('');
        }
        if ($isPost) {
            $this->messages->addSuccessMessage(__('You will no longer receive cart reminders.'));
            return $this->redirectFactory->create()->setPath('');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Stop cart reminders'));
        return $page;
    }

    /**
     * Hash of the address the cart's last reminder went to, or of the cart's e-mail for one reminded before 1.3.0
     *
     * @return string|null
     */
    private function emailHash(): ?string
    {
        $quote = $this->reminderLink->getQuote($this->request);
        if (!$quote) {
            return null;
        }
        $hash = $this->reminderLink->lastEmailHash((int) $quote->getId());
        if ($hash !== null) {
            return $hash;
        }
        $email = (string) ($quote->getCustomerEmail()
            ?: $quote->getShippingAddress()->getEmail()
            ?: $quote->getBillingAddress()->getEmail());
        return $email !== '' ? $this->reminderLink->emailHash($email) : null;
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
