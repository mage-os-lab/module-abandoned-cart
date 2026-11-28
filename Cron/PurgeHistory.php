<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Deletes the reminder history older than the retention set in the configuration, once a day.
 *
 * Opted-out addresses are never deleted: they must keep stopping the reminders.
 */
class PurgeHistory
{
    public const CONFIG_DAYS = 'mageos_abandoned_cart/history/retention_days';

    /** Table => column holding the date of the row. */
    private const TABLES = [
        'mageos_abandoned_cart_reminder' => 'sent_at',
        'mageos_abandoned_cart_issue' => 'created_at',
    ];

    /**
     * @param ScopeConfigInterface $config
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Delete the rows older than the retention; 0 days keeps everything
     *
     * Shortcut: one DELETE per table on an indexed date, daily; delete in batches if a shop sends millions a day.
     *
     * @return int rows deleted
     */
    public function execute(): int
    {
        $days = (int) $this->config->getValue(self::CONFIG_DAYS);
        if ($days <= 0) {
            return 0;
        }
        $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $connection = $this->resource->getConnection();
        $deleted = 0;
        foreach (self::TABLES as $table => $column) {
            $deleted += $connection->delete($this->resource->getTableName($table), ["$column < ?" => $before]);
        }
        return $deleted;
    }
}
