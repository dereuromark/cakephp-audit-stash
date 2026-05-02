<?php

declare(strict_types=1);

namespace AuditStash\Event;

use Cake\Datasource\EntityInterface;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\I18n\Time;
use Cake\ORM\Entity;

/**
 * Exposes basic functions for serializing event classes.
 */
trait SerializableEventTrait
{
    /**
     * Returns the string representation of this object.
     *
     * @return string
     */
    public function serialize(): string
    {
        return serialize(
            $this->__serialize(),
        );
    }

    /**
     * Takes the string representation of this object so it can be reconstructed.
     *
     * Restricts which classes are allowed to be instantiated to prevent
     * gadget-chain object-injection / RCE if a serialized event is ever
     * transported through an untrusted channel (queue payload, cache,
     * cross-process pipe). The default whitelist covers the event itself,
     * Cake's date/time value objects and entity types — extend as needed
     * via `unserializeAllowedClasses()`.
     *
     * @param string $data serialized string
     *
     * @return void
     */
    public function unserialize(string $data): void
    {
        /** @var array $payload */
        $payload = unserialize($data, ['allowed_classes' => $this->unserializeAllowedClasses()]);
        $this->__unserialize($payload);
    }

    /**
     * Returns the list of classes that may be instantiated when unserializing
     * an event payload.
     *
     * Subclasses can override to broaden or narrow the set. The default keeps
     * Cake value objects and entities since events carry the affected
     * EntityInterface and DateTime timestamps.
     *
     * @return array<int, class-string>
     */
    protected function unserializeAllowedClasses(): array
    {
        return [
            static::class,
            Entity::class,
            EntityInterface::class,
            DateTime::class,
            Date::class,
            Time::class,
        ];
    }

    /**
     * Returns the string representation of this object.
     *
     * @return array
     */
    public function __serialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * Takes the string representation of this object so it can be reconstructed.
     *
     * @param array $data serialized string
     *
     * @return void
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $var => $value) {
            $this->{$var} = $value;
        }
    }

    /**
     * Returns an array with the basic variables that should be json serialized.
     *
     * @return array
     */
    protected function basicSerialize(): array
    {
        return [
            'type' => $this->getEventType(),
            'transaction_key' => $this->transactionId,
            'primary_key' => $this->id,
            'source' => $this->source,
            'parent_source' => $this->parentSource,
            '@timestamp' => $this->timestamp,
            'meta' => $this->meta,
            'entity' => $this->entity,
        ];
    }
}
