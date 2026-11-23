<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Integration;

use MageOS\AbandonedCart\Model\ReminderLink;
use MageOS\AbandonedCart\Observer\RecordOrder;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Tests the restore and stop links of the reminder e-mails, and the clicks and orders they record for the report.
 */
#[
    DbIsolation(true),
    DataFixture(ProductFixture::class, as: 'product'),
    DataFixture(GuestCartFixture::class, as: 'cart'),
    DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$']),
    DataFixture(
        SetShippingAddressFixture::class,
        ['cart_id' => '$cart.id$', 'address' => ['email' => 'Guest@Example.com']]
    ),
]
class ReminderLinksTest extends AbstractController
{
    private const SECRET = 'cart-secret';

    /**
     * The restore link copies the guest cart into the visitor's own cart, closes it and moves its reminders and the
     * click over, so whoever else holds the reminded cart shares nothing with the visitor.
     */
    public function testRestoreCopiesTheGuestCartIntoTheVisitorCart(): void
    {
        $this->logReminder();
        $this->dispatch($this->link('restore'));

        $this->assertRedirect($this->stringContains('checkout/cart'));
        $sessionQuote = $this->_objectManager->get(CheckoutSession::class)->getQuote();
        $this->assertNotSame($this->cartId(), (int) $sessionQuote->getId());
        $this->assertCount(1, $sessionQuote->getAllVisibleItems());
        $this->assertSame('0', (string) $this->quoteValue($this->cartId(), 'is_active'));
        $this->assertSame([], $this->loggedRows($this->cartId()));
        $this->assertNotNull($this->loggedRows((int) $sessionQuote->getId())[0]['clicked_at']);
    }

    /**
     * A wrong or malformed token restores nothing.
     */
    public function testRestoreRefusesAWrongToken(): void
    {
        $this->dispatch($this->link('restore', 'wrong'));
        $this->assertNotSame($this->cartId(), (int) $this->_objectManager->get(CheckoutSession::class)->getQuoteId());
        $this->assertSame('1', (string) $this->quoteValue($this->cartId(), 'is_active'));
        $this->resetRequest();

        $this->link('restore');
        $this->getRequest()->setParam('token', ['x']);
        $this->dispatch('abandonedcart/reminder/restore');
        $this->assertRedirect($this->stringContains('checkout/cart'));
    }

    /**
     * Opening the stop link only shows the confirmation form: a mail scanner following it unsubscribes nobody.
     */
    public function testUnsubscribeGetOnlyAsksForConfirmation(): void
    {
        $this->dispatch($this->link('unsubscribe'));

        $this->assertStringContainsString('<form method="post"', $this->getResponse()->getBody());
        $this->assertSame([], $this->optedOut());
    }

    /**
     * Confirming records the address the last reminder went to, even when the cart now has another one; a wrong
     * token records nothing.
     */
    public function testUnsubscribePostRecordsTheRemindedAddressOnlyWithTheRightToken(): void
    {
        $this->logReminder(hash('sha256', 'reminded@example.com'));
        $this->post($this->link('unsubscribe', 'wrong'));
        $this->assertSame([], $this->optedOut());
        $this->resetRequest();

        $this->post($this->link('unsubscribe'));
        $this->assertSame([hash('sha256', 'reminded@example.com')], $this->optedOut());
    }

    /**
     * A cart reminded before 1.3.0 has no address logged: the cart's own e-mail is opted out, lower-cased.
     */
    public function testUnsubscribeFallsBackToTheCartEmail(): void
    {
        $this->post($this->link('unsubscribe'));

        $this->assertSame([hash('sha256', 'guest@example.com')], $this->optedOut());
    }

    /**
     * The one-click POST of the List-Unsubscribe header records the address and answers 200, without a form key.
     */
    public function testOneClickPostUnsubscribes(): void
    {
        $path = $this->link('unsubscribe');
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue('List-Unsubscribe', 'One-Click');
        $this->dispatch($path);

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $this->assertSame([hash('sha256', 'guest@example.com')], $this->optedOut());
    }

    /**
     * An order placed from a reminded cart is credited to its last reminder.
     */
    public function testOrderIsCreditedToTheLastReminder(): void
    {
        $this->logReminder();
        $quote = $this->_objectManager->get(CartRepositoryInterface::class)->get($this->cartId());
        $quote->setData('abandoned_reminder_count', 1);
        $order = $this->_objectManager->create(Order::class)->setId(42)->setBaseGrandTotal(99.5);
        $this->_objectManager->get(RecordOrder::class)->execute(new Observer([
            'event' => new Event(['quote' => $quote, 'order' => $order]),
        ]));

        $row = $this->loggedRows($this->cartId())[0];
        $this->assertSame('42', $row['order_id']);
        $this->assertEquals(99.5, $row['base_order_total']);
    }

    /**
     * Log a sent reminder for the fixture cart.
     *
     * @param string|null $emailHash
     */
    private function logReminder(?string $emailHash = null): void
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        $resource->getConnection()->insert(
            $resource->getTableName('mageos_abandoned_cart_reminder'),
            ['quote_id' => $this->cartId(), 'store_id' => 1, 'step' => 1, 'email_hash' => $emailHash]
        );
    }

    /**
     * Logged reminders of a cart.
     *
     * @param int $quoteId
     * @return array<int, array<string, mixed>>
     */
    private function loggedRows(int $quoteId): array
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('mageos_abandoned_cart_reminder'))
                ->where('quote_id = ?', $quoteId)
        );
    }

    /**
     * Column of a cart, read from the database.
     *
     * @param int $quoteId
     * @param string $column
     * @return mixed
     */
    private function quoteValue(int $quoteId, string $column): mixed
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        return $connection->fetchOne(
            $connection->select()->from($resource->getTableName('quote'), $column)->where('entity_id = ?', $quoteId)
        );
    }

    /**
     * Give the fixture cart a known secret, set the link parameters and return the link path.
     *
     * @param string $action
     * @param string|null $token the right token when null
     * @return string
     */
    private function link(string $action, ?string $token = null): string
    {
        $quoteRepository = $this->_objectManager->get(CartRepositoryInterface::class);
        $quote = $quoteRepository->get($this->cartId());
        $quoteRepository->save($quote->setData('abandoned_reminder_token', self::SECRET));
        $token ??= $this->_objectManager->get(ReminderLink::class)->token($this->cartId(), self::SECRET);
        $this->getRequest()->setParams(['id' => $this->cartId(), 'token' => $token]);
        return 'abandonedcart/reminder/' . $action;
    }

    /**
     * Dispatch a POST, as the confirmation form sends it.
     *
     * @param string $path
     */
    private function post(string $path): void
    {
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->dispatch($path);
    }

    /**
     * Opted-out addresses.
     *
     * @return string[]
     */
    private function optedOut(): array
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        return $connection->fetchCol(
            $connection->select()->from($resource->getTableName('mageos_abandoned_cart_optout'), 'email_hash')
        );
    }

    /**
     * Id of the fixture cart.
     *
     * @return int
     */
    private function cartId(): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get('cart')->getId();
    }
}
