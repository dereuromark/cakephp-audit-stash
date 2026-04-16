<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Persister\TablePersister;
use AuditStash\Service\ChainVerifier;
use AuditStash\Test\AuditLogsTable;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * Integration test: drives TablePersister in hashChain mode against a
 * real table, then verifies with ChainVerifier. Also exercises tamper
 * detection.
 */
class ChainVerifierTest extends TestCase
{
    protected array $fixtures = ['plugin.AuditStash.AuditLogs'];

    protected TablePersister $persister;

    public function setUp(): void
    {
        parent::setUp();

        $this->getTableLocator()->setConfig('AuditLogs', [
            'className' => AuditLogsTable::class,
        ]);

        $this->persister = new TablePersister();
        $this->persister->setConfig([
            'table' => 'AuditLogs',
            'hashChain' => true,
        ]);
    }

    public function tearDown(): void
    {
        unset($this->persister);
        $this->getTableLocator()->clear();

        parent::tearDown();
    }

    public function testPersistingAssignsHashAndPrevHash(): void
    {
        $events = [
            $this->buildEvent(1, ['title' => 'A']),
            $this->buildEvent(2, ['title' => 'B']),
            $this->buildEvent(3, ['title' => 'C']),
        ];

        $this->persister->logEvents($events);

        $table = $this->getTableLocator()->get('AuditLogs');
        $rows = $table->find()->orderByAsc('id')->all()->toArray();

        $this->assertCount(3, $rows);

        $this->assertNull($rows[0]->get('prev_hash'));
        $this->assertNotEmpty($rows[0]->get('hash'));
        $this->assertSame($rows[0]->get('hash'), $rows[1]->get('prev_hash'));
        $this->assertSame($rows[1]->get('hash'), $rows[2]->get('prev_hash'));
    }

    public function testVerifierReportsIntactChain(): void
    {
        $this->persister->logEvents([
            $this->buildEvent(1, ['title' => 'A']),
            $this->buildEvent(2, ['title' => 'B']),
        ]);

        $result = (new ChainVerifier())->verify($this->getTableLocator()->get('AuditLogs'));

        $this->assertTrue($result->intact, (string)$result->reason);
        $this->assertSame(2, $result->rowsChecked);
        $this->assertNull($result->brokenRowId);
    }

    public function testVerifierDetectsRowTampering(): void
    {
        $this->persister->logEvents([
            $this->buildEvent(1, ['title' => 'A']),
            $this->buildEvent(2, ['title' => 'B']),
            $this->buildEvent(3, ['title' => 'C']),
        ]);

        $table = $this->getTableLocator()->get('AuditLogs');
        // Silently rewrite the middle row's source — simulates a rogue admin.
        $middle = $table->find()->orderByAsc('id')->offset(1)->first();
        $middle->set('source', 'TamperedSource');
        $table->saveOrFail($middle);

        $result = (new ChainVerifier())->verify($table);

        $this->assertFalse($result->intact);
        $this->assertSame((int)$middle->get('id'), $result->brokenRowId);
    }

    public function testChainedBatchesStayLinked(): void
    {
        $this->persister->logEvents([$this->buildEvent(1, ['title' => 'A'])]);
        $this->persister->logEvents([$this->buildEvent(2, ['title' => 'B'])]);

        $table = $this->getTableLocator()->get('AuditLogs');
        $rows = $table->find()->orderByAsc('id')->all()->toArray();

        $this->assertSame($rows[0]->get('hash'), $rows[1]->get('prev_hash'));

        $result = (new ChainVerifier())->verify($table);
        $this->assertTrue($result->intact);
    }

    private function buildEvent(int $primaryKey, array $changed): AuditCreateEvent
    {
        $event = new AuditCreateEvent(
            '62ba2e1e-1524-4d4e-bb34-9bf0e03b6a96',
            $primaryKey,
            'Articles',
            $changed,
            null,
            new Entity(),
        );
        $event->setMetaInfo([]);

        return $event;
    }
}
