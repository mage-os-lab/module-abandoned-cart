<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Integration;

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
    private const TOKEN = 'secret-token';

    /**
     * The restore link puts the guest cart in the visitor's session.
     */
    public function testRestorePutsTheGuestCartInTheSession(): void
    {
        $this->dispatch($this->link('restore', self::TOKEN));

        $this->assertRedirect($this->stringContains('checkout/cart'));
        $this->assertSame($this->cartId(), (int) $this->_objectManager->get(CheckoutSession::class)->getQuoteId());
    }

    /**
     * A wrong token restores nothing.
     */
    public function testRestoreRefusesAWrongToken(): void
    {
        $this->dispatch($this->link('restore', 'wrong'));

        $this->assertNotSame($this->cartId(), (int) $this->_objectManager->get(CheckoutSession::class)->getQuoteId());
    }

    /**
     * The stop link records the address in lower case; a wrong token records nothing.
     */
    public function testUnsubscribeRecordsTheAddressOnlyWithTheRightToken(): void
    {
        $this->dispatch($this->link('unsubscribe', 'wrong'));
        $this->assertSame([], $this->optedOut());
        $this->resetRequest();

        $this->dispatch($this->link('unsubscribe', self::TOKEN));
        $this->assertSame(['guest@example.com'], $this->optedOut());
    }

    /**
     * Following the restore link marks the cart's last reminder as clicked.
     */
    public function testRestoreRecordsTheClick(): void
    {
        $this->logReminder();
        $this->dispatch($this->link('restore', self::TOKEN));

        $this->assertNotNull($this->loggedValue('clicked_at'));
    }

    /**
     * The one-click POST of the List-Unsubscribe header records the address and answers 200, without a form key.
     */
    public function testOneClickPostUnsubscribes(): void
    {
        $path = $this->link('unsubscribe', self::TOKEN);
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue('List-Unsubscribe', 'One-Click');
        $this->dispatch($path);

        $this->assertSame(200, $this->getResponse()->getHttpResponseCode());
        $this->assertSame(['guest@example.com'], $this->optedOut());
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

        $this->assertSame('42', $this->loggedValue('order_id'));
        $this->assertEquals(99.5, $this->loggedValue('base_order_total'));
    }

    /**
     * Log a sent reminder for the fixture cart.
     */
    private function logReminder(): void
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        $resource->getConnection()->insert(
            $resource->getTableName('mageos_abandoned_cart_reminder'),
            ['quote_id' => $this->cartId(), 'store_id' => 1, 'step' => 1]
        );
    }

    /**
     * Column of the fixture cart's logged reminder.
     *
     * @param string $column
     * @return mixed
     */
    private function loggedValue(string $column): mixed
    {
        $resource = $this->_objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        return $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('mageos_abandoned_cart_reminder'), $column)
                ->where('quote_id = ?', $this->cartId())
        );
    }

    /**
     * Give the fixture cart a known token, set the link parameters and return the link path.
     *
     * @param string $action
     * @param string $token
     * @return string
     */
    private function link(string $action, string $token): string
    {
        $quoteRepository = $this->_objectManager->get(CartRepositoryInterface::class);
        $quote = $quoteRepository->get($this->cartId());
        $quoteRepository->save($quote->setData('abandoned_reminder_token', self::TOKEN));
        $this->getRequest()->setParams(['id' => $this->cartId(), 'token' => $token]);
        return 'abandonedcart/reminder/' . $action;
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
            $connection->select()->from($resource->getTableName('mageos_abandoned_cart_optout'), 'email')
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
