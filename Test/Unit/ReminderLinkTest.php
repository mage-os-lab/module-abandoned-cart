<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use MageOS\AbandonedCart\Model\ReminderLink;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the campaign parameters of the cart link.
 */
class ReminderLinkTest extends TestCase
{
    /**
     * The cart link of a reminder names the reminder as its campaign.
     */
    public function testCampaignNamesTheReminder(): void
    {
        $this->assertSame(
            ['utm_source' => 'abandoned_cart', 'utm_medium' => 'email', 'utm_campaign' => 'reminder_2'],
            $this->link()->utm(2)
        );
    }

    /**
     * Only plain campaign parameters of a followed link go on to the cart page.
     */
    public function testOnlyPlainCampaignParametersAreForwarded(): void
    {
        $this->assertSame(
            ['utm_source' => 'abandoned_cart', 'utm_campaign' => 'reminder_3'],
            $this->link()->utmFrom([
                'id' => '12',
                'token' => 'secret',
                'utm_source' => 'abandoned_cart',
                'utm_medium' => ['array'],
                'utm_campaign' => 'reminder_3',
                'utm_term' => 'not ours',
            ])
        );
        $this->assertSame([], $this->link()->utmFrom(['utm_source' => 'x" onload="y', 'utm_medium' => '']));
    }

    /**
     * Reminder link service, whose campaign methods use none of its dependencies.
     *
     * @return ReminderLink
     */
    private function link(): ReminderLink
    {
        return new ReminderLink(
            $this->createStub(CartRepositoryInterface::class),
            $this->createStub(ResourceConnection::class),
            $this->createStub(EncryptorInterface::class)
        );
    }
}
