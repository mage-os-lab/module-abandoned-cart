<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use MageOS\AbandonedCart\Controller\Reminder\Restore;
use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests the campaign parameters the cart link carries to the cart page.
 */
class RestoreCampaignTest extends TestCase
{
    /**
     * Behind Varnish the link reaches Magento without its utm_* parameters: the cart page still gets those of the
     * reminder the link belongs to.
     */
    public function testCampaignOfTheReminderFollowsWithoutIncomingParameters(): void
    {
        $this->assertSame(
            ['utm_source' => 'abandoned_cart', 'utm_medium' => 'email', 'utm_campaign' => 'reminder_2'],
            $this->cartPageQuery(true, [])
        );
    }

    /**
     * A link that still carries its parameters (no Varnish) gives the same cart page.
     */
    public function testIncomingParametersChangeNothing(): void
    {
        $this->assertSame(
            ['utm_source' => 'abandoned_cart', 'utm_medium' => 'email', 'utm_campaign' => 'reminder_2'],
            $this->cartPageQuery(true, $this->link()->utm(2))
        );
    }

    /**
     * With the setting off, the cart page gets no campaign of its own.
     */
    public function testNoCampaignWhenTheSettingIsOff(): void
    {
        $this->assertSame([], $this->cartPageQuery(false, []));
    }

    /**
     * Query of the cart page the link of reminder 2 of a customer cart redirects to
     *
     * @param bool $utmEnabled
     * @param array<string, string> $incomingUtm
     * @return array<string, string>
     */
    private function cartPageQuery(bool $utmEnabled, array $incomingUtm): array
    {
        $quote = (new ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
        $quote->setData([
            'id' => 7,
            'store_id' => 1,
            'is_active' => 1,
            'customer_id' => 5,
            'abandoned_reminder_token' => 'secret',
        ]);
        $quotes = $this->createStub(CartRepositoryInterface::class);
        $quotes->method('get')->willReturn($quote);

        $request = $this->createStub(RequestInterface::class);
        $params = ['id' => '7', 'token' => 'token'] + $incomingUtm;
        $request->method('getParam')->willReturnCallback(fn (string $key) => $params[$key] ?? null);
        $request->method('getParams')->willReturn($params);

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn($utmEnabled);
        $config->method('getValue')->willReturn($utmEnabled ? '1' : '0');

        $query = null;
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function (string $path, array $params) use (&$query, $redirect) {
                $query = $params['_query'] ?? [];
                return $redirect;
            }
        );
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);

        (new Restore(
            $request,
            $this->link($quotes),
            $this->createStub(CheckoutSession::class),
            $this->createStub(CustomerSession::class),
            $quotes,
            $this->createStub(ManagerInterface::class),
            $redirects,
            $config
        ))->execute();
        return $query;
    }

    /**
     * Reminder link service whose last reminder of any cart is reminder 2 and whose token of any cart is "token"
     *
     * @param CartRepositoryInterface|null $quotes
     * @return ReminderLink
     */
    private function link(?CartRepositoryInterface $quotes = null): ReminderLink
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('2');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturn('token');
        return new ReminderLink($quotes ?? $this->createStub(CartRepositoryInterface::class), $resource, $encryptor);
    }
}
