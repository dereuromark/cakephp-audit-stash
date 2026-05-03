<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Model\Behavior;

use ArrayObject;
use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Event\AuditUpdateEvent;
use AuditStash\Model\Behavior\AuditLogBehavior;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SplObjectStorage;
use Stringable;
use TestApp\Model\Enum\InvoiceTypeEnum;
use TestApp\Model\Enum\PriorityEnum;

class AuditLogBehaviorTest extends TestCase
{
    private ?Table $table;

    private ?AuditLogBehavior $behavior;

    public function setUp(): void
    {
        parent::setUp();
        $this->table = new Table(['table' => 'articles']);
        $this->table->setPrimaryKey('id');
        $this->table->setSchema([
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'body' => ['type' => 'text'],
            'author_id' => ['type' => 'integer'],
        ]);
        $this->behavior = new AuditLogBehavior($this->table, [
            'whitelist' => ['id', 'title', 'body', 'author_id'],
        ]);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Configure::write('AuditStash.saveType', null);
    }

    /**
     * @return void
     */
    public function testOnSaveCreateWithWhitelist()
    {
        $data = [
            'id' => 13,
            'title' => 'The Title',
            'body' => 'The Body',
            'author_id' => 1,
            'something_extra' => true,
        ];
        $entity = new Entity($data, ['markNew' => true]);

        $event = new Event('Model.afterSave');
        $queue = new SplObjectStorage();
        $this->behavior->afterSave($event, $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));
        $result = $queue[$entity];
        $this->assertEquals($result->getOriginal(), $result->getChanged());
        unset($data['something_extra'], $data['id']);
        $this->assertEquals($data, $result->getChanged());
        $this->assertEquals(13, $result->getId());
        $this->assertEquals('articles', $result->getSourceName());
        $this->assertInstanceOf(AuditCreateEvent::class, $result);
    }

    /**
     * @return void
     */
    public function testOnSaveUpdateWithWhitelist()
    {
        $data = [
            'id' => 13,
            'title' => 'The Title',
            'body' => 'The Body',
            'author_id' => 1,
            'something_extra' => true,
        ];
        $entity = new Entity($data, ['markNew' => false, 'markClean' => true]);
        $entity->title = 'Another Title';

        $event = new Event('Model.afterSave');
        $queue = new SplObjectStorage();
        $this->behavior->afterSave($event, $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));
        $result = $queue[$entity];
        $this->assertEquals(['title' => 'Another Title'], $result->getChanged());
        $this->assertEquals(['title' => 'The Title'], $result->getOriginal());
        $this->assertEquals(13, $result->getId());
        $this->assertEquals('articles', $result->getSourceName());
        $this->assertInstanceOf(AuditUpdateEvent::class, $result);
    }

    /**
     * @return void
     */
    public function testSaveCreateWithBlacklist()
    {
        $this->behavior->setConfig('blacklist', ['author_id']);
        $data = [
            'id' => 13,
            'title' => 'The Title',
            'body' => 'The Body',
            'author_id' => 1,
            'something_extra' => true,
        ];
        $entity = new Entity($data, ['markNew' => true]);

        $event = new Event('Model.afterSave');
        $queue = new SplObjectStorage();
        $this->behavior->afterSave($event, $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));
        $result = $queue[$entity];
        $this->assertEquals($result->getOriginal(), $result->getChanged());
        unset($data['something_extra'], $data['author_id'], $data['id']);
        $this->assertEquals($data, $result->getChanged());
    }

    /**
     * @return void
     */
    public function testSaveUpdateWithBlacklist()
    {
        $this->behavior->setConfig('blacklist', ['author_id']);
        $data = [
            'id' => 13,
            'title' => 'The Title',
            'body' => 'The Body',
            'author_id' => 1,
        ];
        $entity = new Entity($data, ['markNew' => false, 'markClean' => true]);
        $entity->author_id = 50;

        $event = new Event('Model.afterSave');
        $queue = new SplObjectStorage();
        $this->behavior->afterSave($event, $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));

        $this->assertFalse(isset($queue[$entity]));
    }

    /**
     * @return void
     */
    public function testSaveWithFieldsFromSchema()
    {
        $this->table->setSchema([
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'text'],
            'body' => ['type' => 'text'],
        ]);
        $this->behavior->setConfig('whitelist', false);
        $data = [
            'id' => 13,
            'title' => 'The Title',
            'body' => 'The Body',
            'author_id' => 1,
            'something_extra' => true,
        ];
        $entity = new Entity($data, ['markNew' => true]);
        $event = new Event('Model.afterSave');
        $queue = new SplObjectStorage();
        $this->behavior->afterSave($event, $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));
        $result = $queue[$entity];
        unset($data['something_extra'], $data['author_id'], $data['id']);
        $this->assertEquals($data, $result->getChanged());
        $this->assertEquals(13, $result->getId());
        $this->assertEquals('articles', $result->getSourceName());
        $this->assertInstanceOf(AuditCreateEvent::class, $result);
    }

    #[DataProvider('dataProviderForSaveType')]
    public function testImplementedEvents(?string $saveType): void
    {
        Configure::write('AuditStash.saveType', $saveType);
        $events = (new AuditLogBehavior(new Table()))->implementedEvents();
        if ($saveType === 'afterSave') {
            $this->assertArrayNotHasKey('Model.afterSaveCommit', $events);
            $this->assertArrayNotHasKey('Model.afterDeleteCommit', $events);
        } else {
            $this->assertArrayHasKey('Model.afterSaveCommit', $events);
            $this->assertArrayHasKey('Model.afterDeleteCommit', $events);
        }
    }

    public static function dataProviderForSaveType(): array
    {
        return [
            'afterSave' => ['afterSave'],
            'afterCommit' => ['afterCommit'],
            'null' => [null],
        ];
    }

    public function testSensitiveFields(): void
    {
        $behavior = new AuditLogBehavior($this->table, [
            'whitelist' => ['id', 'title', 'body', 'author_id'],
            'sensitive' => ['body'],
        ]);

        $data = [
            'id' => 13,
            'title' => 'The Title',
            'body' => 'The Body',
            'author_id' => 1,
        ];
        $entity = new Entity($data, ['markNew' => false, 'markClean' => true]);
        $entity->body = 'The changed body';

        $event = new Event('Model.afterSave');
        $queue = new SplObjectStorage();
        $behavior->afterSave($event, $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));

        $event = $queue[$entity];

        $this->assertInstanceOf(AuditUpdateEvent::class, $event);

        $changed = $event->getChanged();
        $this->assertArrayHasKey('body', $changed);
        $this->assertEquals('****', $changed['body']);
    }

    /**
     * Display field holding a backed enum must serialize to its scalar value
     * instead of throwing "Object of class ... could not be converted to string".
     *
     * @return void
     */
    public function testDisplayValueWithBackedEnum(): void
    {
        $this->table->setDisplayField('title');
        $entity = new Entity([
            'id' => 1,
            'title' => InvoiceTypeEnum::Anzahl,
            'body' => 'Body',
            'author_id' => 1,
        ], ['markNew' => true]);

        $queue = new SplObjectStorage();
        $this->behavior->afterSave(new Event('Model.afterSave'), $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));

        $this->assertSame('Anzahlungsrechnung', $queue[$entity]->getDisplayValue());
    }

    /**
     * @return void
     */
    public function testDisplayValueWithUnitEnum(): void
    {
        $this->table->setDisplayField('title');
        $entity = new Entity([
            'id' => 1,
            'title' => PriorityEnum::First,
            'body' => 'Body',
            'author_id' => 1,
        ], ['markNew' => true]);

        $queue = new SplObjectStorage();
        $this->behavior->afterSave(new Event('Model.afterSave'), $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));

        $this->assertSame('First', $queue[$entity]->getDisplayValue());
    }

    /**
     * @return void
     */
    public function testDisplayValueWithStringableObject(): void
    {
        $this->table->setDisplayField('title');
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'rendered title';
            }
        };
        $entity = new Entity([
            'id' => 1,
            'title' => $stringable,
            'body' => 'Body',
            'author_id' => 1,
        ], ['markNew' => true]);

        $queue = new SplObjectStorage();
        $this->behavior->afterSave(new Event('Model.afterSave'), $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
            'associated' => [],
        ]));

        $this->assertSame('rendered title', $queue[$entity]->getDisplayValue());
    }

    /**
     * @return void
     */
    public function testDisplayValueWithBackedEnumOnDelete(): void
    {
        $this->table->setDisplayField('title');
        $entity = new Entity([
            'id' => 1,
            'title' => InvoiceTypeEnum::Schluss,
            'body' => 'Body',
            'author_id' => 1,
        ], ['markNew' => false, 'markClean' => true]);

        $queue = new SplObjectStorage();
        $this->behavior->afterDelete(new Event('Model.afterDelete'), $entity, new ArrayObject([
            '_auditQueue' => $queue,
            '_auditTransaction' => '1',
        ]));

        $this->assertInstanceOf(AuditDeleteEvent::class, $queue[$entity]);
        $this->assertSame('Schlussrechnung', $queue[$entity]->getDisplayValue());
    }
}
