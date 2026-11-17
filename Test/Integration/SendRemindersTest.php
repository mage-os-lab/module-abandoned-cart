<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Integration;

use MageOS\AbandonedCart\Cron\SendReminders;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\CustomerCart as CustomerCartFixture;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests which abandoned carts get a reminder.
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
    #[Config('mageos_abandoned_cart/general/enabled', 1)]
    public function testSubscriberGetsAReminder(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        Bootstrap::getObjectManager()->get(SubscriptionManagerInterface::class)
            ->subscribeCustomer((int) $storage->get('customer')->getId(), 1);

        $this->assertNotNull($this->runOnOldCart((int) $storage->get('cart')->getId()));
    }

    /**
     * Without a newsletter subscription there is no consent for the reminder, so none is sent.
     */
    #[Config('mageos_abandoned_cart/general/enabled', 1)]
    public function testCustomerWithoutSubscriptionGetsNoReminder(): void
    {
        $this->assertNull($this->runOnOldCart((int) DataFixtureStorageManager::getStorage()->get('cart')->getId()));
    }

    /**
     * Age the cart by two days, run the cron and return its "reminder sent at" value.
     *
     * @param int $cartId
     * @return string|null
     */
    private function runOnOldCart(int $cartId): ?string
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $table = $resource->getTableName('quote');
        $connection->update(
            $table,
            ['updated_at' => gmdate('Y-m-d H:i:s', time() - 172800)],
            ['entity_id = ?' => $cartId]
        );

        Bootstrap::getObjectManager()->get(SendReminders::class)->execute();

        $sentAt = $connection->fetchOne(
            $connection->select()->from($table, 'abandoned_reminder_sent_at')->where('entity_id = ?', $cartId)
        );
        return $sentAt ?: null;
    }
}
