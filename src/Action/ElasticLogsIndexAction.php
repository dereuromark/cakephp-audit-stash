<?php

declare(strict_types=1);

namespace AuditStash\Action;

use Cake\Database\Expression\QueryExpression;
use Cake\ElasticSearch\Index;
use Cake\ElasticSearch\Query;
use Cake\ElasticSearch\QueryBuilder;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Crud\Action\IndexAction;
use Elastica\Query\BoolQuery;
use Elastica\Query\QueryString;
use Elastica\Util;
use Exception;

/**
 * A CRUD action class to implement the listing of all audit logs
 * documents in elastic search.
 */
class ElasticLogsIndexAction extends IndexAction
{
    use IndexConfigTrait;

    /**
     * Pattern that the `type` query parameter must match before being passed
     * to `Index::setName()`. Prevents an attacker pointing the search at
     * arbitrary indexes.
     *
     * @var string
     */
    protected const TYPE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Renders the index action by searching all documents matching the URL conditions.
     *
     * @throws \Exception
     *
     * @return \Cake\Http\Response|null
     */
    protected function _handle(): ?Response
    {
        $request = $this->_request();
        $this->configIndex($this->_table(), $request);
        $query = $this->_table()->find();
        $repository = $query->getRepository();

        $query->searchOptions(['ignore_unavailable' => true]);

        if ($request->getQuery('type')) {
            $type = (string)$request->getQuery('type');
            if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
                throw new BadRequestException(sprintf(
                    'Invalid type filter: must match %s',
                    self::TYPE_PATTERN,
                ));
            }
            $repository->setName($type);
        }

        if ($request->getQuery('primary_key')) {
            $query->where(['primary_key' => $request->getQuery('primary_key')]);
        }

        if ($request->getQuery('transaction_key')) {
            $query->where(['transaction_key' => $request->getQuery('transaction_key')]);
        }

        if ($request->getQuery('user')) {
            $query->where(['meta.user' => $request->getQuery('user')]);
        }

        if ($request->getQuery('changed_fields')) {
            $query->where(function (QueryBuilder $builder) use ($request): BoolQuery {
                $fields = explode(',', $request->getQuery('changed_fields'));
                $fields = array_map(fn ($f): string => 'changed.' . $f, array_map('trim', $fields));
                $fields = array_map([$builder, 'exists'], $fields);

                return $builder->and(...$fields);
            });
        }

        if ($request->getQuery('query')) {
            // Escape ES query DSL meta-characters so the parameter behaves as
            // a free-text search rather than a full DSL expression — wildcard
            // / regex / field-selector tricks would otherwise let a caller
            // read fields the UI did not intend or run expensive queries.
            $escaped = Util::escapeTerm((string)$request->getQuery('query'));
            $query->where(fn (QueryBuilder $builder): BoolQuery => $builder
                ->and(new QueryString($escaped)));
        }

        try {
            $this->addTimeConstraints($request, $query);
        } catch (Exception $e) {
        }

        $subject = $this->_subject(['success' => true, 'query' => $query]);
        $this->_trigger('beforePaginate', $subject);

        /** @phpstan-ignore-next-line */
        $items = $this->_controller()->paginate($subject->query);
        $subject->set(['entities' => $items]);

        $this->_trigger('afterPaginate', $subject);
        $this->_trigger('beforeRender', $subject);

        return null;
    }

    /**
     * Returns the Repository object to use.
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
     * Alters the query object to add the time constraints as they can be found in
     * the request object.
     *
     * @param \Cake\Http\ServerRequest $request The request where query string params can be found
     * @param \Cake\ElasticSearch\Query $query The Query to add filters to
     *
     * @return void
     */
    protected function addTimeConstraints(ServerRequest $request, Query $query): void
    {
        $from = null;
        $until = null;

        if ($request->getQuery('from')) {
            $from = new DateTime($request->getQuery('from'));
            $until = new DateTime();
        }

        if ($request->getQuery('until')) {
            $until = new DateTime($request->getQuery('until'));
        }

        if ($from !== null && $until !== null) {
            $query->where(fn (QueryExpression $builder): QueryExpression => $builder
                ->between(
                    '@timestamp',
                    $from->format('Y-m-d H:i:s'),
                    $until->format('Y-m-d H:i:s'),
                ));

            return;
        }

        if ($until !== null) {
            $query->where(['@timestamp <=' => $until->format('Y-m-d H:i:s')]);
        }
    }
}
