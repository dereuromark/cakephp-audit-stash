<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Rule;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\SensitiveFieldRule;
use Cake\TestSuite\TestCase;

class SensitiveFieldRuleTest extends TestCase
{
    public function testMatchesFieldChangedOnUpdate(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => ['password', 'role'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'changed' => ['role' => 'admin'],
            'original' => ['role' => 'editor'],
        ]);

        $this->assertTrue($rule->matches($log));
    }

    public function testMatchesFieldPresentOnlyInOriginalForDelete(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => ['api_token'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Delete->value,
            'source' => 'ApiKeys',
            'changed' => null,
            'original' => ['api_token' => 'secret', 'name' => 'CI'],
        ]);

        $this->assertTrue($rule->matches($log));
    }

    public function testMatchesFieldPresentInChangedOnCreate(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => ['password'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Create->value,
            'source' => 'Users',
            'changed' => ['username' => 'alice', 'password' => 'hashed'],
            'original' => null,
        ]);

        $this->assertTrue($rule->matches($log));
    }

    public function testNoMatchWhenFieldAbsent(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => ['password'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'changed' => ['username' => 'bob'],
            'original' => ['username' => 'bob_old'],
        ]);

        $this->assertFalse($rule->matches($log));
    }

    public function testNoMatchWhenFieldsConfigEmpty(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => [],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'changed' => ['password' => 'x'],
            'original' => ['password' => 'y'],
        ]);

        $this->assertFalse($rule->matches($log));
    }

    public function testTablesRestrictionFiltersOutOtherSources(): void
    {
        $rule = new SensitiveFieldRule([
            'tables' => ['Users'],
            'fields' => ['email'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Profiles',
            'changed' => ['email' => 'new@example.com'],
            'original' => ['email' => 'old@example.com'],
        ]);

        $this->assertFalse($rule->matches($log));
    }

    public function testTablesRestrictionAllowsListedSource(): void
    {
        $rule = new SensitiveFieldRule([
            'tables' => ['Users', 'Profiles'],
            'fields' => ['email'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Profiles',
            'changed' => ['email' => 'new@example.com'],
            'original' => ['email' => 'old@example.com'],
        ]);

        $this->assertTrue($rule->matches($log));
    }

    public function testStringEncodedJsonPayloadIsTolerated(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => ['password'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
        ]);
        $log->set('changed', json_encode(['password' => 'hashed', 'username' => 'a']));
        $log->set('original', json_encode(['password' => 'old', 'username' => 'a']));

        $this->assertTrue($rule->matches($log));
    }

    public function testSeverityDefaultsToHigh(): void
    {
        $rule = new SensitiveFieldRule(['fields' => ['x']]);

        $this->assertSame('high', $rule->getSeverity());
    }

    public function testSeverityIsConfigurable(): void
    {
        $rule = new SensitiveFieldRule(['fields' => ['x'], 'severity' => 'critical']);

        $this->assertSame('critical', $rule->getSeverity());
    }

    public function testMessageNamesMatchedFields(): void
    {
        $rule = new SensitiveFieldRule(['fields' => ['password', 'role', 'unused']]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'changed' => ['password' => 'x', 'role' => 'admin'],
            'original' => ['password' => 'y', 'role' => 'editor'],
        ]);

        $message = $rule->getMessage($log);

        $this->assertStringContainsString('password', $message);
        $this->assertStringContainsString('role', $message);
        $this->assertStringContainsString('Users', $message);
        $this->assertStringNotContainsString('unused', $message);
    }

    public function testContextReportsMatchedAndConfiguredFields(): void
    {
        $rule = new SensitiveFieldRule([
            'fields' => ['password', 'role'],
        ]);

        $log = new AuditLog([
            'type' => AuditLogType::Update->value,
            'source' => 'Users',
            'changed' => ['password' => 'x'],
            'original' => ['password' => 'y'],
        ]);

        $context = $rule->getContext($log);

        $this->assertSame('Users', $context['table']);
        $this->assertSame('update', $context['event_type']);
        $this->assertSame(['password'], $context['matched_fields']);
        $this->assertSame(['password', 'role'], $context['configured_fields']);
    }
}
