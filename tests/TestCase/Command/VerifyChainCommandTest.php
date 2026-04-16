<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Command;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Persister\TablePersister;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * @uses \AuditStash\Command\VerifyChainCommand
 */
class VerifyChainCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    protected TablePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        Configure::write('AuditStash.persister', 'AuditStash\Persister\TablePersister');

        $this->persister = new TablePersister();
        $this->persister->setConfig([
            'table' => 'AuditStash.AuditLogs',
            'hashChain' => true,
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->persister);

        parent::tearDown();
    }

    public function testBuildOptionParser(): void
    {
        $this->exec('audit_stash verify_chain --help');

        $this->assertExitSuccess();
        $this->assertOutputContains('Verify the SHA-256 hash chain');
        $this->assertOutputContains('--table');
        $this->assertOutputContains('--chunk');
    }

    public function testExecuteReportsIntactChain(): void
    {
        $this->persister->logEvents([
            $this->buildEvent(1, ['title' => 'A']),
            $this->buildEvent(2, ['title' => 'B']),
        ]);

        $this->exec('audit_stash verify_chain');

        $this->assertExitSuccess();
        $this->assertOutputContains('AuditStash.AuditLogs');
        $this->assertOutputContains('Chain intact');
    }

    public function testExecuteReportsBrokenChain(): void
    {
        $this->persister->logEvents([
            $this->buildEvent(1, ['title' => 'A']),
            $this->buildEvent(2, ['title' => 'B']),
        ]);

        $table = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $row = $table->find()->orderByAsc('id')->firstOrFail();
        $row->set('source', 'TamperedSource');
        $table->saveOrFail($row);

        $this->exec('audit_stash verify_chain');

        $this->assertExitCode(1);
        $this->assertErrorContains('Chain broken at row id=');
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
