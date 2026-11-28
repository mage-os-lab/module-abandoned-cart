<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;

/**
 * Cart price rules that can generate coupon codes, for the coupon of a reminder.
 */
class CouponRule implements OptionSourceInterface
{
    /**
     * @param CollectionFactory $rules
     */
    public function __construct(
        private readonly CollectionFactory $rules
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return array<int, array{value: string, label: Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('No coupon')]];
        $rules = $this->rules->create()->setOrder('name', 'ASC');
        $rules->getSelect()->where(
            'main_table.use_auto_generation = 1 OR main_table.coupon_type = ?',
            Rule::COUPON_TYPE_AUTO
        );
        /** @var Rule $rule */
        foreach ($rules as $rule) {
            $options[] = [
                'value' => (string) $rule->getId(),
                'label' => $rule->getIsActive()
                    ? (string) $rule->getName()
                    : __('%1 (inactive)', $rule->getName()),
            ];
        }
        return $options;
    }
}
