<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Meta;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Meta\EnvironmentMetadata;
use Cake\Event\Event;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use ReflectionClass;

/**
 * EnvironmentMetadata Test Case
 */
class EnvironmentMetadataTest extends TestCase
{
    /**
     * Test that CLI is detected in CLI environment
     *
     * @return void
     */
    public function testDetectsCliEnvironment(): void
    {
        $metadata = new EnvironmentMetadata();

        // In test environment (CLI), it should detect 'cli'
        $this->assertSame('cli', $metadata->getSource());
    }

    /**
     * Test explicit source override
     *
     * @return void
     */
    public function testExplicitSourceOverride(): void
    {
        $metadata = new EnvironmentMetadata('queue');
        $this->assertSame('queue', $metadata->getSource());

        $metadata = new EnvironmentMetadata('custom');
        $this->assertSame('custom', $metadata->getSource());
    }

    /**
     * Test API detection via Accept header
     *
     * @return void
     */
    public function testDetectsApiViaAcceptHeader(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'HTTP_ACCEPT' => 'application/json',
                'REQUEST_URI' => '/users',
            ],
        ]);

        // Since we're in CLI, we need to force non-CLI detection
        // by using explicit source or checking the isApiRequest method
        $metadata = new EnvironmentMetadata(null, [], $request);

        // Still CLI in test environment, but let's test the API detection logic directly
        $reflection = new ReflectionClass($metadata);
        $method = $reflection->getMethod('isApiRequest');

        $this->assertTrue($method->invoke($metadata, $request));
    }

    /**
     * Test API detection via Content-Type header
     *
     * @return void
     */
    public function testDetectsApiViaContentType(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'CONTENT_TYPE' => 'application/json',
                'REQUEST_URI' => '/users',
            ],
        ]);

        $metadata = new EnvironmentMetadata(null, [], $request);
        $reflection = new ReflectionClass($metadata);
        $method = $reflection->getMethod('isApiRequest');

        $this->assertTrue($method->invoke($metadata, $request));
    }

    /**
     * Test API detection via URL pattern
     *
     * @return void
     */
    public function testDetectsApiViaUrlPattern(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'REQUEST_URI' => '/api/users',
            ],
        ]);

        $metadata = new EnvironmentMetadata(null, [], $request);
        $reflection = new ReflectionClass($metadata);
        $method = $reflection->getMethod('isApiRequest');

        $this->assertTrue($method->invoke($metadata, $request));
    }

    /**
     * Test that regular web request is not detected as API
     *
     * @return void
     */
    public function testDoesNotDetectWebAsApi(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'HTTP_ACCEPT' => 'text/html',
                'REQUEST_URI' => '/users',
            ],
        ]);

        $metadata = new EnvironmentMetadata(null, [], $request);
        $reflection = new ReflectionClass($metadata);
        $method = $reflection->getMethod('isApiRequest');

        $this->assertFalse($method->invoke($metadata, $request));
    }

    /**
     * Test that Firefox form submission is not detected as API
     *
     * Firefox sends Accept header with application/xml but prefers text/html,
     * which should NOT be treated as API request.
     *
     * @return void
     */
    public function testDoesNotDetectFirefoxFormAsApi(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'REQUEST_URI' => '/users',
            ],
        ]);

        $metadata = new EnvironmentMetadata(null, [], $request);
        $reflection = new ReflectionClass($metadata);
        $method = $reflection->getMethod('isApiRequest');

        $this->assertFalse($method->invoke($metadata, $request));
    }

    /**
     * Test that pure XML API request is still detected
     *
     * @return void
     */
    public function testDetectsXmlApiRequest(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'HTTP_ACCEPT' => 'application/xml',
                'REQUEST_URI' => '/users',
            ],
        ]);

        $metadata = new EnvironmentMetadata(null, [], $request);
        $reflection = new ReflectionClass($metadata);
        $method = $reflection->getMethod('isApiRequest');

        $this->assertTrue($method->invoke($metadata, $request));
    }

    /**
     * Test that metadata is added to audit logs
     *
     * @return void
     */
    public function testBeforeLogAddsMetadata(): void
    {
        $metadata = new EnvironmentMetadata('web', ['server' => 'test-server']);

        $log = new AuditCreateEvent('tx-123', 1, 'articles', [], [], null);
        $event = new Event('AuditStash.beforeLog', null, ['logs' => [$log]]);

        $metadata->beforeLog($event, [$log]);

        $meta = $log->getMetaInfo();
        $this->assertArrayHasKey('request_source', $meta);
        $this->assertSame('web', $meta['request_source']);
        $this->assertArrayHasKey('server', $meta);
        $this->assertSame('test-server', $meta['server']);
    }

    /**
     * Test implemented events
     *
     * @return void
     */
    public function testImplementedEvents(): void
    {
        $metadata = new EnvironmentMetadata();
        $events = $metadata->implementedEvents();

        $this->assertArrayHasKey('AuditStash.beforeLog', $events);
        $this->assertSame('beforeLog', $events['AuditStash.beforeLog']);
    }

    /**
     * Capture is opt-in: omitting `capture` keeps the legacy meta payload.
     *
     * @return void
     */
    public function testCaptureDefaultsToOff(): void
    {
        $request = new ServerRequest([
            'environment' => ['HTTP_USER_AGENT' => 'Mozilla/5.0 ...'],
        ]);

        $metadata = new EnvironmentMetadata('web', [], $request);

        $log = new AuditCreateEvent('tx-1', 1, 'articles', [], [], null);
        $metadata->beforeLog(new Event('AuditStash.beforeLog'), [$log]);

        $meta = $log->getMetaInfo();
        $this->assertSame(['request_source' => 'web'], $meta);
    }

    /**
     * Opting into `user_agent` and `referer` adds the matching headers.
     *
     * @return void
     */
    public function testCaptureUserAgentAndReferer(): void
    {
        $request = new ServerRequest([
            'environment' => [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux) Firefox/123',
                'HTTP_REFERER' => 'https://example.com/admin/articles',
            ],
        ]);

        $metadata = new EnvironmentMetadata(
            source: 'web',
            request: $request,
            capture: ['user_agent', 'referer'],
        );

        $log = new AuditCreateEvent('tx-2', 1, 'articles', [], [], null);
        $metadata->beforeLog(new Event('AuditStash.beforeLog'), [$log]);

        $meta = $log->getMetaInfo();
        $this->assertSame('Mozilla/5.0 (X11; Linux) Firefox/123', $meta['user_agent']);
        $this->assertSame('https://example.com/admin/articles', $meta['referer']);
    }

    /**
     * Empty headers (no Referer at all on a direct hit) must NOT pollute the
     * audit row with empty strings.
     *
     * @return void
     */
    public function testCaptureSkipsEmptyHeaders(): void
    {
        $request = new ServerRequest([
            'environment' => ['HTTP_USER_AGENT' => 'curl/8.0'],
        ]);

        $metadata = new EnvironmentMetadata(
            source: 'web',
            request: $request,
            capture: ['user_agent', 'referer'],
        );

        $log = new AuditCreateEvent('tx-3', 1, 'articles', [], [], null);
        $metadata->beforeLog(new Event('AuditStash.beforeLog'), [$log]);

        $meta = $log->getMetaInfo();
        $this->assertSame('curl/8.0', $meta['user_agent']);
        $this->assertArrayNotHasKey('referer', $meta);
    }

    /**
     * Unknown capture field names are silently filtered out so a typo in
     * userland config can't smuggle arbitrary values into meta.
     *
     * @return void
     */
    public function testCaptureFiltersUnknownFields(): void
    {
        $request = new ServerRequest([
            'environment' => ['HTTP_USER_AGENT' => 'curl/8.0'],
        ]);

        $metadata = new EnvironmentMetadata(
            source: 'web',
            request: $request,
            capture: ['user_agent', 'totally_unsupported'],
        );

        $log = new AuditCreateEvent('tx-4', 1, 'articles', [], [], null);
        $metadata->beforeLog(new Event('AuditStash.beforeLog'), [$log]);

        $meta = $log->getMetaInfo();
        $this->assertSame('curl/8.0', $meta['user_agent']);
        $this->assertArrayNotHasKey('totally_unsupported', $meta);
    }

    /**
     * Without an active session the session_id capture must yield nothing
     * rather than an empty string.
     *
     * @return void
     */
    public function testCaptureSessionIdSkippedWhenNotStarted(): void
    {
        $request = new ServerRequest([
            'environment' => ['HTTP_USER_AGENT' => 'curl/8.0'],
        ]);

        $metadata = new EnvironmentMetadata(
            source: 'web',
            request: $request,
            capture: ['session_id'],
        );

        $log = new AuditCreateEvent('tx-5', 1, 'articles', [], [], null);
        $metadata->beforeLog(new Event('AuditStash.beforeLog'), [$log]);

        $meta = $log->getMetaInfo();
        $this->assertArrayNotHasKey('session_id', $meta);
    }

    /**
     * `capture` is a no-op when no request is supplied (CLI / queue context).
     *
     * @return void
     */
    public function testCaptureIgnoredWithoutRequest(): void
    {
        $metadata = new EnvironmentMetadata(
            source: 'cli',
            capture: ['user_agent', 'referer'],
        );

        $log = new AuditCreateEvent('tx-6', 1, 'articles', [], [], null);
        $metadata->beforeLog(new Event('AuditStash.beforeLog'), [$log]);

        $meta = $log->getMetaInfo();
        $this->assertSame(['request_source' => 'cli'], $meta);
    }
}
