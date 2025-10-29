# Plan: Revert & Restore Functionality for Audit-Stash

## 🎯 Overview

Add simple revert/restore capabilities to allow reconstructing and restoring records to previous states from audit logs.

**Scope**: Admin backend only, no permission system needed for now.

---

## 📋 Feature Requirements

### 1. **Full Revert**
- Restore entire record to a specific point in time
- Revert to any previous audit log entry
- Support for all field types

### 2. **Partial Revert**
- Cherry-pick specific fields to revert
- Keep some fields at current state while reverting others

### 3. **Restore Deleted Records**
- Restore deleted records from audit logs
- Reconstruct record from final state before deletion

---

## 🏗️ Architecture Design

### Core Components

```
src/
├── Service/
│   ├── RevertService.php              # Main revert logic
│   └── StateReconstructorService.php  # Rebuild state from logs
├── Controller/Admin/
│   └── AuditLogsController.php        # Add revert actions
└── View/Helper/
    └── AuditHelper.php                # Add revert UI helpers
```

---

## 🔧 Implementation Plan

### Phase 1: Core Services

#### **1.1 StateReconstructorService**

```php
namespace AuditStash\Service;

class StateReconstructorService
{
    /**
     * Reconstruct record state at specific audit log entry
     *
     * @param string $source Table name
     * @param int|string $primaryKey Record ID
     * @param int $auditLogId Audit log entry to reconstruct to
     * @return array Reconstructed data
     */
    public function reconstructState(string $source, $primaryKey, int $auditLogId): array
    {
        $auditLogs = $this->AuditLogs->find()
            ->where([
                'source' => $source,
                'primary_key' => $primaryKey,
            ])
            ->orderBy(['created' => 'ASC'])
            ->toArray();

        $state = [];

        // Apply changes sequentially up to target
        foreach ($auditLogs as $log) {
            if ($log->type === 'create') {
                $state = json_decode($log->changed, true);
            } elseif ($log->type === 'update') {
                $changed = json_decode($log->changed, true);
                $state = array_merge($state, $changed);
            }

            if ($log->id === $auditLogId) {
                break;
            }
        }

        return $state;
    }

    /**
     * Calculate diff between current and target state
     *
     * @param array $currentState Current record data
     * @param array $targetState Target state from audit
     * @return array Fields that will change
     */
    public function calculateDiff(array $currentState, array $targetState): array
    {
        $diff = [];

        foreach ($targetState as $field => $value) {
            if (!isset($currentState[$field]) || $currentState[$field] !== $value) {
                $diff[$field] = [
                    'current' => $currentState[$field] ?? null,
                    'target' => $value,
                ];
            }
        }

        return $diff;
    }
}
```

---

#### **1.2 RevertService**

