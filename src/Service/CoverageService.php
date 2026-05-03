<?php

declare(strict_types=1);

namespace AuditStash\Service;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use ReflectionClass;
use Throwable;

/**
 * Discovers Table classes shipped by the app and loaded plugins, classifies
 * each as `tracked`, `missing`, `empirical`, or `internal`, and exposes a
 * summary count for the dashboard KPI tile.
 *
 * Configurable via:
 *
 * ```
 * 'AuditStash' => [
 *     'coverage' => [
 *         'hidePlugins' => ['Migrations', 'Captcha', ...],
 *         'hideTables' => ['Sessions', ...],
 *     ],
 * ],
 * ```
 */
class CoverageService
{
    use LocatorAwareTrait;

    /**
     * Plugins whose Tables never belong on a coverage report (the plugin's
     * own bookkeeping plus tooling/dev plugins that don't represent domain
     * data). Merged with `AuditStash.coverage.hidePlugins` so apps can
     * extend the deny-list without losing the safe defaults.
     *
     * @var array<int, string>
     */
    protected const DEFAULT_HIDE_PLUGINS = [
        'AuditStash',
        'Bouncer',
        'Migrations',
        'DebugKit',
    ];

    /**
     * @return array<int, array{
     *   alias: string,
     *   source: string,
     *   class: ?string,
     *   plugin: ?string,
     *   has_behavior: bool,
     *   instantiation_error: ?string,
     *   events_30d: int,
     *   status: 'tracked'|'missing'|'empirical'|'internal',
     *   config: ?array<string, mixed>,
     * }>
     */
    public function discover(bool $includeInternal = false): array
    {
        $hidePlugins = array_merge(
            self::DEFAULT_HIDE_PLUGINS,
            (array)Configure::read('AuditStash.coverage.hidePlugins', []),
        );
        $hideTables = (array)Configure::read('AuditStash.coverage.hideTables', []);

        $classes = $this->discoverClasses();
        $eventCounts = $this->eventCountsBySource(30);

        $rows = [];
        $seenAliases = [];

        foreach ($classes as $alias => $info) {
            $seenAliases[$alias] = true;
            $isInternal = in_array($info['plugin'], $hidePlugins, true)
                || in_array($alias, $hideTables, true)
                || str_starts_with($alias, 'AuditStash.');

            if ($isInternal && !$includeInternal) {
                continue;
            }

            $inspection = $this->inspectClass($alias);

            // Resolve the audit-log source for this class. AuditLogBehavior
            // persists the source as `$_table->getRegistryAlias()`, which
            // for plugin tables can be either `Plugin.Table` (when loaded
            // with the dotted alias) or just `Table` (when loaded without
            // the prefix from app code or via association). Pick whichever
            // form actually has events; the dotted form wins when both
            // forms have rows.
            $events = $eventCounts[$alias] ?? 0;
            $source = $alias;
            if ($events === 0 && $info['plugin'] !== null) {
                $shortEvents = $eventCounts[$info['short_alias']] ?? 0;
                if ($shortEvents > 0) {
                    $events = $shortEvents;
                    $source = $info['short_alias'];
                    $seenAliases[$info['short_alias']] = true;
                }
            }

            $status = $isInternal
                ? 'internal'
                : ($inspection['has_behavior'] ? 'tracked' : 'missing');

            $rows[] = [
                'alias' => $alias,
                'source' => $source,
                'class' => $info['class'],
                'plugin' => $info['plugin'],
                'has_behavior' => $inspection['has_behavior'],
                'instantiation_error' => $inspection['error'],
                'events_30d' => $events,
                'status' => $status,
                'config' => $inspection['config'],
            ];
        }

        // Empirical sources: events recorded for a `source` we couldn't map to
        // a class (custom events, renamed tables, uninstalled plugins).
        foreach ($eventCounts as $source => $count) {
            if (isset($seenAliases[$source])) {
                continue;
            }
            $rows[] = [
                'alias' => $source,
                'source' => $source,
                'class' => null,
                'plugin' => null,
                'has_behavior' => false,
                'instantiation_error' => null,
                'events_30d' => $count,
                'status' => 'empirical',
                'config' => null,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $orderA = $this->statusOrder($a['status']);
            $orderB = $this->statusOrder($b['status']);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return strcmp($a['alias'], $b['alias']);
        });

        return $rows;
    }

