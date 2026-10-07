<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Persister;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Persister\TablePersister;
use AuditStash\Service\ChainVerifier;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\TestSuite\TestCase;
use Cake\Utility\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TypeError;

/**
 * `AuditStash.afterLog` runs once the rows are safely stored and cannot
 * take them back.
 */
class TablePersisterAfterLogTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = ['plugin.AuditStash.AuditLogs'];

    /**
     * @param bool $hashChain Whether the hash chain is enabled
     *
     * @return void
     */
    #[DataProvider('chainModes')]
    public function testFailingListenerDoesNotUndoOrInterruptTheWrite(bool $hashChain): void
    {
        $persister = $this->persister($hashChain);
        $persister->getEventManager()->on('AuditStash.afterLog', function (): void {
            throw new TypeError('listener bug');
        });

        $persister->logEvents([$this->event(1), $this->event(2)]);

        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $this->assertSame(2, $auditLogs->find()->count());
        if ($hashChain) {
            $this->assertTrue((new ChainVerifier())->verify($auditLogs)->intact);
        }
    }

    /**
     * @param bool $hashChain Whether the hash chain is enabled
     *
     * @return void
     */
    #[DataProvider('chainModes')]
    public function testListenerRunsPerRowAfterCommit(bool $hashChain): void
    {
        $persister = $this->persister($hashChain);
        $connection = $this->fetchTable('AuditStash.AuditLogs')->getConnection();
        $seen = [];
        $persister->getEventManager()->on(
            'AuditStash.afterLog',
            function (EventInterface $event) use (&$seen, $connection): void {
                $seen[] = [$event->getData('auditLog')->primary_key, $connection->inTransaction()];
            },
        );

        $persister->logEvents([$this->event(1), $this->event(2)]);

        $this->assertSame([[1, false], [2, false]], $seen);
    }

    /**
     * Without the hash chain each row commits on its own, so a row stored
     * before a later one fails is still announced.
     *
     * @return void
     */
    public function testRowsStoredBeforeAFailureAreAnnounced(): void
    {
        $persister = $this->persister(false);
        $seen = [];
        $persister->getEventManager()->on('AuditStash.afterLog', function (EventInterface $event) use (&$seen): void {
            $seen[] = $event->getData('auditLog')->primary_key;
        });
        $this->fetchTable('AuditStash.AuditLogs')->getEventManager()->on(
            'Model.beforeSave',
            function (EventInterface $event, EntityInterface $entity): void {
                if ($entity->get('primary_key') === 2) {
                    throw new RuntimeException('storage failed');
                }
            },
        );

        try {
            $persister->logEvents([$this->event(1), $this->event(2)]);
            $this->fail('Expected the storage failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('storage failed', $e->getMessage());
        }

        $this->assertSame([1], $seen);
    }

    /**
     * @return array<string, array<bool>>
     */
    public static function chainModes(): array
    {
        return ['without hash chain' => [false], 'with hash chain' => [true]];
    }

    /**
     * @param bool $hashChain Whether the hash chain is enabled
     *
     * @return \AuditStash\Persister\TablePersister
     */
    protected function persister(bool $hashChain): TablePersister
    {
        $persister = new TablePersister();
        $persister->setConfig(['hashChain' => $hashChain, 'logErrors' => false]);

        return $persister;
    }

    /**
     * @param int $id Record id
     *
     * @return \AuditStash\Event\AuditCreateEvent
     */
    protected function event(int $id): AuditCreateEvent
    {
        return new AuditCreateEvent(Text::uuid(), $id, 'Articles', ['title' => 'Row ' . $id], [], null);
    }
}
