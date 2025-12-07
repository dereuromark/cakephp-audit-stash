<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Meta;

use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventDispatcherTrait;
use Cake\Http\ServerRequest as Request;
use Cake\TestSuite\TestCase;

class RequestMetadataTest extends TestCase
{
    use EventDispatcherTrait;

    /**
     * Tests that request metadata is added to the audit log objects.
     *
     * @return void
     */
    public function testRequestDataIsAdded(): void
    {
        $request = $this->createMock(Request::class, ['clientIp', 'here']);
        $listener = new RequestMetadata($request, 'abc-123', 'jose');
        $this->getEventManager()->on($listener);

        $request->expects($this->once())->method('clientIp')->willReturn('12345');
        $request->expects($this->once())->method('getRequestTarget')->willReturn('/things?a=b');
        $logs = [new AuditDeleteEvent('1234', 1, 'articles')];
        $this->dispatchEvent('AuditStash.beforeLog', ['logs' => $logs]);

        $expected = [
            'ip' => '12345',
            'url' => '/things?a=b',
            'user_id' => 'abc-123',
            'user_display' => 'jose',
        ];
        $this->assertEquals($expected, $logs[0]->getMetaInfo());
    }

    /**
     * Tests that request metadata works with only user ID (no display name).
     *
     * @return void
     */
    public function testRequestDataWithOnlyUserId(): void
    {
        $request = $this->createMock(Request::class, ['clientIp', 'here']);
        $listener = new RequestMetadata($request, 'abc-123');
        $this->getEventManager()->on($listener);

        $request->expects($this->once())->method('clientIp')->willReturn('12345');
        $request->expects($this->once())->method('getRequestTarget')->willReturn('/things?a=b');
        $logs = [new AuditDeleteEvent('1234', 1, 'articles')];
        $this->dispatchEvent('AuditStash.beforeLog', ['logs' => $logs]);

        $expected = [
            'ip' => '12345',
            'url' => '/things?a=b',
            'user_id' => 'abc-123',
            'user_display' => null,
        ];
        $this->assertEquals($expected, $logs[0]->getMetaInfo());
    }
}
