<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Setup\Patch\Data;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Replaces the opt-out addresses kept in clear before 1.3.0, copied as such into email_hash, by their hash.
 */
class HashOptOutEmails implements DataPatchInterface
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
        $this->setup->getConnection()->update(
            $this->setup->getTable('mageos_abandoned_cart_optout'),
            ['email_hash' => new Expression('SHA2(LOWER(TRIM(email_hash)), 256)')],
            ["email_hash LIKE '%@%'"]
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
