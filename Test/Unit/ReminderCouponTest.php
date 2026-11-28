<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Test\Unit;

use DivisionByZeroError;
use MageOS\AbandonedCart\Model\ReminderCoupon;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Helper\Coupon as CouponHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * Tests which cart price rules may give a reminder its coupon.
 */
class ReminderCouponTest extends TestCase
{
    /**
     * An active, auto-generating rule of the cart's website and group, in its dates, gives a coupon.
     */
    public function testRuleForTheCartGivesACoupon(): void
    {
        $this->assertTrue($this->coupon()->usable($this->rule([]), 1, 0, '2026-11-27'));
    }

    /**
     * A rule that would give a code the cart cannot use gives none.
     *
     * @param array<string, mixed> $override
     */
    #[DataProvider('unusableRules')]
    public function testRuleTheCartCannotUseGivesNoCoupon(array $override): void
    {
        $this->assertFalse($this->coupon()->usable($this->rule($override), 1, 0, '2026-11-27'));
    }

    /**
     * Rules whose code would not work for a guest cart of website 1 on 2026-11-27.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableRules(): array
    {
        return [
            'inactive' => [['is_active' => false]],
            'specific code only' => [['use_auto_generation' => false]],
            'other website' => [['website_ids' => [2]]],
            'other customer group' => [['customer_group_ids' => [1, 2]]],
            'not started' => [['from_date' => '2026-11-28']],
            'expired' => [['to_date' => '2026-11-26']],
        ];
    }

    /**
     * A rule ending today still gives a coupon, and the legacy "Auto" coupon type counts as generating codes.
     */
    public function testLastDayAndLegacyAutoTypeGiveACoupon(): void
    {
        $rule = $this->rule([
            'to_date' => '2026-11-27',
            'use_auto_generation' => false,
            'coupon_type' => RuleInterface::COUPON_TYPE_AUTO,
        ]);
        $this->assertTrue($this->coupon()->usable($rule, 1, 0, '2026-11-27'));
    }

    /**
     * A format the core does not know, such as the "1" of a store that never saved its promotion settings, falls back
     * to alphanumeric instead of making the core divide by zero.
     */
    public function testUnknownCodeFormatFallsBackToAlphanumeric(): void
    {
        $this->assertSame('alphanum', $this->coupon(['couponHelper' => $this->helper('1')])->format());
        $this->assertSame('alphanum', $this->coupon(['couponHelper' => $this->helper(null)])->format());
        $this->assertSame('num', $this->coupon(['couponHelper' => $this->helper('num')])->format());
    }

    /**
     * An error, not only an exception, while generating the code gives no coupon instead of stopping the cron.
     */
    public function testGenerationErrorGivesNoCoupon(): void
    {
        $rules = $this->createStub(RuleRepositoryInterface::class);
        $rules->method('getById')->willThrowException(new DivisionByZeroError('Division by zero'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $coupon = $this->coupon(['ruleRepository' => $rules, 'logger' => $logger]);

        $this->assertNull($coupon->generate(4, $this->createStub(Quote::class), 1));
    }

    /**
     * Coupon helper whose configured format is $format.
     *
     * @param string|null $format
     * @return CouponHelper
     */
    private function helper(?string $format): CouponHelper
    {
        $helper = $this->createStub(CouponHelper::class);
        $helper->method('getDefaultFormat')->willReturn($format);
        $helper->method('getFormatsList')->willReturn(['alphanum' => 'A', 'alpha' => 'B', 'num' => 'C']);
        return $helper;
    }

    /**
     * Cart price rule whose code works for a guest cart of website 1, with some values overridden.
     *
     * @param array<string, mixed> $override
     * @return RuleInterface
     */
    private function rule(array $override): RuleInterface
    {
        $data = $override + [
            'is_active' => true,
            'use_auto_generation' => true,
            'coupon_type' => RuleInterface::COUPON_TYPE_SPECIFIC_COUPON,
            'website_ids' => ['1', '2'],
            'customer_group_ids' => ['0', '1'],
            'from_date' => '2026-11-01',
            'to_date' => null,
        ];
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('getIsActive')->willReturn($data['is_active']);
        $rule->method('getUseAutoGeneration')->willReturn($data['use_auto_generation']);
        $rule->method('getCouponType')->willReturn($data['coupon_type']);
        $rule->method('getWebsiteIds')->willReturn($data['website_ids']);
        $rule->method('getCustomerGroupIds')->willReturn($data['customer_group_ids']);
        $rule->method('getFromDate')->willReturn($data['from_date']);
        $rule->method('getToDate')->willReturn($data['to_date']);
        return $rule;
    }

    /**
     * Coupon service with only the given dependencies (the factory of generation specs is generated code, absent here).
     *
     * @param array<string, object> $dependencies
     * @return ReminderCoupon
     */
    private function coupon(array $dependencies = []): ReminderCoupon
    {
        $coupon = (new ReflectionClass(ReminderCoupon::class))->newInstanceWithoutConstructor();
        // Reflection may initialize readonly properties from outside their class.
        foreach ($dependencies as $name => $value) {
            (new ReflectionProperty(ReminderCoupon::class, $name))->setValue($coupon, $value);
        }
        return $coupon;
    }
}
