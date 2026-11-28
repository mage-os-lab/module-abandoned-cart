<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Integration;

use MageOS\AbandonedCart\Cron\SendReminders;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\CustomerCart as CustomerCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests which abandoned carts get which reminder.
 *
 * Settings are set on the "base" website: the cron reads them per website, and the test framework's scope config
 * does not pass default values set by a fixture on to the website scope.
 */
#[
    DbIsolation(true),
    DataFixture(ProductFixture::class, as: 'product'),
    DataFixture(CustomerFixture::class, as: 'customer'),
    DataFixture(CustomerCartFixture::class, ['customer_id' => '$customer.id$'], 'cart'),
    DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$']),
]
class SendRemindersTest extends TestCase
{
    /**
     * An old cart of a newsletter subscriber gets one reminder.
     */
    #[Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base')]
    public function testSubscriberGetsAReminder(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        Bootstrap::getObjectManager()->get(SubscriptionManagerInterface::class)
            ->subscribeCustomer((int) $storage->get('customer')->getId(), 1);

        $this->assertSame(1, $this->runOnOldCart());
    }

    /**
     * Without a newsletter subscription there is no consent for the reminder, so none is sent.
     */
    #[Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base')]
    public function testCustomerWithoutSubscriptionGetsNoReminder(): void
    {
        $this->assertSame(0, $this->runOnOldCart());
    }

