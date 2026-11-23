<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Resolves the cart of a reminder link from its id and token, and records what follows the last reminder.
 *
 * The cart stores a random secret; the link carries an HMAC of it under the deployment key, so the secret showing
 * up in the storefront (checkoutConfig.quoteData) is no link.
 */
class ReminderLink
{
    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param ResourceConnection $resource
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Token of the links of a cart with the given secret
     *
     * @param int $quoteId
     * @param string $secret
     * @return string
     */
    public function token(int $quoteId, string $secret): string
    {
        return $this->encryptor->hash($quoteId . ':' . $secret);
    }

    /**
     * Key of an e-mail address in the opt-out and reminder tables, so they hold no address in clear
     *
     * @param string $email
     * @return string
     */
    public function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Cart of the link, or null when the id is unknown or the token does not match
     *
     * @param RequestInterface $request
     * @return Quote|null
     */
    public function getQuote(RequestInterface $request): ?Quote
    {
        $token = $request->getParam('token');
        if (!is_string($token) || $token === '') {
            return null;
        }
        try {
            /** @var Quote $quote */
            $quote = $this->quoteRepository->get((int) $request->getParam('id'));
        } catch (NoSuchEntityException) {
            return null;
        }
        $secret = (string) $quote->getData('abandoned_reminder_token');
        return $secret !== '' && hash_equals($this->token((int) $quote->getId(), $secret), $token) ? $quote : null;
    }

    /**
     * E-mail hash the cart's last reminder was sent to, null for a cart reminded before 1.3.0
     *
     * @param int $quoteId
     * @return string|null
     */
    public function lastEmailHash(int $quoteId): ?string
    {
        $connection = $this->resource->getConnection();
        $hash = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('mageos_abandoned_cart_reminder'), ['email_hash'])
                ->where('quote_id = ?', $quoteId)
                ->where('email_hash IS NOT NULL')
                ->order('entity_id DESC')
                ->limit(1)
        );
        return $hash ? (string) $hash : null;
    }

    /**
     * Stop the cart reminders to an e-mail hash
     *
     * @param string $emailHash
     */
    public function optOut(string $emailHash): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName('mageos_abandoned_cart_optout'),
            ['email_hash' => $emailHash],
            ['email_hash']
        );
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
     * Credit the reminders of a cart to the cart its items were restored into
     *
     * @param int $fromQuoteId
     * @param int $toQuoteId
     */
    public function moveReminders(int $fromQuoteId, int $toQuoteId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName('mageos_abandoned_cart_reminder'),
            ['quote_id' => $toQuoteId],
            ['quote_id = ?' => $fromQuoteId]
        );
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
