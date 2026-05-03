<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\TestSuite;

use AuditStash\AuditLogType;
use AuditStash\TestSuite\AuditAssertionsTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class AuditAssertionsTraitTest extends TestCase
{
    use AuditAssertionsTrait;

    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    public function testAssertAuditLoggedPassesWhenRowExists(): void
    {
        $this->createLog('Articles', AuditLogType::Create->value, ['title' => 'Hello']);

        $this->assertAuditLogged('Articles');
        $this->assertAuditLogged('Articles', AuditLogType::Create->value);
    }

    public function testAssertAuditLoggedFailsWhenSourceMissing(): void
    {
        $error = $this->captureAssertionFailure(fn () => $this->assertAuditLogged('Articles'));

        $this->assertStringContainsString('Articles', $error->getMessage());
    }

    public function testAssertAuditLoggedFailsWhenTypeDoesNotMatch(): void
    {
        $this->createLog('Articles', AuditLogType::Create->value);

        $error = $this->captureAssertionFailure(
            fn () => $this->assertAuditLogged('Articles', AuditLogType::Delete->value),
        );

        $this->assertStringContainsString('delete', $error->getMessage());
    }

    public function testAssertAuditNotLoggedPassesWhenAbsent(): void
    {
        $this->createLog('Articles', AuditLogType::Create->value);

        $this->assertAuditNotLogged('Profiles');
        $this->assertAuditNotLogged('Articles', AuditLogType::Delete->value);
    }

    public function testAssertAuditNotLoggedFailsWhenPresent(): void
    {
        $this->createLog('Articles', AuditLogType::Create->value);

        $error = $this->captureAssertionFailure(fn () => $this->assertAuditNotLogged('Articles'));

        $this->assertStringContainsString('Articles', $error->getMessage());
    }

    public function testAssertAuditCount(): void
    {
        $this->createLog('Articles', AuditLogType::Create->value);
        $this->createLog('Articles', AuditLogType::Update->value);
        $this->createLog('Profiles', AuditLogType::Create->value);

        $this->assertAuditCount(3);
        $this->assertAuditCount(2, 'Articles');
        $this->assertAuditCount(1, 'Profiles');
        $this->assertAuditCount(0, 'Unknown');
    }

    public function testAssertAuditCountFailureMessageMentionsActual(): void
    {
        $this->createLog('Articles', AuditLogType::Create->value);

        $error = $this->captureAssertionFailure(fn () => $this->assertAuditCount(2, 'Articles'));

        $this->assertStringContainsString('got 1', $error->getMessage());
    }

    public function testAssertAuditFieldChangedWithoutValueCheck(): void
    {
        $this->createLog('Articles', AuditLogType::Update->value, ['title' => 'New', 'body' => 'Body']);

        $this->assertAuditFieldChanged('Articles', 'title');
        $this->assertAuditFieldChanged('Articles', 'body');
    }

    public function testAssertAuditFieldChangedWithValueCheck(): void
    {
        $this->createLog('Articles', AuditLogType::Update->value, ['title' => 'Hello']);

        $this->assertAuditFieldChanged('Articles', 'title', 'Hello');
    }

    public function testAssertAuditFieldChangedFailsWhenFieldAbsent(): void
    {
        $this->createLog('Articles', AuditLogType::Update->value, ['body' => 'Body']);

        $error = $this->captureAssertionFailure(fn () => $this->assertAuditFieldChanged('Articles', 'title'));

        $this->assertStringContainsString('title', $error->getMessage());
    }

    public function testAssertAuditFieldChangedFailsOnValueMismatch(): void
    {
        $this->createLog('Articles', AuditLogType::Update->value, ['title' => 'Actual']);

        $error = $this->captureAssertionFailure(
            fn () => $this->assertAuditFieldChanged('Articles', 'title', 'Expected'),
        );

        $this->assertNotSame('', $error->getMessage());
    }

    public function testAssertAuditFieldChangedLooksAtMostRecentRow(): void
    {
        // Two updates: assertion targets the latest one.
        $this->createLog('Articles', AuditLogType::Update->value, ['title' => 'Older']);
        $this->createLog('Articles', AuditLogType::Update->value, ['title' => 'Newer']);

        $this->assertAuditFieldChanged('Articles', 'title', 'Newer');
    }

    /**
     * Run the given callable and return the AssertionFailedError it raised,
     * or fail the surrounding test if it didn't raise one. Lets us verify
     * the failure-mode behavior of an assert*() method without tripping the
     * `expectException must not be followed by assert*` lint rule.
     *
     * @param callable $callable
     *
     * @return \PHPUnit\Framework\AssertionFailedError
     */
    private function captureAssertionFailure(callable $callable): AssertionFailedError
    {
        try {
            $callable();
        } catch (AssertionFailedError $error) {
            return $error;
        }

        $this->fail('Expected the callable to raise an AssertionFailedError, but none was raised.');
    }

    /**
     * @param string $source
     * @param string $type
     * @param array<string, mixed>|null $changed
     *
     * @return void
     */
    private function createLog(string $source, string $type, ?array $changed = null): void
    {
        $table = $this->fetchTable('AuditStash.AuditLogs');
        $entity = $table->newEntity([
            'transaction_key' => bin2hex(random_bytes(8)),
            'type' => $type,
            'source' => $source,
            'primary_key' => 1,
            'changed' => $changed,
        ]);

        $table->saveOrFail($entity);
    }
}
