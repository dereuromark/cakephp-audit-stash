<?php

declare(strict_types=1);

namespace AuditStash\Persister;

use AuditStash\Event\BaseEvent;
use AuditStash\EventInterface;
use Cake\Core\Configure;
use Cake\Database\Type\DateTimeType;
use Cake\Database\TypeFactory;
use Cake\I18n\DateTime;
use Cake\Utility\Hash;

trait ExtractionTrait
{
    /**
     * Extracts the basic fields from the audit event object.
     *
     * @param \AuditStash\EventInterface $event The event object from which to extract the fields.
     * @param bool $serialize Whether to serialize fields that are expected to hold array data.
     *
     * @return array
     */
    protected function extractBasicFields(EventInterface $event, bool $serialize = true): array
    {
        $fields = [
            'transaction' => $event->getTransactionId(),
            'type' => $event->getEventType(),
            'source' => $event->getSourceName(),
            'parent_source' => null,
            'display_value' => $event->getDisplayValue(),
            'original' => null,
            'changed' => null,
            'created' => new DateTime($event->getTimestamp()),
        ];

        $dateTimeTypeClass = version_compare(Configure::version(), '5.3', '>=')
            ? TypeFactory::getMapped('datetime')
            : TypeFactory::getMap('datetime');
        if ($dateTimeTypeClass !== DateTimeType::class) {
            $fields['created'] = (new DateTime($event->getTimestamp()))->format('Y-m-d H:i:s');
        }

        if (method_exists($event, 'getParentSourceName')) {
            $fields['parent_source'] = $event->getParentSourceName();
        }

        if ($event instanceof BaseEvent) {
            // For create events, original should be null
            $fields['original'] = $fields['type'] === 'create' ? null :
                ($serialize ? $this->serialize($event->getOriginal()) : $event->getOriginal());
            // For delete events, changed should be null
            $fields['changed'] = $fields['type'] === 'delete' ? null :
                ($serialize ? $this->serialize($event->getChanged()) : $event->getChanged());
        }

        return $fields;
    }

    /**
     * Extracts the primary key fields from the audit event object.
     *
     * @param \AuditStash\EventInterface $event The event object from which to extract the primary key.
     * @param string $strategy The strategy to use for extracting the primary key.
     *
     * @return array
     */
    protected function extractPrimaryKeyFields(EventInterface $event, string $strategy = 'automatic'): array
    {
        $primaryKeyFields = [];

        switch ($strategy) {
            case 'automatic':
                $id = (array)$event->getId();
                if (count($id) === 1) {
                    $id = array_pop($id);
                } else {
                    $id = $this->serialize($id);
                }
                $primaryKeyFields['primary_key'] = $id;

                break;
            case 'properties':
                $id = (array)$event->getId();
                if (count($id) === 1) {
                    $primaryKeyFields['primary_key'] = array_pop($id);
                } else {
                    foreach ($id as $key => $value) {
                        $primaryKeyFields['primary_key_' . $key] = $value;
                    }
                }

                break;
            case 'raw':
                $primaryKeyFields['primary_key'] = $event->getId();

                break;
            case 'serialized':
                $id = $event->getId();
                $primaryKeyFields['primary_key'] = $this->serialize($id);

                break;
        }

        return $primaryKeyFields;
    }

    /**
     * Extracts the metadata fields from the audit event object.
     *
     * @param \AuditStash\EventInterface $event The event object from which to extract the metadata fields.
     * @param array|bool $fields Which/whether meta data fields should be extracted.
     * @param bool $unsetExtracted Whether the fields extracted from the meta data should be unset.
     * @param bool $serialize Whether to serialize fields that are expected to hold array data.
     *
     * @return array
     */
    protected function extractMetaFields(
        EventInterface $event,
        bool|array $fields,
        bool $unsetExtracted = true,
        bool $serialize = true,
    ): array {
        $meta = $event->getMetaInfo();
        $extracted = [
            'meta' => $meta,
        ];

        if (
            !$fields ||
            empty($extracted['meta'])
        ) {
            if ($serialize) {
                $extracted['meta'] = $this->serialize($extracted['meta']);
            }

            return $extracted;
        }

        if ($fields === true) {
            $extracted += $extracted['meta'];

            if (!$unsetExtracted) {
                if ($serialize) {
                    $extracted['meta'] = $this->serialize($extracted['meta']);
                }

                return $extracted;
            }

            $extracted['meta'] = $serialize ? $this->serialize([]) : [];

            return $extracted;
        }

        foreach ($fields as $name => $alias) {
            if (!is_string($name)) {
                $name = $alias;
            }

            $extracted[$alias] = Hash::get($extracted['meta'], $name);
            if ($unsetExtracted) {
                /** @phpstan-ignore-next-line */
                $extracted['meta'] = Hash::remove($extracted['meta'], $name);
            }
        }

        if ($serialize) {
            $extracted['meta'] = $this->serialize($extracted['meta']);
        }

        return $extracted;
    }

    /**
     * Serializes a value to JSON.
     *
     * In case the value is `null`, the value is not being JSON encoded (which would turn it
     * into a string), but returned as is, ie `null` is being returned.
     *
     * @param mixed $value The value to convert to JSON.
     *
     * @return string|null
     */
    protected function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value);

        return $encoded !== false ? $encoded : null;
    }
}
