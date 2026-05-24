<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\Model\Table\AuditLogsTable;
use Cake\Database\Expression\FunctionExpression;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Read-only aggregations powering the admin dashboard at /admin/audit-logs.
 *
 * Each method runs a single indexed query against the audit_logs table.
 * No caching applied here — callers can wrap with Cache::remember() if needed.
 */
class DashboardService
{
    use LocatorAwareTrait;

    protected AuditLogsTable $AuditLogs;

    public function __construct(?AuditLogsTable $auditLogs = null)
    {
        if (!$auditLogs instanceof \AuditStash\Model\Table\AuditLogsTable) {
            /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogs */
            $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        }
        $this->AuditLogs = $auditLogs;
    }

    /**
     * Headline KPI counts for the dashboard cards.
     *
     * @return array{
     *   events_today: int,
     *   events_yesterday: int,
     *   active_users_today: int,
     *   active_users_yesterday: int,
     *   sources_7d: int,
     * }
     */
    public function kpis(): array
    {
        // Compare apples-to-apples: today's events from midnight up to NOW vs
        // yesterday's events from yesterday-midnight up to YESTERDAY at the
        // same time-of-day. Otherwise at 00:01am the dashboard would always
        // show "-100% vs yesterday" because today has barely started while
        // yesterday is a full day's worth of activity.
        $now = new DateTime('now');
        $todayStart = (new DateTime('today'))->format('Y-m-d H:i:s');
        $yesterdayStart = (new DateTime('yesterday'))->format('Y-m-d H:i:s');
        $yesterdayUpToNow = (new DateTime('yesterday'))
            ->setTime((int)$now->format('H'), (int)$now->format('i'), (int)$now->format('s'))
            ->format('Y-m-d H:i:s');
        $weekAgo = (new DateTime('-7 days'))->format('Y-m-d H:i:s');

        $eventsToday = $this->AuditLogs->find()
            ->where(['AuditLogs.created >=' => $todayStart])
            ->count();

        $eventsYesterday = $this->AuditLogs->find()
            ->where([
                'AuditLogs.created >=' => $yesterdayStart,
                'AuditLogs.created <' => $yesterdayUpToNow,
            ])
            ->count();

        $activeUsersToday = $this->AuditLogs->find()
            ->select(['AuditLogs.user_id'])
            ->where([
                'AuditLogs.created >=' => $todayStart,
                'AuditLogs.user_id IS NOT' => null,
            ])
            ->distinct(['AuditLogs.user_id'])
            ->all()
            ->count();

        $activeUsersYesterday = $this->AuditLogs->find()
            ->select(['AuditLogs.user_id'])
            ->where([
                'AuditLogs.created >=' => $yesterdayStart,
                'AuditLogs.created <' => $yesterdayUpToNow,
                'AuditLogs.user_id IS NOT' => null,
            ])
            ->distinct(['AuditLogs.user_id'])
            ->all()
            ->count();

        $sources7d = $this->AuditLogs->find()
            ->select(['AuditLogs.source'])
            ->where(['AuditLogs.created >=' => $weekAgo])
            ->distinct(['AuditLogs.source'])
            ->all()
            ->count();

        return [
            'events_today' => $eventsToday,
            'events_yesterday' => $eventsYesterday,
            'active_users_today' => $activeUsersToday,
            'active_users_yesterday' => $activeUsersYesterday,
            'sources_7d' => $sources7d,
        ];
    }

    /**
     * Daily event counts grouped by type, for the bar chart.
     *
     * Returns an ordered array of {date, totals_per_type, total} tuples covering
     * the requested window — including days with zero events so the chart axis
     * is continuous.
     *
     * @param int $days Window length, including today.
     *
     * @return array<int, array{date: string, types: array<string, int>, total: int}>
     */
    public function eventsHistogram(int $days = 30): array
    {
        $since = (new DateTime('-' . ($days - 1) . ' days'))->format('Y-m-d');

        // Portable across MySQL, PostgreSQL, SQLite — all expose DATE().
        $dayExpr = new FunctionExpression('DATE', ['AuditLogs.created' => 'identifier']);

        $rows = $this->AuditLogs->find()
            ->select([
                'day' => $dayExpr,
                'type' => 'AuditLogs.type',
                'cnt' => $this->AuditLogs->find()->func()->count('*'),
            ])
            ->where(['AuditLogs.created >=' => $since . ' 00:00:00'])
            ->groupBy([$dayExpr, 'AuditLogs.type'])
            ->orderByAsc($dayExpr)
            ->disableHydration()
            ->toArray();

        $byDay = [];
        foreach ($rows as $row) {
            $day = (string)$row['day'];
            $type = (string)$row['type'];
            $byDay[$day][$type] = (int)$row['cnt'];
        }

        // Backfill empty days for a continuous axis.
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = (new DateTime('-' . $i . ' days'))->format('Y-m-d');
            $types = $byDay[$date] ?? [];
            $result[] = [
                'date' => $date,
                'types' => $types,
                'total' => array_sum($types),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{source: string, count: int}>
     */
    public function topSources(int $days = 7, int $limit = 10): array
    {
        $since = (new DateTime('-' . $days . ' days'))->format('Y-m-d H:i:s');

        $rows = $this->AuditLogs->find()
            ->select([
                'source' => 'AuditLogs.source',
                'cnt' => $this->AuditLogs->find()->func()->count('*'),
            ])
            ->where(['AuditLogs.created >=' => $since])
            ->groupBy(['AuditLogs.source'])
            ->orderBy(['cnt' => 'DESC'])
            ->limit($limit)
            ->disableHydration()
            ->toArray();

        return array_map(
            fn (array $r): array => ['source' => (string)$r['source'], 'count' => (int)$r['cnt']],
            $rows,
        );
    }

    /**
     * @return array<int, array{user_id: ?string, user_display: ?string, count: int}>
     */
    public function topUsers(int $days = 7, int $limit = 10): array
    {
        $since = (new DateTime('-' . $days . ' days'))->format('Y-m-d H:i:s');

        $rows = $this->AuditLogs->find()
            ->select([
                'user_id' => 'AuditLogs.user_id',
                'user_display' => 'AuditLogs.user_display',
                'cnt' => $this->AuditLogs->find()->func()->count('*'),
            ])
            ->where([
                'AuditLogs.created >=' => $since,
                'AuditLogs.user_id IS NOT' => null,
            ])
            ->groupBy(['AuditLogs.user_id', 'AuditLogs.user_display'])
            ->orderBy(['cnt' => 'DESC'])
            ->limit($limit)
            ->disableHydration()
            ->toArray();

        return array_map(
            fn (array $r): array => [
                'user_id' => $r['user_id'] !== null ? (string)$r['user_id'] : null,
                'user_display' => $r['user_display'] !== null ? (string)$r['user_display'] : null,
                'count' => (int)$r['cnt'],
            ],
            $rows,
        );
    }

    /**
     * @return array<int, \AuditStash\Model\Entity\AuditLog>
     */
    public function recentEvents(int $limit = 20): array
    {
        /** @var array<int, \AuditStash\Model\Entity\AuditLog> $rows */
        $rows = $this->AuditLogs->find()
            ->orderBy(['AuditLogs.created' => 'DESC'])
            ->limit($limit)
            ->toArray();

        return $rows;
    }
}
