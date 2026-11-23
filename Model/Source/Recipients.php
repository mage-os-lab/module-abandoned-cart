<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Who receives the reminders.
 */
class Recipients implements OptionSourceInterface
{
    public const SUBSCRIBERS = 'subscribers';
    public const ALL = 'all';

    /**
     * @inheritdoc
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SUBSCRIBERS, 'label' => __('Newsletter subscribers only')],
            ['value' => self::ALL, 'label' => __('All customers and guests')],
        ];
    }
}
