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
}
