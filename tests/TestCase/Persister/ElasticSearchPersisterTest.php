<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Persister;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Persister\ElasticSearchPersister;
use Cake\ElasticSearch\Datasource\Connection;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Elastica\Bulk\ResponseSet;
use Elastica\Client;
use Elastica\Response;

class ElasticSearchPersisterTest extends TestCase
{
    /**
     * Tests that create events are correctly stored.
     *
     * @return void
     */
    public function testLogEvents()
    {
        $clientMock = $this->createPartialMock(Client::class, ['addDocuments']);
        $clientMock
            ->method('addDocuments')
            ->willReturn(new ResponseSet(new Response('test', 200), []));

        $connectionMock = $this->createPartialMock(Connection::class, ['getDriver']);
        $connectionMock
            ->method('getDriver')
            ->willReturn($clientMock);

        $persister = new ElasticSearchPersister([
            'connection' => $connectionMock,
            'index' => 'article',
            'type' => 'article',
        ]);
        $data = [
            'title' => 'A new article',
            'body' => 'article body',
            'author_id' => 1,
            'published' => 'Y',
        ];

        $events = [new AuditCreateEvent('1234', 50, 'articles', $data, $data, new Entity())];
        $clientMock->expects($this->once())->method('addDocuments');
        $persister->logEvents($events);
    }

    /**
     * A delete entry has to carry the values of the removed record, the same
     * as the table persister stores them.
     *
     * @return void
     */
    public function testDeleteEventKeepsOriginalValues(): void
    {
        $documents = [];
        $clientMock = $this->createPartialMock(Client::class, ['addDocuments']);
        $clientMock
            ->method('addDocuments')
            ->willReturnCallback(function (array $docs) use (&$documents): ResponseSet {
                $documents = $docs;

                return new ResponseSet(new Response('test', 200), []);
            });
        $connectionMock = $this->createPartialMock(Connection::class, ['getDriver']);
        $connectionMock->method('getDriver')->willReturn($clientMock);
        $persister = new ElasticSearchPersister([
            'connection' => $connectionMock,
            'index' => 'article',
            'type' => 'article',
        ]);
        $original = ['title' => 'Removed article', 'body' => 'article body'];

        $persister->logEvents([
            new AuditDeleteEvent('1234', 50, 'articles', null, $original),
            new AuditDeleteEvent('1234', 51, 'articles'),
        ]);

        $this->assertCount(2, $documents);
        $this->assertSame($original, $documents[0]->getData()['original']);
        $this->assertNull($documents[0]->getData()['changed']);
        $this->assertNull($documents[1]->getData()['original']);
    }
}