    /**
     * Counts per status for the dashboard KPI tile.
     *
     * @return array{tracked: int, missing: int, empirical: int, total: int}
     */
    public function summary(): array
    {
        $rows = $this->discover(false);
        $tracked = $missing = $empirical = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'tracked') {
                $tracked++;
            } elseif ($row['status'] === 'missing') {
                $missing++;
            } elseif ($row['status'] === 'empirical') {
                $empirical++;
            }
        }

        return [
            'tracked' => $tracked,
            'missing' => $missing,
            'empirical' => $empirical,
            'total' => $tracked + $missing,
        ];
    }

    /**
     * @return array<string, array{class: string, plugin: ?string, short_alias: string}>
     */
    protected function discoverClasses(): array
    {
        $result = [];

        // App tables.
        $appNamespace = Configure::read('App.namespace', 'App');
        $appPath = APP . 'Model' . DS . 'Table' . DS;
        if (is_dir($appPath)) {
            foreach (glob($appPath . '*Table.php') ?: [] as $file) {
                $name = basename($file, 'Table.php');
                $fqcn = $appNamespace . '\\Model\\Table\\' . $name . 'Table';
                if (!$this->isUsableTableClass($fqcn)) {
                    continue;
                }
                $result[$name] = [
                    'class' => $fqcn,
                    'plugin' => null,
                    'short_alias' => $name,
                ];
            }
        }

        // Plugin tables.
        foreach (Plugin::getCollection() as $plugin) {
            $pluginName = $plugin->getName();
            $tableDir = $plugin->getPath() . 'src' . DS . 'Model' . DS . 'Table' . DS;
            if (!is_dir($tableDir)) {
                continue;
            }
            foreach (glob($tableDir . '*Table.php') ?: [] as $file) {
                $name = basename($file, 'Table.php');
                $alias = $pluginName . '.' . $name;
                $fqcn = str_replace('/', '\\', $pluginName) . '\\Model\\Table\\' . $name . 'Table';
                if (!$this->isUsableTableClass($fqcn)) {
                    continue;
                }
                $result[$alias] = [
                    'class' => $fqcn,
                    'plugin' => $pluginName,
                    'short_alias' => $name,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{has_behavior: bool, error: ?string, config: ?array<string, mixed>}
     */
    protected function inspectClass(string $alias): array
    {
        try {
            $table = $this->fetchTable($alias);
            $hasBehavior = $table->behaviors()->has('AuditLog');
            $config = $hasBehavior ? $table->behaviors()->get('AuditLog')->getConfig() : null;

            return ['has_behavior' => $hasBehavior, 'error' => null, 'config' => $config];
        } catch (Throwable $e) {
            return ['has_behavior' => false, 'error' => $e->getMessage(), 'config' => null];
        }
    }

    /**
     * @return array<string, int>
     */
    protected function eventCountsBySource(int $days): array
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogs */
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $since = (new DateTime('-' . $days . ' days'))->format('Y-m-d H:i:s');

        $rows = $auditLogs->find()
            ->select([
                'source' => 'AuditLogs.source',
                'cnt' => $auditLogs->find()->func()->count('*'),
            ])
            ->where(['AuditLogs.created >=' => $since])
            ->groupBy(['AuditLogs.source'])
            ->disableHydration()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['source']] = (int)$row['cnt'];
        }

        return $out;
    }

    protected function isUsableTableClass(string $fqcn): bool
    {
        if (!class_exists($fqcn)) {
            return false;
        }
        try {
            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract()) {
                return false;
            }
            if (!$ref->isSubclassOf(Table::class) && $ref->getName() !== Table::class) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    protected function statusOrder(string $status): int
    {
        return match ($status) {
            'missing' => 0,
            'empirical' => 1,
            'tracked' => 2,
            'internal' => 3,
            default => 99,
        };
    }
}
