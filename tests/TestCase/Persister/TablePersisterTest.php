<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Persister;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Model\Table\AuditLogsTable as PluginAuditLogsTable;
use AuditStash\Persister\TablePersister;
use AuditStash\Test\AuditLogsTable;
use Cake\Database\Schema\TableSchemaInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;

class TablePersisterTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \AuditStash\Persister\TablePersister
     */
    public TablePersister $TablePersister;

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->TablePersister = new TablePersister();
        $this->getTableLocator()->setConfig('AuditLogs', [
            'className' => AuditLogsTable::class,
        ]);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown(): void
    {
        unset($this->TablePersister);

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testConfigDefaults()
    {
        $expected = [
            'extractMetaFields' => false,
            'logErrors' => true,
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_AUTOMATIC,
            'serializeFields' => true,
            'table' => 'AuditStash.AuditLogs',
            'unsetExtractedMetaFields' => true,
        ];
        $this->assertEquals($expected, $this->TablePersister->getConfig());
    }

    /**
     * @return void
     */
    public function testGetTableDefault()
    {
        $this->assertInstanceOf(PluginAuditLogsTable::class, $this->TablePersister->getTable());
    }

    /**
     * @return void
     */
    public function testSetTableAsAlias()
    {
        $this->assertInstanceOf(PluginAuditLogsTable::class, $this->TablePersister->getTable());
        $this->assertInstanceOf(TablePersister::class, $this->TablePersister->setTable('Custom'));
        $this->assertInstanceOf(Table::class, $this->TablePersister->getTable());
        $this->assertEquals('Custom', $this->TablePersister->getTable()->getAlias());
    }

    /**
     * @return void
     */
    public function testSetTableAsObject()
    {
        $customTable = $this->getTableLocator()->get('Custom');
        $this->assertInstanceOf(PluginAuditLogsTable::class, $this->TablePersister->getTable());
        $this->assertInstanceOf(TablePersister::class, $this->TablePersister->setTable($customTable));
        $this->assertSame($customTable, $this->TablePersister->getTable());
    }

    /**
     * @return void
     */
    public function testSetInvalidTable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `$table` argument must be either a table alias, or an instance of `\Cake\ORM\Table`.');
        $this->TablePersister->setTable(null);
    }

    /**
     * @return void
     */
    public function testSerializeNull()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', null, null, new Entity());
        $event->setMetaInfo([]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => null,
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => json_encode([]),
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);

        $this->TablePersister->setTable($AuditLogsTable);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testExtractMetaFields()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'foo' => 'bar',
            'baz' => [
                'nested' => 'value',
                'bar' => 'foo',
            ],
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '{"baz":{"bar":"foo"}}',
            'foo' => 'bar',
            'nested' => 'value',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'extractMetaFields' => [
                'foo',
                'baz.nested' => 'nested',
            ],
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testExtractAllMetaFields()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'foo' => 'bar',
            'baz' => [
                'nested' => 'value',
                'bar' => 'foo',
            ],
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '[]',
            'foo' => 'bar',
            'baz' => [
                'nested' => 'value',
                'bar' => 'foo',
            ],
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'extractMetaFields' => true,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testExtractMetaFieldsDoNotUnset()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'foo' => 'bar',
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '{"foo":"bar"}',
            'foo' => 'bar',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'extractMetaFields' => [
                'foo',
            ],
            'unsetExtractedMetaFields' => false,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testExtractAllMetaFieldsDoNotUnset()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'foo' => 'bar',
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '{"foo":"bar"}',
            'foo' => 'bar',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'extractMetaFields' => true,
            'unsetExtractedMetaFields' => false,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testErrorLogging()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());

        /** @var \AuditStash\Persister\TablePersister|\PHPUnit\Framework\MockObject\MockObject $TablePersister */
        $TablePersister = $this
            ->getMockBuilder(TablePersister::class)
            ->onlyMethods(['log'])
            ->getMock();

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '[]',
        ]);

        $logged = clone $entity;
        $logged->setError('field', ['error']);
        $logged->setSource('AuditLogs');

        $TablePersister
            ->expects($this->once())
            ->method('log');

        $TablePersister->getTable()->getEventManager()->on(
            'Model.beforeSave',
            function (EventInterface $event, EntityInterface $entity) {
                $entity->setError('field', ['error']);

                $event->stopPropagation();
                $event->setResult(false);
            },
        );

        $TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testDisableErrorLogging()
    {
        /** @var \AuditStash\Persister\TablePersister|\PHPUnit\Framework\MockObject\MockObject $TablePersister */
        $TablePersister = $this
            ->getMockBuilder(TablePersister::class)
            ->onlyMethods(['log'])
            ->getMock();

        $TablePersister
            ->expects($this->never())
            ->method('log');

        $TablePersister->setConfig([
            'logErrors' => false,
        ]);
        $TablePersister->getTable()->getEventManager()->on(
            'Model.beforeSave',
            function (EventInterface $event, EntityInterface $entity) {
                $event->setResult(false);
            },
        );

        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testCompoundPrimaryKeyExtractDefault()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', [1, 2, 3], 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => '[1,2,3]',
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $AuditLogsTable->getSchema()->setColumnType('primary_key', 'string');

        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testPrimaryKeyExtractRaw()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_RAW,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testCompoundPrimaryKeyExtractRaw()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', [1, 2, 3], 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => [1, 2, 3],
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $AuditLogsTable->getSchema()->setColumnType('primary_key', 'json');

        $this->TablePersister->setConfig([
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_RAW,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testPrimaryKeyExtractProperties()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_PROPERTIES,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testCompoundPrimaryKeyExtractProperties()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', [1, 2, 3], 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key_0' => 1,
            'primary_key_1' => 2,
            'primary_key_2' => 3,
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setConfig([
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_PROPERTIES,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testPrimaryKeyExtractSerialized()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 'pk', 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => '"pk"',
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $AuditLogsTable->getSchema()->setColumnType('primary_key', 'string');

        $this->TablePersister->setConfig([
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_SERIALIZED,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testCompoundPrimaryKeyExtractSerialized()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', [1, 2, 3], 'source', [], [], new Entity());

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => '[1,2,3]',
            'meta' => '[]',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $AuditLogsTable->getSchema()->setColumnType('primary_key', 'string');

        $this->TablePersister->setConfig([
            'primaryKeyExtractionStrategy' => TablePersister::STRATEGY_SERIALIZED,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testDoNotSerializeFields()
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'foo' => 'bar',
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => [],
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => [
                'foo' => 'bar',
            ],
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $AuditLogsTable->getSchema()->setColumnType('original', 'json');
        $AuditLogsTable->getSchema()->setColumnType('changed', 'json');
        $AuditLogsTable->getSchema()->setColumnType('meta', 'json');

        $this->TablePersister->setConfig([
            'serializeFields' => false,
        ]);
        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testUserAutoExtraction(): void
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'ip' => '127.0.0.1',
            'user_id' => 'abc-123',
            'user_display' => 'john_doe',
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '{"ip":"127.0.0.1"}',
            'user_id' => 'abc-123',
            'user_display' => 'john_doe',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testUserAutoExtractionWithoutUser(): void
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'ip' => '127.0.0.1',
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '{"ip":"127.0.0.1"}',
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * @return void
     */
    public function testUserAutoExtractionWithNumericId(): void
    {
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', [], [], new Entity());
        $event->setMetaInfo([
            'ip' => '127.0.0.1',
            'user_id' => 123,
        ]);

        $entity = new Entity([
            'transaction' => '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            'type' => 'create',
            'source' => 'source',
            'parent_source' => null,
            'display_value' => null,
            'original' => null,
            'changed' => '[]',
            'created' => new DateTime($event->getTimestamp()),
            'primary_key' => 1,
            'meta' => '{"ip":"127.0.0.1"}',
            'user_id' => 123,
        ]);
        $entity->setSource('AuditLogs');

        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save']);
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($entity)
            ->willReturn($entity);

        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * Tests that JSON columns are auto-detected and serialization is skipped.
     *
     * When using native JSON columns, CakePHP handles encoding automatically,
     * so we should not double-encode.
     *
     * @return void
     */
    public function testJsonColumnAutoDetection(): void
    {
        // Constructor: transactionId, id, source, changed, original, entity
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', ['title' => 'Test'], [], new Entity());
        $event->setMetaInfo(['ip' => '127.0.0.1']);

        // Create a mock table with JSON column types
        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save', 'getSchema']);
        $schema = $this->createMock(TableSchemaInterface::class);
        $schema->method('getColumnType')
            ->willReturnCallback(function ($column) {
                // Return 'json' for the serializable columns
                if (in_array($column, ['original', 'changed', 'meta'])) {
                    return 'json';
                }

                return 'string';
            });
        $AuditLogsTable->method('getSchema')->willReturn($schema);

        // When JSON columns are detected, the data should NOT be serialized (no json_encode)
        // It should be passed as arrays, which CakePHP's JSON type will encode
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (EntityInterface $entity) {
                // changed should be an array, not a JSON string
                $this->assertIsArray($entity->get('changed'), 'changed should be an array when JSON columns are used');
                $this->assertEquals(['title' => 'Test'], $entity->get('changed'));

                // meta should be an array, not a JSON string
                $this->assertIsArray($entity->get('meta'), 'meta should be an array when JSON columns are used');
                $this->assertEquals(['ip' => '127.0.0.1'], $entity->get('meta'));

                return true;
            }))
            ->willReturn(new Entity());

        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * Tests that serialization still works when columns are not JSON type.
     *
     * @return void
     */
    public function testSerializationWithTextColumns(): void
    {
        // Constructor: transactionId, id, source, changed, original, entity
        $event = new AuditCreateEvent('62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96', 1, 'source', ['title' => 'Test'], [], new Entity());
        $event->setMetaInfo(['ip' => '127.0.0.1']);

        // Create a mock table with TEXT column types (not JSON)
        $AuditLogsTable = $this->getMockForModel('AuditLogs', ['save', 'getSchema']);
        $schema = $this->createMock(TableSchemaInterface::class);
        $schema->method('getColumnType')
            ->willReturnCallback(function ($column) {
                // Return 'text' for all columns (no JSON)
                return 'text';
            });
        $AuditLogsTable->method('getSchema')->willReturn($schema);

        // When TEXT columns are used, the data should be serialized (json_encode)
        $AuditLogsTable
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (EntityInterface $entity) {
                // changed should be a JSON string
                $this->assertIsString($entity->get('changed'), 'changed should be a JSON string when TEXT columns are used');
                $this->assertEquals('{"title":"Test"}', $entity->get('changed'));

                // meta should be a JSON string
                $this->assertIsString($entity->get('meta'), 'meta should be a JSON string when TEXT columns are used');
                $this->assertEquals('{"ip":"127.0.0.1"}', $entity->get('meta'));

                return true;
            }))
            ->willReturn(new Entity());

        $this->TablePersister->setTable($AuditLogsTable);
        $this->TablePersister->logEvents([$event]);
    }

    /**
     * Get a mock for a model.
     *
     * @param string $alias The model alias
     * @param array $methods The methods to mock
     * @param array $options Additional options
     *
     * @return \Cake\ORM\Table|\PHPUnit\Framework\MockObject\MockObject
     */
    public function getMockForModel($alias, array $methods = [], array $options = []): Table|MockObject
    {
        return parent::getMockForModel($alias, $methods, $options + [
            'className' => AuditLogsTable::class,
        ]);
    }
}
