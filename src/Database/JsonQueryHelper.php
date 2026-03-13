<?php

declare(strict_types=1);

namespace AuditStash\Database;

use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\ORM\Query\SelectQuery;

/**
 * Database-agnostic JSON query helper
 *
 * Provides methods to build JSON queries that work across MySQL and PostgreSQL.
 */
class JsonQueryHelper
{
    /**
     * Check if a JSON column contains a specific key
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to detect driver from
     * @param string $column The JSON column name
     * @param string $key The key to check for
     *
     * @return \Cake\Database\Expression\QueryExpression
     */
    public static function jsonKeyExists(SelectQuery $query, string $column, string $key): QueryExpression
    {
        $driver = self::getDriverName($query);
        $expr = $query->expr();

        if ($driver === 'Mysql') {
            // MySQL: JSON_CONTAINS_PATH(column, 'one', '$.key')
            return $expr->add(
                new FunctionExpression(
                    'JSON_CONTAINS_PATH',
                    [
                        new IdentifierExpression($column),
                        'one',
                        '$.' . $key,
                    ],
                ),
            );
        }

        if ($driver === 'Sqlite') {
            // SQLite: json_extract(column, '$.key') IS NOT NULL
            return $expr->isNotNull(
                new FunctionExpression(
                    'json_extract',
                    [
                        new IdentifierExpression($column),
                        '$.' . $key,
                    ],
                ),
            );
        }

        // PostgreSQL: column ? 'key' OR use jsonb_exists
        return $expr->add(
            new FunctionExpression(
                'jsonb_exists',
                [
                    new FunctionExpression('CAST', [
                        $expr->add(new IdentifierExpression($column)),
                        $expr->add('AS jsonb'),
                    ]),
                    $key,
                ],
            ),
        );
    }

    /**
     * Extract a value from a JSON column
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to detect driver from
     * @param string $column The JSON column name
     * @param string $key The key to extract
     *
     * @return \Cake\Database\Expression\FunctionExpression
     */
    public static function jsonExtract(SelectQuery $query, string $column, string $key): FunctionExpression
    {
        $driver = self::getDriverName($query);

        if ($driver === 'Mysql') {
            // MySQL: JSON_UNQUOTE(JSON_EXTRACT(column, '$.key'))
            return new FunctionExpression(
                'JSON_UNQUOTE',
                [
                    new FunctionExpression(
                        'JSON_EXTRACT',
                        [
                            new IdentifierExpression($column),
                            '$.' . $key,
                        ],
                    ),
                ],
            );
        }

        if ($driver === 'Sqlite') {
            // SQLite: json_extract(column, '$.key')
            // Note: SQLite json_extract returns the value without quotes for strings
            return new FunctionExpression(
                'json_extract',
                [
                    new IdentifierExpression($column),
                    '$.' . $key,
                ],
            );
        }

        // PostgreSQL: column->>'key'
        return new FunctionExpression(
            'jsonb_extract_path_text',
            [
                new FunctionExpression('CAST', [
                    new IdentifierExpression($column),
                    'AS jsonb',
                ]),
                $key,
            ],
        );
    }

    /**
     * Check if a JSON column key equals a specific value
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to detect driver from
     * @param string $column The JSON column name
     * @param string $key The key to check
     * @param mixed $value The expected value
     *
     * @return \Cake\Database\Expression\QueryExpression
     */
    public static function jsonEquals(SelectQuery $query, string $column, string $key, mixed $value): QueryExpression
    {
        $expr = $query->expr();

        $extractExpr = self::jsonExtract($query, $column, $key);

        // Compare extracted value to the given value (cast to string for comparison)
        return $expr->eq($extractExpr, (string)$value, 'string');
    }

    /**
     * Get the driver name from a query
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query
     *
     * @return string Driver name (Mysql, Postgres, Sqlite)
     */
    protected static function getDriverName(SelectQuery $query): string
    {
        $connection = $query->getConnection();
        $driver = $connection->getDriver();
        $driverClass = get_class($driver);

        if (str_contains($driverClass, 'Mysql')) {
            return 'Mysql';
        }
        if (str_contains($driverClass, 'Postgres')) {
            return 'Postgres';
        }

        return 'Sqlite';
    }
}
