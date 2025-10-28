<?php
declare(strict_types=1);

namespace AuditStash\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * AuditLogs Model
 *
 * @method \AuditStash\Model\Entity\AuditLog newEmptyEntity()
 * @method \AuditStash\Model\Entity\AuditLog newEntity(array $data, array $options = [])
 * @method array<\AuditStash\Model\Entity\AuditLog> newEntities(array $data, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \AuditStash\Model\Entity\AuditLog findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\AuditStash\Model\Entity\AuditLog> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AuditLogsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('audit_logs');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->nonNegativeInteger('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->scalar('transaction')
            ->maxLength('transaction', 36)
            ->requirePresence('transaction', 'create')
            ->notEmptyString('transaction');

        $validator
            ->scalar('type')
            ->maxLength('type', 7)
            ->requirePresence('type', 'create')
            ->notEmptyString('type')
            ->inList('type', ['create', 'update', 'delete']);

        $validator
            ->nonNegativeInteger('primary_key')
            ->allowEmptyString('primary_key');

        $validator
            ->scalar('display_value')
            ->maxLength('display_value', 255)
            ->allowEmptyString('display_value');

        $validator
            ->scalar('source')
            ->maxLength('source', 255)
            ->requirePresence('source', 'create')
            ->notEmptyString('source');

        $validator
            ->scalar('parent_source')
            ->maxLength('parent_source', 255)
            ->allowEmptyString('parent_source');

        $validator
            ->scalar('username')
            ->maxLength('username', 255)
            ->allowEmptyString('username');

        $validator
            ->scalar('original')
            ->allowEmptyString('original');

        $validator
            ->scalar('changed')
            ->allowEmptyString('changed');

        $validator
            ->scalar('meta')
            ->allowEmptyString('meta');

        return $validator;
    }
}
