<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Model\Behavior;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Event\AuditUpdateEvent;
use AuditStash\Model\Behavior\AuditLogBehavior;
use AuditStash\Test\DebugPersister;
use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

class AuditIntegrationTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * Fixtures to use.
     *
     * @var array
     */
    public array $fixtures = [
        'plugin.AuditStash.Articles',
        'plugin.AuditStash.Comments',
        'plugin.AuditStash.Authors',
        'plugin.AuditStash.Tags',
        'plugin.AuditStash.ArticlesTags',
    ];

    private ?Table $table = null;

    private mixed $persister;

    /**
     * tests setup.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->table = $this->fetchTable('Articles');
        $this->table->hasMany('Comments');
        $this->table->belongsToMany('Tags');
        $this->table->belongsTo('Authors');
        $this->table->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);

        $this->persister = $this->createMock(DebugPersister::class);
        $this->table->behaviors()->get('AuditLog')->persister($this->persister);
    }

    /**
     * Tests that creating an article means having one audit log create event.
     *
     * @return void
     */
    public function testCreateArticle()
    {
        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'author_id' => 1,
            'body' => 'new article body',
        ]);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditCreateEvent::class, $event);
                $this->assertEquals(4, $event->getId());
                $this->assertEquals('Articles', $event->getSourceName());
                $this->assertEquals(
                    $event->getOriginal(),
                    array_intersect_assoc($event->getOriginal(), $event->getChanged()),
                );
                $this->assertNotEmpty($event->getTransactionId());
                $expected = [
                    'title' => 'New Article',
                    'author_id' => 1,
                    'body' => 'new article body',
                ];
                $this->assertEquals($expected, $event->getChanged());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that updating an article means having one audit log update event.
     *
     * @return void
     */
    public function testUpdateArticle()
    {
        $entity = $this->table->get(1);
        $entity->title = 'Changed title';

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditUpdateEvent::class, $event);
                $this->assertEquals(1, $event->getId());
                $this->assertEquals('Articles', $event->getSourceName());
                $expected = [
                    'title' => 'Changed title',
                ];
                $this->assertEquals($expected, $event->getChanged());
                $this->assertNotEmpty($event->getTransactionId());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that adding a belongsTo association means having one update
     * log event for the main entity.
     *
     * @return void
     */
    public function testCreateArticleWithExisitingBelongsTo()
    {
        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'body' => 'new article body',
        ]);
        $entity->author = $this->table->Authors->get(1);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditCreateEvent::class, $event);
                $this->assertEquals(4, $event->getId());
                $this->assertEquals('Articles', $event->getSourceName());
                $changed = $event->getChanged();
                $this->assertEquals(1, $changed['author_id']);
                $this->assertFalse(isset($changed['author']));
                $this->assertNotEmpty($event->getTransactionId());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that adding a belongsTo association means having one update
     * log event for the main entity.
     *
     * @return void
     */
    public function testUpdateArticleWithExistingBelongsTo()
    {
        $entity = $this->table->get(primaryKey: 1, contain: ['Authors']);
        $entity->title = 'Changed title';
        $entity->author = $this->table->Authors->get(2);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditUpdateEvent::class, $event);
                $this->assertEquals(1, $event->getId());
                $this->assertEquals('Articles', $event->getSourceName());
                $expected = [
                    'title' => 'Changed title',
                    'author_id' => 2,
                ];
                $this->assertEquals($expected, $event->getChanged());
                $this->assertFalse(isset($changed['author']));
                $this->assertNotEmpty($event->getTransactionId());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that adding a new belongsTo entity means having one update
     * log event for the main entity and one of the new belongsto entity.
     *
     * @return void
     */
    public function testCreateArticleWithNewBelongsTo()
    {
        $this->table->Authors->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);
        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'body' => 'new article body',
            'author' => [
                'name' => 'Jose',
            ],
        ]);
        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(2, $events);
                $this->assertEquals('Authors', $events[0]->getSourceName());
                $this->assertEquals('Articles', $events[1]->getSourceName());
                $this->assertInstanceOf(AuditCreateEvent::class, $events[0]);
                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());
                $this->assertEquals(['name' => 'Jose'], $events[0]->getChanged());
                $this->assertFalse(isset($events[1]->getChanged()['author']));
                $this->assertEquals('new article body', $events[1]->getChanged()['body']);
            });

        $this->table->save($entity);
    }

    /**
     * Tests that adding has many entities means one event for each of the updated
     * associated entities.
     *
     * @return void
     */
    public function testUpdateArticleWithHasMany()
    {
        $this->table->Comments->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);

        $entity = $this->table->get(primaryKey: 1, contain: ['Comments']);
        $entity->comments[] = $this->table->Comments->newEntity([
            'user_id' => 1,
            'comment' => 'This is a comment',
        ]);
        $entity->comments[] = $this->table->Comments->newEntity([
            'user_id' => 1,
            'comment' => 'This is another comment',
        ]);
        $entity->setDirty('comments', true);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(2, $events);
                $this->assertEquals('Comments', $events[0]->getSourceName());
                $this->assertEquals('Comments', $events[1]->getSourceName());
                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());
                $expected = [
                    'article_id' => 1,
                    'user_id' => 1,
                    'comment' => 'This is a comment',
                ];
                $this->assertEquals($expected, $events[0]->getChanged());
                $expected = [
                    'article_id' => 1,
                    'user_id' => 1,
                    'comment' => 'This is another comment',
                ];
                $this->assertEquals($expected, $events[1]->getChanged());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that adding has many entities means one event for each of the updated
     * associated entities and finally and event for the main entity if it is new.
     *
     * @return void
     */
    public function testCreateArticleWithHasMany()
    {
        $this->table->Comments->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);

        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'body' => 'new article body',
            'comments' => [
                ['comment' => 'This is a comment', 'user_id' => 1],
                ['comment' => 'This is another comment', 'user_id' => 1],
            ],
        ]);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(3, $events);
                $this->assertEquals('Comments', $events[0]->getSourceName());
                $this->assertEquals('Articles', $events[0]->getParentSourceName());
                $this->assertEquals('Comments', $events[1]->getSourceName());
                $this->assertEquals('Articles', $events[2]->getSourceName());
                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[2]->getTransactionId());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that adding belongsToMany entities means log events for each new
     * entity in the target table and events for as many entities got saved in the
     * junction table.
     *
     * @return void
     */
    public function testUpdateWithBelongsToMany()
    {
        $this->table->Tags->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);
        $this->table->Tags->junction()->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);

        $entity = $this->table->get(primaryKey: 1, contain: ['Tags']);
        $entity->tags[] = $this->table->Tags->newEntity([
            'name' => 'This is a Tag',
        ]);
        $entity->tags[] = $this->table->Tags->get(3);
        $entity->setDirty('tags', true);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(3, $events);
                $this->assertEquals('Tags', $events[0]->getSourceName());
                $this->assertEquals('ArticlesTags', $events[1]->getSourceName());
                $this->assertEquals('ArticlesTags', $events[2]->getSourceName());
                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());
            });

        $this->table->save($entity);
    }

    /**
     * Tests that deleting an entity logs a single event.
     *
     * @return void
     */
    public function testDelete()
    {
        $entity = $this->table->get(1);
        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(1, $events);
                $this->assertinstanceOf(AuditDeleteEvent::class, $events[0]);
                $this->assertEquals(1, $events[0]->getId());
                $this->assertEquals('Articles', $events[0]->getSourceName());
                $this->assertNotEmpty($events[0]->getTransactionId());
            });

        $this->table->delete($entity);
    }

    /**
     * Tests that a bulk deleteMany logs exactly one audit event per entity.
     *
     * Regression test for the N^2 duplication: CakePHP shares a single options
     * object (and therefore one `_auditQueue`) across all entities of a
     * `deleteMany()` and then dispatches `Model.afterDeleteCommit` once per
     * entity. Before the fix every dispatch re-flushed the whole queue, so
     * deleting N rows produced N*N audit records.
     *
     * @return void
     */
    public function testDeleteManyLogsOneEventPerEntity()
    {
        $entities = $this->table->find()->all()->toList();
        $count = count($entities);
        $this->assertGreaterThan(1, $count, 'Need multiple rows to exercise the bulk path.');

        $persistedIds = [];
        $this->persister
            ->expects($this->atLeastOnce())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) use (&$persistedIds): void {
                foreach ($events as $event) {
                    $this->assertInstanceOf(AuditDeleteEvent::class, $event);
                    $persistedIds[] = $event->getId();
                }
            });

        $this->table->deleteManyOrFail($entities);

        // Exactly one event per entity (was $count * $count before the fix).
        $this->assertCount(
            $count,
            $persistedIds,
            'deleteMany must log exactly one audit event per entity, not N^2.',
        );
        // And every entity logged once, none duplicated.
        $expectedIds = array_map(
            fn ($entity): mixed => $entity->get('id'),
            $entities,
        );
        sort($expectedIds);
        sort($persistedIds);
        $this->assertSame($expectedIds, $persistedIds);
    }

    /**
     * Tests that the `afterSave` save strategy also logs exactly one event per
     * entity on a bulk deleteMany.
     *
     * In this strategy `afterCommit()` is invoked inline (per entity, during the
     * transaction) instead of via the commit events. The events accumulate in
     * the shared queue, so without clearing it every inline flush re-persisted
     * the already-queued events (1 + 2 + ... + N records).
     *
     * @return void
     */
    public function testDeleteManyWithAfterSaveStrategyLogsOneEventPerEntity()
    {
        Configure::write('AuditStash.saveType', 'afterSave');
        try {
            // Build a fresh, isolated table so the behavior is only ever
            // registered with the afterSave strategy (implementedEvents is
            // evaluated when the behavior is added).
            $locator = $this->getTableLocator();
            $locator->clear();

            $table = $locator->get('Articles');
            $table->addBehavior('AuditLog', [
                'className' => AuditLogBehavior::class,
            ]);
            $table->behaviors()->get('AuditLog')->persister($this->persister);

            $entities = $table->find()->all()->toList();
            $count = count($entities);
            $this->assertGreaterThan(1, $count, 'Need multiple rows to exercise the bulk path.');

            $persistedIds = [];
            $this->persister
                ->expects($this->atLeastOnce())
                ->method('logEvents')
                ->willReturnCallback(function (array $events) use (&$persistedIds): void {
                    foreach ($events as $event) {
                        $this->assertInstanceOf(AuditDeleteEvent::class, $event);
                        $persistedIds[] = $event->getId();
                    }
                });

            $table->deleteManyOrFail($entities);

            $this->assertCount(
                $count,
                $persistedIds,
                'afterSave strategy must log exactly one audit event per entity.',
            );
        } finally {
            Configure::write('AuditStash.saveType', null);
            $this->getTableLocator()->clear();
        }
    }

    /**
     * Tests that deleting an entity with cascading delete.
     *
     * @return void
     */
    public function testDeleteCascade()
    {
        $this->table->Tags->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);
        $this->table->Tags->junction()->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);
        $this->table->Comments->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class,
        ]);
        $entity = $this->table->get(primaryKey: 1, contain: ['Comments', 'Tags']);

        $this->table->Comments->setDependent(true);
        $this->table->Comments->setCascadeCallbacks(true);

        $this->table->Tags->setDependent(true);
        $this->table->Tags->setCascadeCallbacks(true);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                $this->assertCount(5, $events);
                $id = $events[0]->getTransactionId();
                foreach ($events as $event) {
                    $this->assertinstanceOf(AuditDeleteEvent::class, $event);
                    $this->assertNotEmpty($event->getTransactionId());
                    $this->assertEquals($id, $event->getTransactionId());
                }
                $this->assertEquals('Comments', $events[0]->getSourceName());
                $this->assertEquals('Comments', $events[1]->getSourceName());
                $this->assertEquals('ArticlesTags', $events[2]->getSourceName());
                $this->assertEquals('ArticlesTags', $events[3]->getSourceName());
                $this->assertEquals('Articles', $events[4]->getSourceName());
            });

        $this->table->delete($entity);
    }

    /**
     * Tests that cascade-deleted dependent records are logged when cascadeDeletes
     * option is enabled, even without AuditLogBehavior on dependent tables.
     *
     * This is useful when:
     * - Dependent tables don't have AuditLogBehavior attached
     * - You don't want to use setCascadeCallbacks(true) for performance reasons
     *
     * @return void
     */
    public function testDeleteWithCascadeDeletesOption()
    {
        // Configure the behavior with cascadeDeletes enabled
        $this->table->behaviors()->get('AuditLog')->setConfig('cascadeDeletes', true);

        // Set up hasMany association with dependent = true
        // Note: We're NOT adding AuditLogBehavior to Comments
        $this->table->Comments->setDependent(true);

        $entity = $this->table->get(1);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                // Should log: 2 comments (cascade) + 1 article (parent)
                $this->assertCount(3, $events);

                $transactionId = $events[0]->getTransactionId();
                foreach ($events as $event) {
                    $this->assertInstanceOf(AuditDeleteEvent::class, $event);
                    $this->assertNotEmpty($event->getTransactionId());
                    $this->assertEquals($transactionId, $event->getTransactionId());
                }

                // First two events should be the cascade-deleted comments
                $this->assertEquals('Comments', $events[0]->getSourceName());
                $this->assertEquals('Articles', $events[0]->getParentSourceName());
                $this->assertEquals('Comments', $events[1]->getSourceName());
                $this->assertEquals('Articles', $events[1]->getParentSourceName());

                // Last event should be the parent article
                $this->assertEquals('Articles', $events[2]->getSourceName());
                $this->assertNull($events[2]->getParentSourceName());

                // Verify cascade-deleted records have original data captured
                $this->assertNotEmpty($events[0]->getOriginal());
                $this->assertArrayHasKey('comment', $events[0]->getOriginal());
            });

        $this->table->delete($entity);
    }

    /**
     * Tests that cascadeDeletes option is disabled by default (backward compatible).
     *
     * @return void
     */
    public function testDeleteWithCascadeDeletesDisabledByDefault()
    {
        // Set up dependent association but don't enable cascadeDeletes
        $this->table->Comments->setDependent(true);

        $entity = $this->table->get(1);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->willReturnCallback(function (array $events) {
                // Should only log the parent article, not the cascade-deleted comments
                $this->assertCount(1, $events);
                $this->assertEquals('Articles', $events[0]->getSourceName());
            });

        $this->table->delete($entity);
    }
}
