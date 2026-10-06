<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Command;

use AuditStash\Event\AuditSnapshotEvent;
use AuditStash\EventFactory;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SnapshotCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = ['plugin.AuditStash.AuditLogs', 'plugin.AuditStash.Articles'];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('AuditStash.persister', 'AuditStash\Persister\TablePersister');
        $this->fetchTable('Articles')->addBehavior('AuditStash.AuditLog');
    }

    public function testSnapshotsAndSecondRun(): void
    {
        $this->exec('audit_stash snapshot Articles --batch-size 1');
        $this->assertExitSuccess();
        $this->assertOutputContains('Snapshots written: 3');
        $logs = $this->fetchTable('AuditStash.AuditLogs');
        $this->assertSame(3, $logs->find()->count());
        $log = $logs->find()->orderByAsc('id')->firstOrFail();
        $this->assertSame('snapshot', $log->type);
        $this->assertSame([
            'author_id' => 1,
            'title' => 'First Article',
            'body' => 'First Article Body',
            'published' => 'Y',
        ], $log->original);
        $this->assertSame([], $log->changed);
        $this->exec('audit_stash snapshot Articles');
        $this->assertExitSuccess();
        $this->assertOutputContains('Snapshots written: 0');
        $this->assertSame(3, $logs->find()->count());
    }

    public function testExistingHistory(): void
    {
        $logs = $this->fetchTable('AuditStash.AuditLogs');
        $logs->saveOrFail($logs->newEntity([
            'transaction_key' => 'existing',
            'source' => 'Articles',
            'primary_key' => 1,
            'type' => 'update',
        ]));
        $this->exec('audit_stash snapshot Articles');
        $this->assertExitSuccess();
        $this->assertOutputContains('Snapshots written: 2');
        $this->assertOutputContains('Skipped (already have history): 1');
        $this->assertSame(3, $logs->find()->count());
    }

    public function testDryRun(): void
    {
        $this->exec('audit_stash snapshot Articles --dry-run');
        $this->assertExitSuccess();
        $this->assertOutputContains('Snapshots would be written: 3');
        $this->assertSame(0, $this->fetchTable('AuditStash.AuditLogs')->find()->count());
    }

    public function testMissingBehavior(): void
    {
        $this->fetchTable('Articles')->removeBehavior('AuditLog');
        $this->exec('audit_stash snapshot Articles');
        $this->assertExitError();
        $this->assertErrorContains('AuditLog behavior');
    }

    /**
     * @param string $value Invalid batch size
     */
    #[DataProvider('invalidBatchSizes')]
    public function testInvalidBatchSize(string $value): void
    {
        $this->exec('audit_stash snapshot Articles --batch-size ' . $value);
        $this->assertExitError();
        $this->assertErrorContains('Batch size must be an integer');
    }

    /**
     * @return array<string, array<string>>
     */
    public static function invalidBatchSizes(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'fraction' => ['1.5'], 'text' => ['abc']];
    }

    public function testCompositePrimaryKey(): void
    {
        $this->fetchTable('Articles')->setPrimaryKey(['id', 'author_id']);
        $this->fetchTable('AuditStash.AuditLogs')->getSchema()->setColumnType('primary_key', 'string');
        $this->exec('audit_stash snapshot Articles --batch-size 1');
        $this->assertExitSuccess();
        $logs = $this->fetchTable('AuditStash.AuditLogs');
        $log = $logs->find()->orderByAsc('id')->firstOrFail();
        $this->assertSame('{"id":1,"author_id":1}', $log->primary_key);
        $this->exec('audit_stash snapshot Articles');
        $this->assertExitSuccess();
        $this->assertOutputContains('Snapshots written: 0');
        $this->assertSame(3, $logs->find()->count());
    }

    public function testNonTablePersister(): void
    {
        Configure::write('AuditStash.persister', 'AuditStash\\Persister\\ElasticSearchPersister');
        $this->exec('audit_stash snapshot Articles');
        $this->assertExitError();
        $this->assertErrorContains('only works with TablePersister');
    }

    public function testSnapshotEventRoundTrip(): void
    {
        $event = new AuditSnapshotEvent('transaction', ['id' => 1], 'Articles', ['title' => 'Title'], 'Title');
        $restored = unserialize(serialize($event));
        $this->assertSame(['title' => 'Title'], $restored->getOriginal());
        $this->assertSame([], $restored->getChanged());
        $json = json_decode((string)json_encode($event), true);
        $this->assertSame(['title' => 'Title'], $json['original']);
        $factory = new EventFactory();
        $restored = $factory->create([
            'type' => 'snapshot',
            'transaction_key' => 'transaction',
            'primary_key' => 1,
            'source' => 'Articles',
            'original' => ['title' => 'Title'],
            'display_value' => 'Title',
            '@timestamp' => $event->getTimestamp(),
            'meta' => [],
        ]);
        $this->assertInstanceOf(AuditSnapshotEvent::class, $restored);
        $this->assertSame(['title' => 'Title'], $restored->getOriginal());
        $this->assertSame([], $restored->getChanged());
        $this->assertSame('Title', $restored->getDisplayValue());
    }

    public function testSnapshotFieldSelection(): void
    {
        $table = $this->fetchTable('Articles');
        $behavior = $table->getBehavior('AuditLog');
        $behavior->setConfig('whitelist', ['title', 'body', 'id', 'virtual']);
        $behavior->setConfig('sensitive', ['body']);
        $this->assertSame(0, $behavior->snapshot([]));
        $this->assertSame(3, $behavior->snapshot($table->find()->all()));
        $logs = $this->fetchTable('AuditStash.AuditLogs')->find()->all()->toList();
        $this->assertSame(['title' => 'First Article', 'body' => '****'], $logs[0]->original);
        $this->assertSame($logs[0]->transaction_key, $logs[1]->transaction_key);
    }

    public function testFailedWritesAreReported(): void
    {
        $logs = $this->fetchTable('AuditStash.AuditLogs');
        $logs->getEventManager()->on('Model.beforeSave', function ($event, $entity): void {
            if ((string)$entity->primary_key === '2') {
                $event->setResult(false);
            }
        });

        $this->exec('audit_stash snapshot Articles');

        $this->assertExitError();
        $this->assertOutputContains('Snapshots written: 2');
        $this->assertErrorContains('could not be written: 1');
        $this->assertSame(2, $logs->find()->count());
    }

    public function testRowsDeletedDuringRunDoNotShiftBatches(): void
    {
        $articles = $this->fetchTable('Articles');
        $deleted = false;
        $this->fetchTable('AuditStash.AuditLogs')->getEventManager()->on(
            'Model.afterSave',
            function () use ($articles, &$deleted): void {
                if (!$deleted) {
                    $deleted = true;
                    $articles->deleteAll(['id' => 1]);
                }
            },
        );

        $this->exec('audit_stash snapshot Articles --batch-size 1');

        $this->assertExitSuccess();
        $this->assertOutputContains('Snapshots written: 3');
    }
}