```php
namespace AuditStash\Service;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;

class RevertService
{
    use LocatorAwareTrait;

    protected StateReconstructorService $reconstructor;

    public function __construct()
    {
        $this->reconstructor = new StateReconstructorService();
    }

    /**
     * Revert entire record to previous state
     *
     * @param string $source Table name
     * @param int|string $primaryKey Record ID
     * @param int $auditLogId Target audit log entry
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function revertFull(string $source, $primaryKey, int $auditLogId)
    {
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey, $auditLogId) {
            // Get target state
            $targetState = $this->reconstructor->reconstructState($source, $primaryKey, $auditLogId);

            // Load and update entity
            $table = $this->fetchTable($source);
            $entity = $table->get($primaryKey);

            // Get current state for audit
            $currentState = $entity->extract($entity->getVisible());

            // Patch entity with target state
            $entity = $table->patchEntity($entity, $targetState);

            // Save entity
            if (!$table->save($entity)) {
                return false;
            }

            // Create audit entry for revert
            $this->createRevertAudit($source, $primaryKey, $auditLogId, 'full', $currentState, $targetState);

            return $entity;
        });
    }

    /**
     * Revert specific fields only
     *
     * @param string $source Table name
     * @param int|string $primaryKey Record ID
     * @param int $auditLogId Target audit log entry
     * @param array $fields Fields to revert
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function revertPartial(string $source, $primaryKey, int $auditLogId, array $fields)
    {
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey, $auditLogId, $fields) {
            // Get target state
            $fullTargetState = $this->reconstructor->reconstructState($source, $primaryKey, $auditLogId);

            // Filter only selected fields
            $targetState = array_intersect_key($fullTargetState, array_flip($fields));

            // Load and update entity
            $table = $this->fetchTable($source);
            $entity = $table->get($primaryKey);

            // Get current state for audit
            $currentState = $entity->extract($fields);

            // Patch entity with target state
            $entity = $table->patchEntity($entity, $targetState);

            // Save entity
            if (!$table->save($entity)) {
                return false;
            }

            // Create audit entry for revert
            $this->createRevertAudit($source, $primaryKey, $auditLogId, 'partial', $currentState, $targetState);

            return $entity;
        });
    }

    /**
     * Restore deleted record
     *
     * @param string $source Table name
     * @param int|string $primaryKey Deleted record ID
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function restoreDeleted(string $source, $primaryKey)
    {
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey) {
            // Find DELETE audit entry
            $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
            $deleteLog = $auditLogs->find()
                ->where([
                    'source' => $source,
                    'primary_key' => $primaryKey,
                    'type' => 'delete',
                ])
                ->orderBy(['created' => 'DESC'])
                ->first();

            if (!$deleteLog) {
                return false;
            }

            // Get state before deletion
            $state = json_decode($deleteLog->original, true);

            // Create new entity
            $table = $this->fetchTable($source);
            $entity = $table->newEntity($state);

            // Force the primary key
            $entity->set($table->getPrimaryKey(), $primaryKey);
            $entity->setNew(true);

            // Save entity
            if (!$table->save($entity)) {
                return false;
            }

            // Create audit entry for restore
            $this->createRevertAudit($source, $primaryKey, $deleteLog->id, 'restore', [], $state);

            return $entity;
        });
    }

    /**
     * Create audit entry for revert operation
     */
    protected function createRevertAudit(
        string $source,
        $primaryKey,
        int $auditLogId,
        string $revertType,
        array $currentState,
        array $targetState
    ): void {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $auditLog = $auditLogs->newEntity([
            'transaction' => \Cake\Utility\Text::uuid(),
            'type' => 'revert',
            'source' => $source,
            'primary_key' => $primaryKey,
            'original' => json_encode($currentState),
            'changed' => json_encode($targetState),
            'meta' => json_encode([
                'revert_to_audit_id' => $auditLogId,
                'revert_type' => $revertType,
            ]),
            'created' => new \Cake\I18n\DateTime(),
        ]);

        $auditLogs->save($auditLog);
    }
}
```

---

### Phase 2: Admin UI

#### **2.1 Controller Actions**

Add to `AuditLogsController`:

```php
/**
 * Preview revert changes
 *
 * @param string|null $id Audit log ID
 * @return \Cake\Http\Response|null|void
 */
public function revertPreview(?string $id = null)
{
    $auditLog = $this->AuditLogs->get($id);

    // Load services
    $reconstructor = new \AuditStash\Service\StateReconstructorService();

    // Get target state from audit
    $targetState = $reconstructor->reconstructState(
        $auditLog->source,
        $auditLog->primary_key,
        $auditLog->id
    );

    // Get current state
    $table = $this->fetchTable($auditLog->source);
    $entity = $table->get($auditLog->primary_key);
    $currentState = $entity->toArray();

    // Calculate diff
    $diff = $reconstructor->calculateDiff($currentState, $targetState);

    $this->set(compact('auditLog', 'currentState', 'targetState', 'diff'));
}

/**
 * Execute revert
 *
 * @param string|null $id Audit log ID
 * @return \Cake\Http\Response
 */
public function revert(?string $id = null): Response
{
    $this->request->allowMethod(['post']);

    $auditLog = $this->AuditLogs->get($id);
    $fields = $this->request->getData('fields'); // For partial revert

    $revertService = new \AuditStash\Service\RevertService();

    if (empty($fields)) {
        // Full revert
        $entity = $revertService->revertFull(
            $auditLog->source,
            $auditLog->primary_key,
            $auditLog->id
        );
    } else {
        // Partial revert
        $entity = $revertService->revertPartial(
            $auditLog->source,
            $auditLog->primary_key,
            $auditLog->id,
            $fields
        );
    }

    if ($entity) {
        $this->Flash->success(__('Record reverted successfully.'));
    } else {
        $this->Flash->error(__('Failed to revert record.'));
    }

    return $this->redirect(['action' => 'view', $id]);
}

/**
 * Restore deleted record
 *
 * @param string|null $source Table name
 * @param string|null $primaryKey Primary key value
 * @return \Cake\Http\Response|null|void
 */
public function restore(?string $source = null, ?string $primaryKey = null)
{
    if ($this->request->is('post')) {
        $revertService = new \AuditStash\Service\RevertService();
        $entity = $revertService->restoreDeleted($source, $primaryKey);

        if ($entity) {
            $this->Flash->success(__('Record restored successfully.'));

            return $this->redirect(['action' => 'timeline', $source, $primaryKey]);
        }

        $this->Flash->error(__('Failed to restore record.'));
    }

    // Find DELETE audit entry for preview
    $deleteLog = $this->AuditLogs->find()
        ->where([
            'source' => $source,
            'primary_key' => $primaryKey,
            'type' => 'delete',
        ])
        ->orderBy(['created' => 'DESC'])
        ->first();

    $this->set(compact('source', 'primaryKey', 'deleteLog'));
}
```

