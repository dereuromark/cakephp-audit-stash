<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Model\Table;

use AuditStash\Model\Table\AuditLogsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * AuditStash\Model\Table\AuditLogsTable Test Case
 *
 * @uses \AuditStash\Model\Table\AuditLogsTable
 */
class AuditLogsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    /**
     * Get the AuditLogs table
     *
     * @return \AuditStash\Model\Table\AuditLogsTable
     */
    protected function getAuditLogsTable(): AuditLogsTable
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $table */
        $table = $this->fetchTable('AuditStash.AuditLogs');

        return $table;
    }

    /**
     * Test findByChangedField method
     *
     * @return void
     */
    public function testFindByChangedField(): void
    {
        // Create test logs with different changed fields
        $log1 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => ['title' => 'New Title', 'body' => 'New Body'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log1);

        $log2 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-2',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'changed' => ['body' => 'Another Body'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log2);

        $log3 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-3',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 3,
            'changed' => ['status' => 'published'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log3);

        // Find logs where 'title' was changed
        $results = $this->getAuditLogsTable()->find('byChangedField', field: 'title')->toArray();

        $this->assertCount(1, $results);
        $this->assertEquals($log1->id, $results[0]->id);

        // Find logs where 'body' was changed
        $results = $this->getAuditLogsTable()->find('byChangedField', field: 'body')->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * Defense-in-depth: a key with JSON-path meta characters must be
     * rejected by the JsonQueryHelper even if the controller boundary is
     * bypassed (Issue #2).
     *
     * @return void
     */
    public function testFindByChangedFieldRejectsPathMetaCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->getAuditLogsTable()->find('byChangedField', field: 'foo.bar')->toArray();
    }

    /**
     * `*` reaches into the JSON-path mini-language; reject it.
     *
     * @return void
     */
    public function testFindByChangedFieldValueRejectsWildcard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->getAuditLogsTable()->find('byChangedFieldValue', field: '*', value: 'x')->toArray();
    }

    /**
     * Test findByChangedFieldValue method
     *
     * @return void
     */
    public function testFindByChangedFieldValue(): void
    {
        // Create test logs with different field values
        $log1 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => ['status' => 'published'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log1);

        $log2 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-2',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'changed' => ['status' => 'draft'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log2);

        $log3 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-3',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 3,
            'changed' => ['status' => 'published'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log3);

        // Find logs where status changed to 'published'
        $results = $this->getAuditLogsTable()->find('byChangedFieldValue', field: 'status', value: 'published')->toArray();

        $this->assertCount(2, $results);
        $ids = array_map(fn ($r) => $r->id, $results);
        $this->assertContains($log1->id, $ids);
        $this->assertContains($log3->id, $ids);

        // Find logs where status changed to 'draft'
        $results = $this->getAuditLogsTable()->find('byChangedFieldValue', field: 'status', value: 'draft')->toArray();

        $this->assertCount(1, $results);
        $this->assertEquals($log2->id, $results[0]->id);
    }

    /**
     * Test findRelatedChanges method
     *
     * @return void
     */
    public function testFindRelatedChanges(): void
    {
        // Create a main record log
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => ['title' => 'Test Article'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        // Create a related record log (comment on the article)
        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'create',
            'source' => 'Comments',
            'primary_key' => 1,
            'parent_source' => 'Articles',
            'changed' => ['article_id' => 1, 'body' => 'Test Comment'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        // Create an unrelated log
        $unrelatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-2',
            'type' => 'create',
            'source' => 'Users',
            'primary_key' => 1,
            'changed' => ['name' => 'Test User'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($unrelatedLog);

        // Find related changes for the article
        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'Articles', primaryKey: 1)->toArray();

        $this->assertCount(2, $results);
        $ids = array_map(fn ($r) => $r->id, $results);
        $this->assertContains($mainLog->id, $ids);
        $this->assertContains($relatedLog->id, $ids);
        $this->assertNotContains($unrelatedLog->id, $ids);
    }

    /**
     * Test findBulkChanges method
     *
     * @return void
     */
    public function testFindBulkChanges(): void
    {
        // Create a bulk transaction with many records
        $bulkTxId = 'bulk-tx-123';
        for ($i = 1; $i <= 10; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction_key' => $bulkTxId,
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => $i,
                'changed' => ['title' => "Article $i"],
                'created' => new DateTime(),
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Create a small transaction with few records
        $smallTxId = 'small-tx-456';
        for ($i = 1; $i <= 2; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction_key' => $smallTxId,
                'type' => 'update',
                'source' => 'Users',
                'primary_key' => $i,
                'changed' => ['name' => "User $i"],
                'created' => new DateTime(),
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Find bulk changes with default threshold (5)
        $results = $this->getAuditLogsTable()->find('bulkChanges')->toArray();

        $this->assertCount(10, $results);
        foreach ($results as $log) {
            $this->assertEquals($bulkTxId, $log->transaction_key);
        }

        // Find bulk changes with higher threshold (8)
        $results = $this->getAuditLogsTable()->find('bulkChanges', minRecords: 8)->toArray();

        $this->assertCount(10, $results);

        // Find bulk changes with very high threshold (15) - should be empty
        $results = $this->getAuditLogsTable()->find('bulkChanges', minRecords: 15)->toArray();

        $this->assertCount(0, $results);
    }

    /**
     * Test findNonBulkChanges method
     *
     * @return void
     */
    public function testFindNonBulkChanges(): void
    {
        // Create a bulk transaction with many records
        $bulkTxId = 'bulk-tx-non-123';
        for ($i = 1; $i <= 10; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction_key' => $bulkTxId,
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => $i,
                'changed' => ['title' => "Article $i"],
                'created' => new DateTime(),
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Create a small transaction with few records
        $smallTxId = 'small-tx-non-456';
        for ($i = 1; $i <= 2; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction_key' => $smallTxId,
                'type' => 'update',
                'source' => 'Users',
                'primary_key' => $i,
                'changed' => ['name' => "User $i"],
                'created' => new DateTime(),
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Find non-bulk changes with default threshold (5) - should get small transaction
        $results = $this->getAuditLogsTable()->find('nonBulkChanges')->toArray();

        $this->assertCount(2, $results);
        foreach ($results as $log) {
            $this->assertEquals($smallTxId, $log->transaction_key);
        }

        // Find non-bulk changes with higher threshold (8) - should still get only small transaction
        $results = $this->getAuditLogsTable()->find('nonBulkChanges', minRecords: 8)->toArray();

        $this->assertCount(2, $results);

        // Find non-bulk changes with threshold lower than small (2) - should get nothing
        $results = $this->getAuditLogsTable()->find('nonBulkChanges', minRecords: 2)->toArray();

        $this->assertCount(0, $results);
    }

    /**
     * Test findBulkChangeStats method
     *
     * @return void
     */
    public function testFindBulkChangeStats(): void
    {
        // Create a bulk transaction with many records across multiple sources
        $bulkTxId = 'bulk-tx-789';
        $now = new DateTime();

        for ($i = 1; $i <= 5; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction_key' => $bulkTxId,
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => $i,
                'user_id' => 'user-1',
                'user_display' => 'Test User',
                'changed' => ['title' => "Article $i"],
                'created' => $now,
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        for ($i = 1; $i <= 3; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction_key' => $bulkTxId,
                'type' => 'create',
                'source' => 'Comments',
                'primary_key' => $i,
                'user_id' => 'user-1',
                'user_display' => 'Test User',
                'changed' => ['body' => "Comment $i"],
                'created' => $now,
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Create a small transaction (should not appear in results)
        $smallTxId = 'small-tx-111';
        $log = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => $smallTxId,
            'type' => 'update',
            'source' => 'Users',
            'primary_key' => 1,
            'changed' => ['name' => 'User 1'],
            'created' => $now,
        ]);
        $this->getAuditLogsTable()->save($log);

        // Get bulk change stats
        $results = $this->getAuditLogsTable()->find('bulkChangeStats', minRecords: 5)->toArray();

        $this->assertCount(1, $results);
        $stat = $results[0];

        $this->assertEquals($bulkTxId, $stat['transaction_key']);
        $this->assertEquals(8, (int)$stat['record_count']);
        $this->assertEquals(2, (int)$stat['sources']);
        // user_id and user_display are included via MAX aggregate
        $this->assertArrayHasKey('user_id', $stat);
        $this->assertArrayHasKey('user_display', $stat);
        $this->assertArrayHasKey('created', $stat);
    }

    /**
     * Test getDistinctChangedFields method
     *
     * @return void
     */
    public function testGetDistinctChangedFields(): void
    {
        // Create logs with various fields
        $log1 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => ['title' => 'New Title', 'body' => 'New Body'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log1);

        $log2 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-2',
            'type' => 'update',
            'source' => 'Users',
            'primary_key' => 1,
            'changed' => ['name' => 'New Name', 'email' => 'new@email.com'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log2);

        $log3 = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-3',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'changed' => ['title' => 'Another Title', 'status' => 'published'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log3);

        $fields = $this->getAuditLogsTable()->getDistinctChangedFields();

        $this->assertContains('title', $fields);
        $this->assertContains('body', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('email', $fields);
        $this->assertContains('status', $fields);
        $this->assertCount(5, $fields);
    }

    /**
     * Test buildForeignKeyName method via findRelatedChanges
     *
     * This indirectly tests the protected buildForeignKeyName method
     * by checking that the related changes finder correctly identifies
     * records with the appropriate foreign key.
     *
     * @return void
     */
    public function testBuildForeignKeyNameViaPascalCase(): void
    {
        // Create a main record with PascalCase source name
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'create',
            'source' => 'UserProfiles',
            'primary_key' => 1,
            'changed' => ['bio' => 'Test bio'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        // Create a related record with snake_case foreign key
        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'test-tx-1',
            'type' => 'create',
            'source' => 'ProfilePhotos',
            'primary_key' => 1,
            'parent_source' => 'UserProfiles',
            'changed' => ['user_profile_id' => 1, 'url' => 'photo.jpg'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        // Find related changes - should find both
        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'UserProfiles', primaryKey: 1)->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * Regression: `Categories` must resolve to `category_id`, not the
     * `categorie_id` the prior naive `rtrim($x, 's')` produced. Without
     * proper singularization the related-changes finder silently fails to
     * link child rows to their parent audit entry on every `-ies` plural
     * (Categories, Stories, Companies, Properties, ...).
     *
     * @return void
     */
    public function testFindRelatedChangesResolvesIesPluralSource(): void
    {
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-cat-1',
            'type' => 'create',
            'source' => 'Categories',
            'primary_key' => 7,
            'changed' => ['name' => 'Books'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-cat-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => 1,
            'parent_source' => 'Categories',
            'changed' => ['category_id' => 7, 'title' => 'Hello'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'Categories', primaryKey: 7)->toArray();

        $this->assertCount(2, $results, 'Both the direct row and the FK-linked child row must be returned.');
    }

    /**
     * Regression: irregular plural — `People` must resolve to `person_id`,
     * not the `peopl_id` the prior naive trim produced.
     *
     * @return void
     */
    public function testFindRelatedChangesResolvesIrregularPluralSource(): void
    {
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-people-1',
            'type' => 'create',
            'source' => 'People',
            'primary_key' => 42,
            'changed' => ['name' => 'Ada'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-people-1',
            'type' => 'create',
            'source' => 'Addresses',
            'primary_key' => 1,
            'parent_source' => 'People',
            'changed' => ['person_id' => 42, 'city' => 'London'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'People', primaryKey: 42)->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * Regression: uncountable / already-singular source — `News` must
     * resolve to `news_id`, not the `new_id` the prior trim produced.
     *
     * @return void
     */
    public function testFindRelatedChangesResolvesUncountableSource(): void
    {
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-news-1',
            'type' => 'create',
            'source' => 'News',
            'primary_key' => 3,
            'changed' => ['headline' => 'Hi'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-news-1',
            'type' => 'create',
            'source' => 'Comments',
            'primary_key' => 1,
            'parent_source' => 'News',
            'changed' => ['news_id' => 3, 'body' => 'first!'],
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'News', primaryKey: 3)->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * Empty filter set must be a pass-through — every audit log row is
     * returned and the helper does not silently inject WHERE clauses.
     *
     * @return void
     */
    public function testFindForFiltersWithEmptyParamsReturnsAll(): void
    {
        $this->seedForFilterTests();

        $results = $this->getAuditLogsTable()->find('forFilters', params: [])->toArray();

        $this->assertCount(6, $results);
    }

    /**
     * Base filters (source / type / transaction_key / primary_key) compose
     * with WHERE clauses; mirrors the previous controller-side
     * `applyBaseFilters` behavior so the refactor is observably equivalent
     * for the simple cases.
     *
     * @return void
     */
    public function testFindForFiltersAppliesBaseFilters(): void
    {
        $this->seedForFilterTests();

        $results = $this->getAuditLogsTable()
            ->find('forFilters', params: ['source' => 'Articles'])
            ->toArray();

        $this->assertCount(4, $results);
        foreach ($results as $row) {
            $this->assertSame('Articles', $row->source);
        }
    }

    /**
     * `user_id` is a substring LIKE match — preserved from the original
     * helper. Wildcards in the input must be escaped so a literal `%`
     * cannot turn the filter into a match-all.
     *
     * @return void
     */
    public function testFindForFiltersUserIdIsEscapedSubstringMatch(): void
    {
        $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-user-a',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 'admin-42',
            'created' => new DateTime(),
        ]));
        $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'tx-user-b',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'user_id' => 'editor-9',
            'created' => new DateTime(),
        ]));

        $hit = $this->getAuditLogsTable()
            ->find('forFilters', params: ['user_id' => 'admin'])
            ->toArray();
        $this->assertCount(1, $hit);

        // A literal `%` must not match all rows.
        $miss = $this->getAuditLogsTable()
            ->find('forFilters', params: ['user_id' => '%'])
            ->toArray();
        $this->assertCount(0, $miss);
    }

    /**
     * `date_from` / `date_to` are inclusive day boundaries.
     *
     * @return void
     */
    public function testFindForFiltersDateRangeIsInclusive(): void
    {
        $today = DateTime::now();
        $oneMonthAgo = $today->subMonths(1);
        $sixMonthsAgo = $today->subMonths(6);

        foreach ([$today, $oneMonthAgo, $sixMonthsAgo] as $i => $when) {
            $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
                'transaction_key' => 'tx-date-' . $i,
                'type' => 'update',
                'source' => 'Articles',
                'primary_key' => $i + 1,
                'created' => $when,
            ]));
        }

        // Floor at 2 months ago: keeps today + one-month-ago, drops six-months-ago.
        $results = $this->getAuditLogsTable()
            ->find('forFilters', params: ['date_from' => $today->subMonths(2)->format('Y-m-d')])
            ->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * `changed_field` filter routes through {@see findByChangedField}.
     *
     * @return void
     */
    public function testFindForFiltersAppliesChangedFieldFilter(): void
    {
        $this->seedForFilterTests();

        $results = $this->getAuditLogsTable()
            ->find('forFilters', params: ['changed_field' => 'title'])
            ->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * `field_name` + `field_value` route through
     * {@see findByChangedFieldValue}; both must be set for the filter to fire.
     *
     * @return void
     */
    public function testFindForFiltersAppliesFieldNameValueFilter(): void
    {
        $this->seedForFilterTests();

        $hit = $this->getAuditLogsTable()
            ->find('forFilters', params: ['field_name' => 'status', 'field_value' => 'published'])
            ->toArray();
        $this->assertCount(1, $hit);

        // `field_name` alone does NOT trigger the value finder — empty
        // `field_value` is a no-op (matches the index-page UX where the
        // user has typed a name but not yet a value).
        $unfiltered = $this->getAuditLogsTable()
            ->find('forFilters', params: ['field_name' => 'status'])
            ->toArray();
        $this->assertCount(6, $unfiltered);
    }

    /**
     * `bulk_filter=yes/no` route through {@see findBulkChanges} /
     * {@see findNonBulkChanges} with `min_records` honored.
     *
     * @return void
     */
    public function testFindForFiltersAppliesBulkFilter(): void
    {
        // Bulk transaction (≥5 rows).
        for ($i = 1; $i <= 6; $i++) {
            $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
                'transaction_key' => 'bulk-tx',
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => $i,
                'created' => new DateTime(),
            ]));
        }
        // Non-bulk transaction.
        $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
            'transaction_key' => 'small-tx',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => 99,
            'created' => new DateTime(),
        ]));

        $bulk = $this->getAuditLogsTable()
            ->find('forFilters', params: ['bulk_filter' => 'yes'])
            ->toArray();
        $this->assertCount(6, $bulk);

        $nonBulk = $this->getAuditLogsTable()
            ->find('forFilters', params: ['bulk_filter' => 'no'])
            ->toArray();
        $this->assertCount(1, $nonBulk);
    }

    /**
     * The whole point of routing through one finder: filters compose. The
     * old controller code applied finders by re-assigning `$query`, which
     * silently dropped earlier filters when more than one advanced filter
     * was set.
     *
     * @return void
     */
    public function testFindForFiltersComposesBaseAndAdvancedFilters(): void
    {
        // Bulk transaction on Articles, all changing `title`.
        for ($i = 1; $i <= 6; $i++) {
            $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
                'transaction_key' => 'bulk-articles',
                'type' => 'update',
                'source' => 'Articles',
                'primary_key' => $i,
                'changed' => ['title' => 'New ' . $i],
                'created' => new DateTime(),
            ]));
        }
        // Bulk transaction on Comments, none changing `title`.
        for ($i = 1; $i <= 6; $i++) {
            $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
                'transaction_key' => 'bulk-comments',
                'type' => 'update',
                'source' => 'Comments',
                'primary_key' => $i,
                'changed' => ['body' => 'New ' . $i],
                'created' => new DateTime(),
            ]));
        }

        $results = $this->getAuditLogsTable()
            ->find('forFilters', params: [
                'source' => 'Articles',
                'changed_field' => 'title',
                'bulk_filter' => 'yes',
            ])
            ->toArray();

        $this->assertCount(6, $results);
        foreach ($results as $row) {
            $this->assertSame('Articles', $row->source);
            $this->assertSame('bulk-articles', $row->transaction_key);
        }
    }

    /**
     * Seed six logs covering Articles + Comments and a small set of
     * `changed` fields, used by the simpler `findForFilters` tests.
     *
     * @return void
     */
    private function seedForFilterTests(): void
    {
        $rows = [
            ['source' => 'Articles', 'changed' => ['title' => 'A1']],
            ['source' => 'Articles', 'changed' => ['title' => 'A2']],
            ['source' => 'Articles', 'changed' => ['body' => 'B1']],
            ['source' => 'Articles', 'changed' => ['status' => 'published']],
            ['source' => 'Comments', 'changed' => ['body' => 'C1']],
            ['source' => 'Comments', 'changed' => ['author' => 'D1']],
        ];
        foreach ($rows as $i => $row) {
            $this->getAuditLogsTable()->saveOrFail($this->getAuditLogsTable()->newEntity([
                'transaction_key' => 'seed-tx-' . $i,
                'type' => 'update',
                'source' => $row['source'],
                'primary_key' => $i + 1,
                'changed' => $row['changed'],
                'created' => new DateTime(),
            ]));
        }
    }
}
