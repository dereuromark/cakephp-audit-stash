<?php

declare(strict_types=1);

namespace AuditStash\TestSuite;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;

/**
 * Test assertions for the audit_logs table.
 *
 * Mix into any `TestCase` that exercises behavior wired through the
 * `AuditStash.AuditLog` behavior (or the `Audit::log()` facade) and that
 * loads the `plugin.AuditStash.AuditLogs` fixture. The assertions query the
 * `audit_logs` table directly, so they verify what was actually persisted —
 * not just what an in-memory event queue produced.
 *
 * ```php
 * use AuditStash\TestSuite\AuditAssertionsTrait;
 * use Cake\TestSuite\TestCase;
 *
 * class ArticlesControllerTest extends TestCase
 * {
 *     use AuditAssertionsTrait;
 *
 *     protected array $fixtures = ['plugin.AuditStash.AuditLogs', 'app.Articles'];
 *
 *     public function testArticleCreateIsAudited(): void
 *     {
 *         $this->post('/articles/add', ['title' => 'Hello']);
 *
 *         $this->assertAuditLogged('Articles', 'create');
 *         $this->assertAuditFieldChanged('Articles', 'title', 'Hello');
 *         $this->assertAuditCount(1, 'Articles');
 *     }
 * }
 * ```
 */
trait AuditAssertionsTrait
{
    use LocatorAwareTrait;

    /**
     * Assert that at least one audit log row exists for the given source
     * (and optionally a specific event type — `create`, `update`, `delete`,
     * or any custom string).
     *
     * @param string $source The audit source / table alias (e.g. 'Articles')
     * @param string|null $type Event type to match. `null` matches any type.
     * @param string $message Optional failure message
     *
     * @return void
     */
    public function assertAuditLogged(string $source, ?string $type = null, string $message = ''): void
    {
        $count = $this->buildAuditQuery($source, $type)->count();

        $description = $type !== null
            ? sprintf('audit log for source "%s" of type "%s"', $source, $type)
            : sprintf('audit log for source "%s"', $source);

        $this->assertGreaterThan(
            0,
            $count,
            $message !== '' ? $message : sprintf('Expected at least one %s, got none.', $description),
        );
    }

    /**
     * Inverse of `assertAuditLogged()`. Use to confirm that an action did
     * NOT trigger any audit row for a given source.
     *
     * @param string $source The audit source / table alias
     * @param string|null $type Event type to match. `null` matches any type.
     * @param string $message Optional failure message
     *
     * @return void
     */
    public function assertAuditNotLogged(string $source, ?string $type = null, string $message = ''): void
    {
        $count = $this->buildAuditQuery($source, $type)->count();

        $description = $type !== null
            ? sprintf('audit log for source "%s" of type "%s"', $source, $type)
            : sprintf('audit log for source "%s"', $source);

        $this->assertSame(
            0,
            $count,
            $message !== '' ? $message : sprintf('Expected no %s, got %d.', $description, $count),
        );
    }

    /**
     * Assert the exact number of audit rows. With `$source = null`, counts
     * every row in the table (useful for "no audit logs at all" checks).
     *
     * @param int $expected Expected row count
     * @param string|null $source If given, restrict to this source
     * @param string $message Optional failure message
     *
     * @return void
     */
    public function assertAuditCount(int $expected, ?string $source = null, string $message = ''): void
    {
        $count = $this->buildAuditQuery($source, null)->count();

        $this->assertSame(
            $expected,
            $count,
            $message !== '' ? $message : sprintf(
                'Expected %d audit log(s)%s, got %d.',
                $expected,
                $source !== null ? ' for source "' . $source . '"' : '',
                $count,
            ),
        );
    }

    /**
     * Assert that the most recent audit row for the given source has the
     * specified field present in its `changed` payload. Optionally also
     * assert the changed-to value.
     *
     * Looks at `changed` (which covers `create` and `update` events). For
     * `delete` events use `assertAuditFieldRemoved()`.
     *
     * @param string $source The audit source / table alias
     * @param string $field Field name expected in `changed`
     * @param mixed $expectedValue Optional expected value. Use `null` to skip the value check.
     * @param string $message Optional failure message
     *
     * @return void
     */
    public function assertAuditFieldChanged(
        string $source,
        string $field,
        mixed $expectedValue = null,
        string $message = '',
    ): void {
        $log = $this->buildAuditQuery($source, null)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull(
            $log,
            $message !== '' ? $message : sprintf('No audit log found for source "%s".', $source),
        );

        /** @var array<string, mixed>|null $changed */
        $changed = $log->get('changed');
        $changed = is_array($changed) ? $changed : [];

        $this->assertArrayHasKey(
            $field,
            $changed,
            $message !== '' ? $message : sprintf(
                'Expected field "%s" in changed payload of latest audit row for "%s". Present fields: %s.',
                $field,
                $source,
                $changed ? implode(', ', array_keys($changed)) : '<none>',
            ),
        );

        if (func_num_args() >= 3) {
            $this->assertSame(
                $expectedValue,
                $changed[$field],
                $message !== '' ? $message : sprintf(
                    'Field "%s" in latest audit row for "%s" did not match expected value.',
                    $field,
                    $source,
                ),
            );
        }
    }

    /**
     * Build the base audit-log query used by the assertions.
     *
     * @param string|null $source Source filter, or null to match any source
     * @param string|null $type Event type filter, or null to match any type
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function buildAuditQuery(?string $source, ?string $type): SelectQuery
    {
        $conditions = [];
        if ($source !== null) {
            $conditions['source'] = $source;
        }
        if ($type !== null) {
            $conditions['type'] = $type;
        }

        $query = $this->fetchTable('AuditStash.AuditLogs')->find();
        if ($conditions) {
            $query->where($conditions);
        }

        return $query;
    }
}