    /**
     * With "All customers and guests", a customer gets reminded without a subscription.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
    ]
    public function testAllRecipientsIncludesNonSubscribers(): void
    {
        $this->assertSame(1, $this->runOnOldCart());
    }

    /**
     * An address that followed the stop link gets nothing.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
    ]
    public function testOptedOutAddressGetsNoReminder(): void
    {
        $this->connection()->insert(
            $this->table('mageos_abandoned_cart_optout'),
            ['email_hash' => hash('sha256', $this->customerEmail())]
        );

        $this->assertSame(0, $this->runOnOldCart());
    }

    /**
     * An address that got its three reminders of the week gets no more, whatever the cart: its step is recorded
     * without an e-mail.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
    ]
    public function testAddressOverTheWeeklyCapGetsNoReminder(): void
    {
        foreach ([1, 2, 3] as $otherCart) {
            $this->connection()->insert(
                $this->table('mageos_abandoned_cart_reminder'),
                ['quote_id' => 100000 + $otherCart, 'store_id' => 1, 'step' => 1,
                    'email_hash' => hash('sha256', $this->customerEmail())]
            );
        }

        $this->assertSame(1, $this->runOnOldCart());
        $this->assertSame([], $this->loggedSteps());
        $this->assertSame([SendReminders::ISSUE_ADDRESS_LIMIT], $this->issues());
    }

    /**
     * Reminder 2 waits the gap between both delays after reminder 1, and sending keeps the cart's updated_at.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
        Config('mageos_abandoned_cart/reminder2/delay_hours', 36, 'website', 'base'),
    ]
    public function testSecondReminderWaitsForTheGap(): void
    {
        $this->assertSame(1, $this->runOnOldCart());
        $this->runCron();
        $this->assertSame(1, $this->reminderCount(), 'Reminder 2 must wait 12 hours after reminder 1.');

        $this->connection()->update(
            $this->table('quote'),
            [
                'abandoned_reminder_sent_at' => gmdate('Y-m-d H:i:s', time() - 13 * 3600),
                'updated_at' => new Expression('updated_at'),
            ],
            ['entity_id = ?' => $this->cartId()]
        );
        $this->runCron();
        $this->assertSame(2, $this->reminderCount());
        $this->assertSame(
            gmdate('Y-m-d', time() - 172800),
            substr((string) $this->quoteValue('updated_at'), 0, 10)
        );
    }

    /**
     * A website where the module is off sends nothing, even when it is on by default.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
        Config('mageos_abandoned_cart/general/enabled', 0, 'website', 'base'),
    ]
    public function testWebsiteSettingWins(): void
    {
        $this->assertSame(0, $this->runOnOldCart());
    }

    /**
     * A cart below the minimum subtotal gets nothing.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
        Config('mageos_abandoned_cart/general/min_subtotal', 100000, 'website', 'base'),
    ]
    public function testCartBelowMinimumSubtotalGetsNoReminder(): void
    {
        $this->assertSame(0, $this->runOnOldCart());
    }

    /**
     * A sent reminder is logged for the report.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
    ]
    public function testSentReminderIsLogged(): void
    {
        $this->assertSame(1, $this->runOnOldCart());
        $this->assertSame(['1'], $this->loggedSteps());
    }

    /**
     * A cart with nothing left for sale gets its step recorded without an e-mail, so it leaves the batch.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
    ]
    public function testCartWithNothingForSaleIsSkippedWithoutEmail(): void
    {
        $products = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        $product = $products->get(DataFixtureStorageManager::getStorage()->get('product')->getSku(), true, 0, true);
        $products->save($product->setStatus(Status::STATUS_DISABLED));

        $this->assertSame(1, $this->runOnOldCart());
        $this->assertSame([], $this->loggedSteps());
        $this->assertSame([SendReminders::ISSUE_NOTHING_FOR_SALE], $this->issues());
    }

    /**
     * A guest cart is reminded through the e-mail saved on its address, with a token for the restore link.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
        DataFixture(ProductFixture::class, as: 'product'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$']),
        DataFixture(
            SetShippingAddressFixture::class,
            ['cart_id' => '$cart.id$', 'address' => ['email' => 'guest@example.com']]
        ),
    ]
    public function testGuestWithEmailGetsAReminder(): void
    {
        $this->assertSame(1, $this->runOnOldCart());
        $this->assertSame(64, strlen((string) $this->quoteValue('abandoned_reminder_token')));
        $this->assertSame(
            [hash('sha256', 'guest@example.com')],
            $this->connection()->fetchCol(
                $this->connection()->select()
                    ->from($this->table('mageos_abandoned_cart_reminder'), 'email_hash')
                    ->where('quote_id = ?', $this->cartId())
            )
        );
    }

    /**
     * A guest cart whose checkout saved the e-mail on the cart itself, without any address, is reminded.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
        DataFixture(ProductFixture::class, as: 'product'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$']),
    ]
    public function testGuestWithEmailOnTheCartGetsAReminder(): void
    {
        $this->connection()->update(
            $this->table('quote'),
            ['customer_email' => 'guest@example.com'],
            ['entity_id = ?' => $this->cartId()]
        );

        $this->assertSame(1, $this->runOnOldCart());
        $this->assertSame(['1'], $this->loggedSteps());
    }

    /**
     * A reminder whose sending fails stays due, waits before each new try, and is passed over after the last one.
     */
    #[
        Config('mageos_abandoned_cart/general/enabled', 1, 'website', 'base'),
        Config('mageos_abandoned_cart/general/recipients', 'all', 'website', 'base'),
        Config('mageos_abandoned_cart/reminder1/template', 'mageos_no_such_template', 'store', 'default'),
    ]
    public function testFailedSendingIsTriedAgainThenPassedOver(): void
    {
        $this->assertSame(0, $this->runOnOldCart(), 'A failed reminder must stay due.');
        $this->assertSame(1, (int) $this->quoteValue('abandoned_reminder_failures'));
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) $this->quoteValue('abandoned_reminder_retry_at'));

        $this->runCron();
        $this->assertSame(1, (int) $this->quoteValue('abandoned_reminder_failures'), 'The new try must wait.');

        $this->connection()->update(
            $this->table('quote'),
            [
                'abandoned_reminder_failures' => count(SendReminders::RETRY_HOURS),
                'abandoned_reminder_retry_at' => gmdate('Y-m-d H:i:s', time() - 60),
                'updated_at' => new Expression('updated_at'),
            ],
            ['entity_id = ?' => $this->cartId()]
        );
        $this->runCron();
        $this->assertSame(1, $this->reminderCount(), 'After the last try the reminder is passed over.');
        $this->assertSame(0, (int) $this->quoteValue('abandoned_reminder_failures'));
        $this->assertSame([], $this->loggedSteps());
    }

    /**
     * Age the fixture cart by two days, run the cron and return its reminder count.
     *
     * @return int
     */
    private function runOnOldCart(): int
    {
        $this->connection()->update(
            $this->table('quote'),
            ['updated_at' => gmdate('Y-m-d H:i:s', time() - 172800)],
            ['entity_id = ?' => $this->cartId()]
        );
        $this->runCron();
        return $this->reminderCount();
    }

    /**
     * Steps logged for the fixture cart.
     *
     * @return string[]
     */
    private function loggedSteps(): array
    {
        return $this->connection()->fetchCol(
            $this->connection()->select()
                ->from($this->table('mageos_abandoned_cart_reminder'), 'step')
                ->where('quote_id = ?', $this->cartId())
        );
    }

    /**
     * Reasons recorded in the history for the fixture cart.
     *
     * @return string[]
     */
    private function issues(): array
    {
        return $this->connection()->fetchCol(
            $this->connection()->select()
                ->from($this->table('mageos_abandoned_cart_issue'), 'reason')
                ->where('quote_id = ?', $this->cartId())
        );
    }

    /**
     * Run the reminder cron.
     */
    private function runCron(): void
    {
        Bootstrap::getObjectManager()->create(SendReminders::class)->execute();
    }

    /**
     * Number of reminders sent for the fixture cart.
     *
     * @return int
     */
    private function reminderCount(): int
    {
        return (int) $this->quoteValue('abandoned_reminder_count');
    }

    /**
     * Column of the fixture cart, read from the database.
     *
     * @param string $column
     * @return mixed
     */
    private function quoteValue(string $column): mixed
    {
        return $this->connection()->fetchOne(
            $this->connection()->select()->from($this->table('quote'), $column)->where('entity_id = ?', $this->cartId())
        );
    }

    /**
     * Lower-cased e-mail of the fixture customer.
     *
     * @return string
     */
    private function customerEmail(): string
    {
        return strtolower((string) DataFixtureStorageManager::getStorage()->get('customer')->getEmail());
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

    /**
     * Database connection.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
    }

    /**
     * Table name with prefix.
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return Bootstrap::getObjectManager()->get(ResourceConnection::class)->getTableName($name);
    }
}
