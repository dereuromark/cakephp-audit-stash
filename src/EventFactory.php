<?php
declare(strict_types=1);

namespace AuditStash;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Event\AuditUpdateEvent;
use Cake\ORM\Entity;
use ReflectionObject;

/**
 * Can be used to convert an array of data obtained from elastic search
 * to convert it to an EventInterface object.
 */
class EventFactory
{
    /**
     * Converts an array of data as coming from elastic search and
     * converts it into an AuditStash\EventInterface object.
     *
     * @param array $data The array data from elastic search
     * @return \AuditStash\EventInterface
     * @throws \ReflectionException
     */
    public function create(array $data): EventInterface
    {
        $displayValue = $data['display_value'] ?? null;

        if ($data['type'] === 'delete') {
            $parentSource = $data['parent_source'] ?? null;
            $original = array_key_exists('original', $data) ? $data['original'] : [];
            $event = new AuditDeleteEvent(
                $data['transaction'],
                $data['primary_key'],
                $data['source'],
                $parentSource,
                $original,
                $displayValue,
            );
        } elseif ($data['type'] === 'create') {
            $event = new AuditCreateEvent(
                $data['transaction'],
                $data['primary_key'],
                $data['source'],
                array_key_exists('changed', $data) ? $data['changed'] : [],
                array_key_exists('original', $data) ? $data['original'] : [],
                new Entity(),
                $displayValue,
            );
        } else {
            $event = new AuditUpdateEvent(
                $data['transaction'],
                $data['primary_key'],
                $data['source'],
                array_key_exists('changed', $data) ? $data['changed'] : [],
                array_key_exists('original', $data) ? $data['original'] : [],
                new Entity(),
                $displayValue,
            );
        }

        if (isset($data['parent_source'])) {
            $event->setParentSourceName($data['parent_source']);
        }

        $reflection = new ReflectionObject($event);
        $timestamp = $reflection->getProperty('timestamp');
        $timestamp->setAccessible(true);
        $timestamp->setValue($event, $data['@timestamp']);
        $event->setMetaInfo($data['meta']);

        return $event;
    }
}
