<?php

declare(strict_types=1);

namespace AuditStash\Model\Entity;

use Cake\ORM\Entity;

/**
 * AuditLog Entity
 *
 * @property int $id
 * @property string $transaction
 * @property string $type
 * @property int|null $primary_key
 * @property string|null $display_value
 * @property string $source
 * @property string|null $parent_source
 * @property string|null $username
 * @property string|null $original
 * @property string|null $changed
 * @property string|null $meta
 * @property \Cake\I18n\DateTime|null $created
 */
class AuditLog extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'transaction' => true,
        'type' => true,
        'primary_key' => true,
        'display_value' => true,
        'source' => true,
        'parent_source' => true,
        'username' => true,
        'original' => true,
        'changed' => true,
        'meta' => true,
        'created' => true,
    ];
}