---

#### **2.2 View Templates**

**templates/Admin/AuditLogs/revert_preview.php:**

```php
<div class="auditLogs revert">
    <h3>Revert Preview</h3>

    <div class="info">
        <p><strong>Record:</strong> <?= h($auditLog->source) ?> #<?= h($auditLog->primary_key) ?></p>
        <p><strong>Reverting to:</strong> <?= $auditLog->created->format('Y-m-d H:i:s') ?></p>
        <?php if ($auditLog->username): ?>
        <p><strong>Originally changed by:</strong> <?= h($auditLog->username) ?></p>
        <?php endif; ?>
    </div>

    <?= $this->Form->create(null, ['url' => ['action' => 'revert', $auditLog->id]]) ?>

    <h4>Fields to Revert:</h4>

    <table class="table">
        <thead>
            <tr>
                <th>Field</th>
                <th>Current Value</th>
                <th>After Revert</th>
                <th>Revert?</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($diff as $field => $values): ?>
            <tr>
                <td><strong><?= h($field) ?></strong></td>
                <td><?= $this->Audit->formatValue($values['current']) ?></td>
                <td><?= $this->Audit->formatValue($values['target']) ?></td>
                <td>
                    <?= $this->Form->checkbox('fields[]', [
                        'value' => $field,
                        'checked' => true,
                        'hiddenField' => false,
                    ]) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (empty($diff)): ?>
        <p class="info">No differences found. Record is already in this state.</p>
    <?php endif; ?>

    <div class="actions">
        <?= $this->Html->link(__('Cancel'), ['action' => 'view', $auditLog->id], ['class' => 'button']) ?>
        <?= $this->Form->button(__('Revert All'), ['name' => 'type', 'value' => 'full', 'class' => 'button']) ?>
        <?= $this->Form->button(__('Revert Selected'), ['name' => 'type', 'value' => 'partial', 'class' => 'button primary']) ?>
    </div>

    <?= $this->Form->end() ?>
</div>

<script>
// Select/deselect all
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[name="fields[]"]');

    // Add "select all" functionality if needed
});
</script>
```

**templates/Admin/AuditLogs/restore.php:**

```php
<div class="auditLogs restore">
    <h3>Restore Deleted Record</h3>

    <div class="info">
        <p><strong>Record:</strong> <?= h($source) ?> #<?= h($primaryKey) ?></p>
        <?php if ($deleteLog): ?>
        <p><strong>Deleted:</strong> <?= $deleteLog->created->format('Y-m-d H:i:s') ?></p>
        <?php if ($deleteLog->username): ?>
        <p><strong>Deleted by:</strong> <?= h($deleteLog->username) ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($deleteLog): ?>
        <h4>Record Data:</h4>
        <?php
        $data = json_decode($deleteLog->original, true);
        ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $field => $value): ?>
                <tr>
                    <td><strong><?= h($field) ?></strong></td>
                    <td><?= $this->Audit->formatValue($value) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?= $this->Form->create(null, ['url' => ['action' => 'restore', $source, $primaryKey]]) ?>
        <div class="actions">
            <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'button']) ?>
            <?= $this->Form->button(__('Restore Record'), ['class' => 'button primary', 'confirm' => __('Are you sure you want to restore this record?')]) ?>
        </div>
        <?= $this->Form->end() ?>
    <?php else: ?>
        <p class="error">No deletion record found for this record.</p>
    <?php endif; ?>
</div>
```

---

#### **2.3 Helper Methods**

Add to `AuditHelper`:

```php
/**
 * Render revert button
 *
 * @param int $auditLogId Audit log ID
 * @return string HTML button
 */
public function revertButton(int $auditLogId): string
{
    return $this->Html->link(
        __('Revert'),
        ['action' => 'revertPreview', $auditLogId],
        ['class' => 'button revert']
    );
}

/**
 * Render restore button for deleted records
 *
 * @param string $source Table name
 * @param int|string $primaryKey Primary key
 * @return string HTML button
 */
public function restoreButton(string $source, $primaryKey): string
{
    return $this->Html->link(
        __('Restore'),
        ['action' => 'restore', $source, $primaryKey],
        ['class' => 'button restore']
    );
}
```

Update the timeline view to add revert/restore buttons:

