<?php
declare(strict_types=1);

namespace AuditStash\Action;

use AuditStash\Model\Document\AuditLog;
use Cake\ElasticSearch\Index;
use Crud\Action\ViewAction;
use Crud\Event\Subject;

/**
 * A CRUD action class to implement the view of all details of a single audit log event
 * from elastic search.
 */
class ElasticLogsViewAction extends ViewAction
{
    use IndexConfigTrait;

    /**
     * Returns the Repository object to use.
     *
     * @return \Cake\ElasticSearch\Index
     */
    protected function _table(): Index
    {
        $controller = $this->_controller();
        /** @var \Cake\ElasticSearch\Index $index */
        $index = $this->getIndexRepository('AuditStash.AuditLogs');
        /** @phpstan-ignore-next-line */
        $controller->AuditLogs = $index;

        return $index;
    }

    /**
     * Find a audit log by id.
     *
     * @param string|int|null $id Record id
     * @param \Crud\Event\Subject $subject Event subject
     * @return \AuditStash\Model\Document\AuditLog
     * @throws \Exception
     */
    protected function _findRecord(string|int|null $id, Subject $subject): AuditLog
    {
        $repository = $this->_table();
        $this->configIndex($repository, $this->_request());

        if ($this->_request()->getQuery('type')) {
            $repository->setName($this->_request()->getQuery('type'));
        }

        /** @var string $method */
        $method = $this->findMethod();
        $query = $repository->find($method);
        $query->where(['_id' => $id]);
        $subject->set([
            'repository' => $repository,
            'query' => $query,
        ]);
        $this->_trigger('beforeFind', $subject);
        $entity = $query->first();
        if (!$entity) {
            $this->_notFound($id, $subject);
        }
        $subject->set(['entity' => $entity, 'success' => true]);
        $this->_trigger('afterFind', $subject);

        return $entity;
    }
}
