<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Model\Behavior;

use AuditStash\PersisterInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * What a delete entry may hold in `original`.
 */
class AuditDeleteSnapshotTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.Articles',
        'plugin.AuditStash.Comments',
        'plugin.AuditStash.Authors',
    ];

    /**
     * @var \AuditStash\PersisterInterface&object{events: array<\AuditStash\Event\BaseEvent>}
     */
    protected PersisterInterface $persister;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->persister = new class implements PersisterInterface {
            /**
             * @var array<\AuditStash\Event\BaseEvent>
             */
            public array $events = [];

            /**
             * @inheritDoc
             */
            public function logEvents(array $auditLogs): void
            {
                $this->events = array_merge($this->events, $auditLogs);
            }
        };
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        $this->getTableLocator()->clear();

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testDeleteHonorsWhitelist(): void
    {
        $articles = $this->audited('Articles', ['whitelist' => ['title']]);

        $articles->deleteOrFail($articles->get(1));

        $this->assertSame(['title' => 'First Article'], $this->originals('Articles')[0]);
    }

    /**
     * @return void
     */
    public function testDeleteOfPartialEntityHonorsWhitelist(): void
    {
        $articles = $this->audited('Articles', ['whitelist' => ['title']]);

        $articles->deleteOrFail($articles->find()->select(['id'])->where(['id' => 1])->firstOrFail());

        $this->assertSame(['title' => 'First Article'], $this->originals('Articles')[0]);
    }

    /**
     * @return void
     */
    public function testDeleteLeavesOutContainedAssociations(): void
    {
        $articles = $this->audited('Articles');
        $articles->belongsTo('Authors');

        $articles->deleteOrFail($articles->get(1, contain: ['Authors']));

        $this->assertSame(
            ['author_id', 'title', 'body', 'published'],
            array_keys($this->originals('Articles')[0]),
        );
    }

    /**
     * A cascade-deleted record is audited by the rules of its own table.
     *
     * @return void
     */
    public function testCascadeDeleteUsesConfigOfDependentTable(): void
    {
        $this->audited('Comments', ['sensitive' => ['comment'], 'blacklist' => ['user_id']]);
        $articles = $this->audited('Articles', ['cascadeDeletes' => true]);
        $articles->hasMany('Comments', ['dependent' => true]);

        $articles->deleteOrFail($articles->get(1));

        $comments = $this->originals('Comments');
        $this->assertNotEmpty($comments);
        foreach ($comments as $original) {
            $this->assertSame('****', $original['comment']);
            $this->assertArrayNotHasKey('user_id', $original);
        }
    }

    /**
     * Without a behavior of its own, the dependent table falls back to the
     * parent's settings.
     *
     * @return void
     */
    public function testCascadeDeleteOfUnauditedTableUsesParentConfig(): void
    {
        $articles = $this->audited('Articles', ['cascadeDeletes' => true, 'sensitive' => ['comment']]);
        $articles->hasMany('Comments', ['dependent' => true]);

        $articles->deleteOrFail($articles->get(1));

        $comments = $this->originals('Comments');
        $this->assertNotEmpty($comments);
        foreach ($comments as $original) {
            $this->assertSame('****', $original['comment']);
            $this->assertArrayHasKey('user_id', $original);
        }
    }

    /**
     * @param string $alias Table alias
     * @param array<string, mixed> $config Behavior config
     *
     * @return \Cake\ORM\Table
     */
    protected function audited(string $alias, array $config = []): Table
    {
        $table = $this->fetchTable($alias);
        $table->addBehavior('AuditStash.AuditLog', $config);
        $table->getBehavior('AuditLog')->persister($this->persister);

        return $table;
    }

    /**
     * @param string $source Source name
     *
     * @return array<array<string, mixed>>
     */
    protected function originals(string $source): array
    {
        $originals = [];
        foreach ($this->persister->events as $event) {
            if ($event->getSourceName() === $source) {
                $originals[] = $event->getOriginal();
            }
        }

        return $originals;
    }
}
