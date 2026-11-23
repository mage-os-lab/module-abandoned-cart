<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Report of the reminders sent, followed and turned into orders.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_AbandonedCart::report';

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        if ($page instanceof Page) {
            $page->setActiveMenu('MageOS_AbandonedCart::report');
            $page->getConfig()->getTitle()->prepend((string) __('Abandoned Cart Reminders'));
        }
        return $page;
    }
}
