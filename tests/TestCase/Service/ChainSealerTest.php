<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditCustomEvent;
use AuditStash\Persister\TablePersister;
use AuditStash\Service\ChainSealer;
use AuditStash\Service\ChainVerificationResult;
use AuditStash\Service\ChainVerifier;
use AuditStash\Service\GdprService;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Cake\Utility\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * The hash chain has to stay verifiable after cleanup and GDPR operations,
 * and still catch tampering on both sides of a seal.
 */
class ChainSealerTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = ['plugin.AuditStash.AuditLogs'];

    protected Table $auditLogs;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        Configure::write('AuditStash.persister', TablePersister::class);
        Configure::write('AuditStash.persisterConfig', [
            'hashChain' => true,
            'extractMetaFields' => ['user' => 'user_id'],
        ]);
        $this->auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $this->seed();
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        Configure::delete('AuditStash.persister');
        Configure::delete('AuditStash.persisterConfig');
        $this->getTableLocator()->clear();

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testPerTableCleanupKeepsChainVerifiable(): void
    {
        $this->seed('Comments');
        $this->assertTrue($this->verify()->intact);

        $this->exec('audit_stash cleanup --retention 30 --table Comments --force');

        $this->assertExitSuccess();
        $this->assertOutputContains('Hash chain sealed');
        $this->assertSame(0, $this->auditLogs->find()->where(['source' => 'Comments'])->count());
        $result = $this->verify();
        $this->assertTrue($result->intact, (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testGdprAnonymizeKeepsChainVerifiable(): void
    {
        $this->assertSame(3, (new GdprService())->anonymize('7'));

        $result = $this->verify();
        $this->assertTrue($result->intact, (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testGdprDeleteKeepsChainVerifiable(): void
    {
        $this->assertSame(3, (new GdprService())->delete('8'));

        $result = $this->verify();
        $this->assertTrue($result->intact, (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testRowsWrittenAfterSealAreChained(): void
    {
        (new GdprService())->delete('7');
        $this->persister()->logEvents([new AuditCreateEvent(Text::uuid(), 50, 'Articles', ['title' => 'Later'], [], null)]);
        $this->assertTrue($this->verify()->intact);

        $this->auditLogs->updateAll(['display_value' => 'tampered'], ['primary_key' => '50']);

        $result = $this->verify();
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('hash mismatch', (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testEditInsideSealedSegmentIsDetected(): void
    {
        (new GdprService())->delete('7');
        $survivor = $this->auditLogs->find()->where(['type' => 'create'])->orderByAsc('id')->firstOrFail();

        $this->auditLogs->updateAll(['display_value' => 'tampered'], ['id' => $survivor->id]);

        $result = $this->verify();
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('sealed segment mismatch', (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testDeleteInsideSealedSegmentIsDetected(): void
    {
        (new GdprService())->delete('7');
        $survivor = $this->auditLogs->find()->where(['type' => 'create'])->orderByAsc('id')->firstOrFail();

        $this->auditLogs->deleteAll(['id' => $survivor->id]);

        $result = $this->verify();
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('sealed segment mismatch', (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testClearedHashInsideSealedSegmentIsDetected(): void
    {
        (new GdprService())->delete('7');
        $survivor = $this->auditLogs->find()->where(['type' => 'create'])->orderByAsc('id')->firstOrFail();

        $this->auditLogs->updateAll(['hash' => str_repeat('a', 64)], ['id' => $survivor->id]);

        $result = $this->verify();
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('sealed segment mismatch', (string)$result->reason);
    }

    /**
     * Maintenance must not turn a chain that was already broken into a
     * clean one.
     *
     * @return void
     */
    public function testSealDoesNotHideEarlierTampering(): void
    {
        $this->auditLogs->updateAll(['display_value' => 'tampered'], ['user_id' => '8']);
        $this->assertFalse($this->verify()->intact);

        (new GdprService())->delete('7');

        $result = $this->verify();
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('already broken', (string)$result->reason);
    }

    /**
     * @return void
     */
    public function testEditedSealIsDetected(): void
    {
        (new GdprService())->delete('7');
        $seal = $this->auditLogs->find()->where(['type' => ChainSealer::TYPE])->firstOrFail();
        $changed = $seal->changed;
        $changed['sealed_digest'] = str_repeat('0', 64);

        $this->auditLogs->updateAll(['changed' => json_encode($changed)], ['id' => $seal->id]);

        $this->assertFalse($this->verify()->intact);
    }

    /**
     * @return void
     */
    public function testSealWithClearedHashIsDetected(): void
    {
        (new GdprService())->delete('7');

        $this->auditLogs->updateAll(['hash' => null, 'prev_hash' => null], ['type' => ChainSealer::TYPE]);

        $this->assertFalse($this->verify()->intact);
    }

    /**
     * @return void
     */
    public function testEarlierBreakSurvivesEmptyingTheTable(): void
    {
        $this->auditLogs->updateAll(['display_value' => 'tampered'], ['user_id' => '8']);
        $this->auditLogs->updateAll(['user_id' => '7'], ['user_id' => '8']);

        (new GdprService())->delete('7');

        $this->assertSame(1, $this->auditLogs->find()->count());
        $result = $this->verify();
        $this->assertFalse($result->intact);
        $this->assertStringContainsString('already broken', (string)$result->reason);
    }

    /**
     * The hash has to be built from the values as the columns store them.
     *
     * @param mixed $id Record id on the event
     * @param mixed $user User id in the event metadata
     *
     * @return void
     */
    #[DataProvider('coercedValues')]
    public function testChainSurvivesColumnTypeCoercion(mixed $id, mixed $user): void
    {
        $event = new AuditCustomEvent('user.login', Text::uuid(), $id, 'Users', ['ok' => true]);
        $event->setMetaInfo(['user' => $user]);
        $this->persister()->logEvents([$event]);

        $result = $this->verify();
        $this->assertTrue($result->intact, (string)$result->reason);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function coercedValues(): array
    {
        return [
            'integer user id in a string column' => [5, 7],
            'string key in an integer column' => ['5', '7'],
            'no record id' => [null, '7'],
        ];
    }

    /**
     * @return void
     */
    public function testNoSealWithoutHashChain(): void
    {
        Configure::write('AuditStash.persisterConfig', ['extractMetaFields' => ['user' => 'user_id']]);

        (new GdprService())->delete('7');

        $this->assertSame(0, $this->auditLogs->find()->where(['type' => ChainSealer::TYPE])->count());
    }

    /**
     * @return void
     */
    public function testSealRecordsOperationWithoutUserId(): void
    {
        (new GdprService())->anonymize('7');

        $seal = $this->auditLogs->find()->where(['type' => ChainSealer::TYPE])->firstOrFail();
        $this->assertSame('gdpr.anonymize', $seal->changed['operation']);
        $this->assertSame(3, $seal->changed['rows']);
        $this->assertStringNotContainsString('"7"', (string)json_encode($seal->changed));
    }

    /**
     * @return \AuditStash\Persister\TablePersister
     */
    protected function persister(): TablePersister
    {
        $persister = new TablePersister();
        $persister->setConfig((array)Configure::read('AuditStash.persisterConfig'));

        return $persister;
    }

    /**
     * @return \AuditStash\Service\ChainVerificationResult
     */
    protected function verify(): ChainVerificationResult
    {
        return (new ChainVerifier())->verify($this->auditLogs);
    }

    /**
     * Writes six chained rows, two sources interleaved, the first three by
     * user 7. Rows of `$oldSource` are dated past any retention window.
     *
     * @param string|null $oldSource Source whose rows are old
     *
     * @return void
     */
    protected function seed(?string $oldSource = null): void
    {
        $this->auditLogs->deleteAll([]);
        foreach (['Articles', 'Comments', 'Articles', 'Comments', 'Articles', 'Comments'] as $i => $source) {
            $event = new AuditCreateEvent(Text::uuid(), $i + 1, $source, ['title' => 'Row ' . $i], [], null);
            $event->setMetaInfo(['user' => $i < 3 ? '7' : '8', 'ip' => '192.168.1.' . $i]);
            if ($source === $oldSource) {
                $timestamp = new ReflectionProperty($event, 'timestamp');
                $timestamp->setValue($event, (new DateTime('-400 days'))->format('Y-m-d\TH:i:s.uP'));
            }
            $this->persister()->logEvents([$event]);
        }
    }
}
