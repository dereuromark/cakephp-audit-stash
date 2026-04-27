<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Event;

use AuditStash\Event\AuditCreateEvent;
use Cake\I18n\DateTime;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

class TimestampPrecisionTest extends TestCase
{
    /**
     * The timestamp string returned from getTimestamp() must carry
     * sub-second precision so persisters can keep it.
     */
    public function testGetTimestampCarriesSubSecondPrecision(): void
    {
        $event = new AuditCreateEvent('123', 50, 'articles', ['title' => 'foo'], null, new Entity());

        $timestamp = $event->getTimestamp();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/',
            $timestamp,
            'Timestamp should be ISO 8601 with microsecond precision and timezone offset.',
        );
    }

    /**
     * Round-tripping the timestamp string through Cake\I18n\DateTime
     * (as ExtractionTrait does) must preserve the microsecond component.
     */
    public function testTimestampRoundTripPreservesMicroseconds(): void
    {
        $event = new AuditCreateEvent('123', 50, 'articles', ['title' => 'foo'], null, new Entity());

        $parsed = new DateTime($event->getTimestamp());

        $this->assertSame(
            $event->getTimestamp(),
            $parsed->format('Y-m-d\TH:i:s.uP'),
            'Parsing the timestamp string should yield an equal DateTime when re-formatted with microseconds.',
        );
    }

    /**
     * BaseEvent exposes the timestamp as a Cake\I18n\DateTime so persisters
     * can avoid the parse round-trip and keep microsecond precision.
     */
    public function testGetTimestampObjectReturnsCakeDateTime(): void
    {
        $event = new AuditCreateEvent('123', 50, 'articles', ['title' => 'foo'], null, new Entity());

        $object = $event->getTimestampObject();

        $this->assertInstanceOf(DateTime::class, $object);
        $this->assertSame($event->getTimestamp(), $object->format('Y-m-d\TH:i:s.uP'));
    }
}