```php
// In view.php or timeline.php
<?php if ($auditLog->type === 'delete'): ?>
    <?= $this->Audit->restoreButton($auditLog->source, $auditLog->primary_key) ?>
<?php else: ?>
    <?= $this->Audit->revertButton($auditLog->id) ?>
<?php endif; ?>
```

---

### Phase 3: Database Changes

**No new tables needed.** Use existing `audit_logs` table with new event type:

```php
// When reverting, type = 'revert'
// Meta field stores revert details:
[
    'revert_to_audit_id' => 123,
    'revert_type' => 'full' // or 'partial' or 'restore'
]
```

Update validation in `AuditLogsTable` to allow 'revert' type:

```php
$validator
    ->scalar('type')
    ->maxLength('type', 7)
    ->requirePresence('type', 'create')
    ->notEmptyString('type')
    ->inList('type', ['create', 'update', 'delete', 'revert']);
```

---

## 🧪 Testing Strategy

### Unit Tests

```php
// tests/TestCase/Service/RevertServiceTest.php
class RevertServiceTest extends TestCase
{
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
        'plugin.AuditStash.Articles',
    ];

    public function testRevertFull()
    {
        $revertService = new RevertService();

        // Revert to earlier state
        $entity = $revertService->revertFull('articles', 1, 2);

        $this->assertNotFalse($entity);
        $this->assertEquals('Original Title', $entity->title);
    }

    public function testRevertPartial()
    {
        $revertService = new RevertService();

        // Revert only title field
        $entity = $revertService->revertPartial('articles', 1, 2, ['title']);

        $this->assertNotFalse($entity);
        $this->assertEquals('Original Title', $entity->title);
        // Other fields should remain unchanged
    }

    public function testRestoreDeleted()
    {
        $revertService = new RevertService();

        // Restore deleted record
        $entity = $revertService->restoreDeleted('articles', 5);

        $this->assertNotFalse($entity);
        $this->assertEquals(5, $entity->id);
    }
}
```

### Integration Tests

```php
// tests/TestCase/Controller/Admin/AuditLogsControllerTest.php
public function testRevertPreview()
{
    $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'revertPreview', 1]);

    $this->assertResponseOk();
    $this->assertResponseContains('Revert Preview');
}

public function testRevert()
{
    $this->post(
        ['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'revert', 1],
        ['fields' => ['title']]
    );

    $this->assertRedirect(['action' => 'view', 1]);
    $this->assertFlashMessage('Record reverted successfully.');
}
```

---

## 🚀 Implementation Milestones

### **Week 1: Core Services**
- [ ] Create `StateReconstructorService`
- [ ] Create `RevertService`
- [ ] Add unit tests

### **Week 2: Admin UI**
- [ ] Add controller actions
- [ ] Create view templates
- [ ] Add helper methods
- [ ] Update timeline view

### **Week 3: Testing & Polish**
- [ ] Integration tests
- [ ] Fix edge cases
- [ ] Add validation messages
- [ ] Documentation

---

## 📚 Configuration

Simple config in `config/audit_stash.php`:

```php
return [
    'AuditStash' => [
        'revert' => [
            // Enable/disable revert functionality
            'enabled' => true,

            // Create audit entry when reverting
            'auditReverts' => true,
        ],
    ],
];
```

---

## 🎨 Timeline View with Revert Buttons

Update the timeline view to show revert/restore options:

```php
<?php foreach ($auditLogs as $auditLog): ?>
<div class="audit-entry">
    <div class="audit-header">
        <span class="type"><?= $this->Audit->eventTypeBadge($auditLog->type) ?></span>
        <span class="date"><?= $auditLog->created->format('Y-m-d H:i:s') ?></span>
        <?php if ($auditLog->username): ?>
        <span class="user">by <?= h($auditLog->username) ?></span>
        <?php endif; ?>
    </div>

    <div class="audit-actions">
        <?= $this->Html->link(__('View'), ['action' => 'view', $auditLog->id]) ?>

        <?php if ($auditLog->type === 'delete'): ?>
            <?= $this->Audit->restoreButton($auditLog->source, $auditLog->primary_key) ?>
        <?php elseif ($auditLog->type !== 'revert'): ?>
            <?= $this->Audit->revertButton($auditLog->id) ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
```

---

## ✨ Simple and Clean

This simplified plan:
- ✅ No extra database tables
- ✅ No permission system (admin only)
- ✅ Uses existing audit log structure
- ✅ Clear service separation
- ✅ Simple UI templates
- ✅ Basic configuration
- ✅ Easy to implement and test

Focus on core functionality first, add complexity later if needed!
