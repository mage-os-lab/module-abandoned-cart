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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Stops all cart reminders to the e-mail address of the reminder whose link was followed.
 *
 * Also takes the RFC 8058 one-click POST of the List-Unsubscribe header, sent by the mailbox provider without a
 * form key: the token of the link is what authorizes it.
 *
 * shortcut: done on GET, so a mail scanner that opens links can unsubscribe someone; add a confirmation form
 * if that happens.
 */
class Unsubscribe implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param RequestInterface $request
     * @param ReminderLink $reminderLink
     * @param ResourceConnection $resource
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     * @param RawFactory $rawFactory
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReminderLink $reminderLink,
        private readonly ResourceConnection $resource,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $quote = $this->reminderLink->getQuote($this->request);
        $email = $quote ? (string) ($quote->getCustomerEmail()
            ?: $quote->getShippingAddress()->getEmail()
            ?: $quote->getBillingAddress()->getEmail()) : '';
        if ($email !== '') {
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName('mageos_abandoned_cart_optout'),
                ['email' => mb_strtolower($email)],
                ['email']
            );
        }
        if ($this->request instanceof Http && $this->request->isPost()) {
            return $this->rawFactory->create()->setHttpResponseCode($email === '' ? 400 : 200)->setContents('');
        }
        if ($email === '') {
            $this->messages->addErrorMessage(__('This link is not valid.'));
        } else {
            $this->messages->addSuccessMessage(__('You will no longer receive cart reminders.'));
        }
        return $this->redirectFactory->create()->setPath('');
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
