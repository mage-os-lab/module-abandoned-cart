<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterfaceFactory;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Helper\Coupon as CouponHelper;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generates the single-use coupon of a reminder from the cart price rule chosen for it, if any.
 *
 * The code follows the rule's own limits (uses per coupon, per customer, end date) and the store's settings for
 * generated codes (length, format, prefix). A rule that cannot give this cart a working code gives none.
 */
class ReminderCoupon
{
    /**
     * @param ScopeConfigInterface $config
     * @param RuleRepositoryInterface $ruleRepository
     * @param CouponManagementInterface $couponManagement
     * @param CouponGenerationSpecInterfaceFactory $specFactory
     * @param CouponHelper $couponHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly CouponManagementInterface $couponManagement,
        private readonly CouponGenerationSpecInterfaceFactory $specFactory,
        private readonly CouponHelper $couponHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Id of the cart price rule set for reminder $step of the website, 0 when the reminder has no coupon
     *
     * @param int $step
     * @param int $websiteId
     * @return int
     */
    public function ruleId(int $step, int $websiteId): int
    {
        return (int) $this->config->getValue(
            "mageos_abandoned_cart/reminder$step/coupon_rule",
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    /**
     * New coupon code for the cart, or null when the rule cannot give one that works for it
     *
     * @param int $ruleId
     * @param Quote $quote
     * @param int $websiteId
     * @return string|null
     */
    public function generate(int $ruleId, Quote $quote, int $websiteId): ?string
    {
        try {
            $rule = $this->ruleRepository->getById($ruleId);
            if (!$this->usable($rule, $websiteId, (int) $quote->getCustomerGroupId(), gmdate('Y-m-d'))) {
                return null;
            }
            $spec = $this->specFactory->create()
                ->setRuleId($ruleId)
                ->setQuantity(1)
                ->setFormat($this->format())
                ->setLength($this->couponHelper->getDefaultLength())
                ->setPrefix($this->couponHelper->getDefaultPrefix())
                ->setSuffix($this->couponHelper->getDefaultSuffix())
                ->setDelimiterAtEvery($this->couponHelper->getDefaultDashInterval());
            $codes = $this->couponManagement->generate($spec);
            return $codes ? (string) reset($codes) : null;
        } catch (Throwable $e) {
            // Errors too (the core divides by zero on a bad format): a coupon must never stop the reminder.
            $this->logger->error('Abandoned cart reminder coupon not generated, rule ' . $ruleId, ['exception' => $e]);
            return null;
        }
    }

    /**
     * Format of generated codes set in the promotion settings, alphanumeric when it is not one the core knows
     *
     * A store that never saved *Customers > Promotions* has the core default "1", on which the core divides by zero.
     *
     * @return string
     */
    public function format(): string
    {
        // getDefaultFormat() is documented as int but returns the configured format code (alphanum, alpha, num).
        $format = (string) $this->couponHelper->getDefaultFormat();
        return isset($this->couponHelper->getFormatsList()[$format])
            ? $format
            : CouponHelper::COUPON_FORMAT_ALPHANUMERIC;
    }

    /**
     * Delete a code that was generated for an e-mail that could not be sent
     *
     * @param string $code
     */
    public function cancel(string $code): void
    {
        try {
            $this->couponManagement->deleteByCodes([$code]);
        } catch (Throwable $e) {
            $this->logger->error('Abandoned cart reminder coupon not deleted', ['exception' => $e]);
        }
    }

    /**
     * Whether the rule generates codes that a cart of this website and customer group can use on $today
     *
     * Shortcut: $today is the UTC date while the rule's dates are the store's; a code may be withheld or given
     * on the day the rule starts or ends. The checkout applies the rule's own checks anyway.
     *
     * @param RuleInterface $rule
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string $today Y-m-d
     * @return bool
     */
    public function usable(RuleInterface $rule, int $websiteId, int $customerGroupId, string $today): bool
    {
        return $rule->getIsActive()
            && ($rule->getUseAutoGeneration() || $rule->getCouponType() === RuleInterface::COUPON_TYPE_AUTO)
            && in_array($websiteId, array_map('intval', (array) $rule->getWebsiteIds()), true)
            && in_array($customerGroupId, array_map('intval', (array) $rule->getCustomerGroupIds()), true)
            && (!$rule->getFromDate() || substr((string) $rule->getFromDate(), 0, 10) <= $today)
            && (!$rule->getToDate() || substr((string) $rule->getToDate(), 0, 10) >= $today);
    }
}
