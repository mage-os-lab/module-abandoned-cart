<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Setup\Patch\Data;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Carries the 1.0 single-reminder settings over to "Reminder 1" and counts carts already reminded as such.
 */
class MigrateSingleReminder implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $setup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $config = $this->setup->getTable('core_config_data');
        foreach (['delay_hours', 'template'] as $field) {
            $connection->update(
                $config,
                ['path' => 'mageos_abandoned_cart/reminder1/' . $field],
                ['path = ?' => 'mageos_abandoned_cart/general/' . $field]
            );
        }
        $connection->update(
            $config,
            ['value' => 'mageos_abandoned_cart_reminder1_template'],
            [
                'path = ?' => 'mageos_abandoned_cart/reminder1/template',
                'value = ?' => 'mageos_abandoned_cart_general_template',
            ]
        );
        $connection->update(
            $this->setup->getTable('quote'),
            ['abandoned_reminder_count' => 1, 'updated_at' => new Expression('updated_at')],
            ['abandoned_reminder_sent_at IS NOT NULL', 'abandoned_reminder_count = 0']
        );
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
