<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Resolves the cart of a reminder link from its id and secret token, and records what follows the last reminder.
 */
class ReminderLink
{
    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Cart of the link, or null when the id is unknown or the token does not match
     *
     * @param RequestInterface $request
     * @return Quote|null
     */
    public function getQuote(RequestInterface $request): ?Quote
    {
        $token = (string) $request->getParam('token');
        try {
            /** @var Quote $quote */
            $quote = $this->quoteRepository->get((int) $request->getParam('id'));
        } catch (NoSuchEntityException) {
            return null;
        }
        $expected = (string) $quote->getData('abandoned_reminder_token');
        return $expected !== '' && hash_equals($expected, $token) ? $quote : null;
    }

    /**
     * Record on the cart's last reminder that its link was followed, the first time only
     *
     * @param int $quoteId
     */
    public function recordClick(int $quoteId): void
    {
        $this->updateLastReminder($quoteId, ['clicked_at' => gmdate('Y-m-d H:i:s')], 'clicked_at IS NULL');
    }

    /**
     * Record on the cart's last reminder the order placed from the cart
     *
     * @param int $quoteId
     * @param int $orderId
     * @param float $baseTotal
     */
    public function recordOrder(int $quoteId, int $orderId, float $baseTotal): void
    {
        $this->updateLastReminder($quoteId, ['order_id' => $orderId, 'base_order_total' => $baseTotal]);
    }

    /**
     * Update the last reminder sent for the cart, the one the visitor acts on
     *
     * @param int $quoteId
     * @param array<string,mixed> $data
     * @param string|null $condition
     */
    private function updateLastReminder(int $quoteId, array $data, ?string $condition = null): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('mageos_abandoned_cart_reminder');
        $id = $connection->fetchOne(
            $connection->select()->from($table, ['MAX(entity_id)'])->where('quote_id = ?', $quoteId)
        );
        if ($id) {
            $connection->update($table, $data, array_filter(['entity_id = ?' => (int) $id, $condition]));
        }
    }
}
