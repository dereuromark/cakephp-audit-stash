<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\AuditLogType;
use AuditStash\Service\HashChain;
use PHPUnit\Framework\TestCase;

class HashChainTest extends TestCase
{
    public function testHashIsDeterministic(): void
    {
        $payload = ['source' => 'Articles', 'primary_key' => 1, 'type' => 'create'];

        $a = HashChain::hash(null, $payload);
        $b = HashChain::hash(null, $payload);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function testHashIgnoresKeyOrder(): void
    {
        $a = HashChain::hash(null, ['source' => 'Articles', 'type' => 'create', 'primary_key' => 1]);
        $b = HashChain::hash(null, ['type' => 'create', 'primary_key' => 1, 'source' => 'Articles']);

        $this->assertSame($a, $b);
    }

    public function testHashChangesWhenPayloadChanges(): void
    {
        $a = HashChain::hash(null, ['source' => 'Articles', 'primary_key' => 1]);
        $b = HashChain::hash(null, ['source' => 'Articles', 'primary_key' => 2]);

        $this->assertNotSame($a, $b);
    }

    public function testHashChangesWhenPrevHashChanges(): void
    {
        $a = HashChain::hash(null, ['source' => 'Articles']);
        $b = HashChain::hash('0000', ['source' => 'Articles']);

        $this->assertNotSame($a, $b);
    }

    public function testListOrderIsPreserved(): void
    {
        // Lists should NOT be sorted — order is semantic.
        $a = HashChain::hash(null, ['tags' => ['a', 'b', 'c']]);
        $b = HashChain::hash(null, ['tags' => ['c', 'b', 'a']]);

        $this->assertNotSame($a, $b);
    }

    public function testNestedAssociativeArraysAreCanonicalized(): void
    {
        $a = HashChain::hash(null, ['meta' => ['user' => ['id' => 1, 'name' => 'x']]]);
        $b = HashChain::hash(null, ['meta' => ['user' => ['name' => 'x', 'id' => 1]]]);

        $this->assertSame($a, $b);
    }

    public function testBackedEnumsAreCanonicalizedToTheirValue(): void
    {
        $a = HashChain::hash(null, ['type' => AuditLogType::Create]);
        $b = HashChain::hash(null, ['type' => AuditLogType::Create->value]);

        $this->assertSame($a, $b);
    }

    public function testVerifyIntactChain(): void
    {
        $rows = $this->buildChain([
            ['source' => 'Articles', 'primary_key' => 1],
            ['source' => 'Articles', 'primary_key' => 2],
            ['source' => 'Articles', 'primary_key' => 3],
        ]);

        $this->assertTrue(HashChain::verify($rows));
        $this->assertNull(HashChain::findFirstBreak($rows));
    }

    public function testVerifyDetectsModifiedPayload(): void
    {
        $rows = $this->buildChain([
            ['source' => 'Articles', 'primary_key' => 1],
            ['source' => 'Articles', 'primary_key' => 2],
            ['source' => 'Articles', 'primary_key' => 3],
        ]);

        // Tamper with the middle row's payload without recomputing hash.
        $rows[1]['payload']['primary_key'] = 99;

        $this->assertFalse(HashChain::verify($rows));
        $this->assertSame(1, HashChain::findFirstBreak($rows));
    }

    public function testVerifyDetectsRemovedRow(): void
    {
        $rows = $this->buildChain([
            ['source' => 'Articles', 'primary_key' => 1],
            ['source' => 'Articles', 'primary_key' => 2],
            ['source' => 'Articles', 'primary_key' => 3],
        ]);

        // Drop the middle row — the third row's prev_hash now points into thin air.
        unset($rows[1]);
        $rows = array_values($rows);

        $this->assertSame(1, HashChain::findFirstBreak($rows));
    }

    public function testVerifyDetectsSwappedHashes(): void
    {
        $rows = $this->buildChain([
            ['source' => 'Articles', 'primary_key' => 1],
            ['source' => 'Articles', 'primary_key' => 2],
        ]);

        $tmp = $rows[0]['hash'];
        $rows[0]['hash'] = $rows[1]['hash'];
        $rows[1]['hash'] = $tmp;

        $this->assertFalse(HashChain::verify($rows));
    }

    /**
     * @param array<array<string, mixed>> $payloads
     *
     * @return array<int, array{payload: array<string, mixed>, prev_hash: string|null, hash: string}>
     */
    private function buildChain(array $payloads): array
    {
        $rows = [];
        $prev = null;
        foreach ($payloads as $payload) {
            $hash = HashChain::hash($prev, $payload);
            $rows[] = [
                'payload' => $payload,
                'prev_hash' => $prev,
                'hash' => $hash,
            ];
            $prev = $hash;
        }

        return $rows;
    }
}
