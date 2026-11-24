<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Controller\Adminhtml\Report;

use Exception;
use MageOS\AbandonedCart\Model\ReminderMail;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Sends a reminder of a store view, with sample products, to the admin user who asks for it.
 */
class SendTest extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_AbandonedCart::report';

    /**
     * @param Context $context
     * @param ReminderMail $mail
     * @param Session $authSession
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        private readonly ReminderMail $mail,
        private readonly Session $authSession,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $step = (int) $this->getRequest()->getParam('step');
        $storeId = (int) $this->getRequest()->getParam('store');
        $email = (string) $this->authSession->getUser()?->getEmail();
        if (!in_array($step, [1, 2, 3], true) || !isset($this->storeManager->getStores()[$storeId]) || $email === '') {
            $this->messageManager->addErrorMessage((string) __('Choose a reminder and a store view.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
        try {
            $this->mail->sendTest($storeId, $step, $email);
            $this->messageManager->addSuccessMessage(
                (string) __('Reminder %1 was sent as a test to %2.', $step, $email)
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (Exception $e) {
            $this->messageManager->addExceptionMessage($e, (string) __('The test e-mail could not be sent.'));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
