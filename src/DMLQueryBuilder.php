<?php

declare(strict_types=1);

namespace Yiisoft\Db\Oracle;

use Generator;
use JsonException;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Db\QueryBuilder\AbstractDMLQueryBuilder;

use function implode;
use function ltrim;
use function strrpos;
use function count;
use function reset;

/**
 * Implements a DML (Data Manipulation Language) SQL statements for Oracle Server.
 */
final class DMLQueryBuilder extends AbstractDMLQueryBuilder
{
    /**
     * @psalm-suppress MixedArrayOffset
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function batchInsert(string $table, array $columns, iterable|Generator $rows, array &$params = []): string
    {
        if (empty($rows)) {
            return '';
        }

        if (($tableSchema = $this->schema->getTableSchema($table)) !== null) {
            $columnSchemas = $tableSchema->getColumns();
        } else {
            $columnSchemas = [];
        }

        $mappedNames = $this->getNormalizeColumnNames($table, $columns);
        $values = [];

        /** @psalm-var array<array-key, array<array-key, string>> $rows */
        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($row as $index => $value) {
                if (isset($columns[$index], $mappedNames[$columns[$index]], $columnSchemas[$mappedNames[$columns[$index]]])) {
                    /** @var mixed $value */
                    $value = $this->getTypecastValue($value, $columnSchemas[$mappedNames[$columns[$index]]]);
                }

                if ($value instanceof ExpressionInterface) {
                    $placeholders[] = $this->queryBuilder->buildExpression($value, $params);
                } else {
                    $placeholders[] = $this->queryBuilder->bindParam($value, $params);
                }
            }
            $values[] = '(' . implode(', ', $placeholders) . ')';
        }

        if (empty($values)) {
            return '';
        }

        foreach ($columns as $i => $name) {
            $columns[$i] = $this->quoter->quoteColumnName($mappedNames[$name]);
        }

        $tableAndColumns = ' INTO ' . $this->quoter->quoteTableName($table)
            . ' (' . implode(', ', $columns) . ') VALUES ';

        return 'INSERT ALL ' . $tableAndColumns . implode($tableAndColumns, $values) . ' SELECT 1 FROM SYS.DUAL';
    }

    /**
     * @throws Exception
     * @throws NotSupportedException
     */
    public function insertWithReturningPks(string $table, QueryInterface|array $columns, array &$params = []): string
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported by Oracle.');
    }

    /**
     * @link https://docs.oracle.com/cd/B28359_01/server.111/b28286/statements_9016.htm#SQLRF01606
     *
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws NotSupportedException
     */
    public function upsert(
        string $table,
        QueryInterface|array $insertColumns,
        array|bool $updateColumns,
        array &$params = []
    ): string {
        $usingValues = null;
        $constraints = [];

        [$uniqueNames, $insertNames, $updateNames] = $this->prepareUpsertColumns(
            $table,
            $insertColumns,
            $updateColumns,
            $constraints
        );

        if (empty($uniqueNames)) {
            return $this->insert($table, $insertColumns, $params);
        }

        if ($updateNames === []) {
            /** there are no columns to update */
            $updateColumns = false;
        }

        $onCondition = ['or'];
        $quotedTableName = $this->quoter->quoteTableName($table);

        foreach ($constraints as $constraint) {
            $columnNames = $constraint->getColumnNames() ?? [];
            $constraintCondition = ['and'];
            /** @psalm-var string[] $columnNames */
            foreach ($columnNames as $name) {
                $quotedName = $this->quoter->quoteColumnName($name);
                $constraintCondition[] = "$quotedTableName.$quotedName=\"EXCLUDED\".$quotedName";
            }

            $onCondition[] = $constraintCondition;
        }

        $on = $this->queryBuilder->buildCondition($onCondition, $params);
        /** @psalm-var string[] $placeholders */
        [, $placeholders, $values, $params] = $this->prepareInsertValues($table, $insertColumns, $params);

        if (!empty($placeholders)) {
            $usingSelectValues = [];
            /** @psalm-var string[] $insertNames */
            foreach ($insertNames as $index => $name) {
                $usingSelectValues[$name] = new Expression($placeholders[$index]);
            }

            /** @psalm-var array $params */
            $usingValues = $this->queryBuilder->buildSelect($usingSelectValues, $params) . ' ' . $this->queryBuilder->buildFrom(['DUAL'], $params);
        }

        $insertValues = [];
        $mergeSql = 'MERGE INTO '
            . $this->quoter->quoteTableName($table)
            . ' '
            . 'USING (' . ($usingValues ?? ltrim((string) $values, ' '))
            . ') "EXCLUDED" '
            . "ON ($on)";

        /** @psalm-var string[] $insertNames */
        foreach ($insertNames as $name) {
            $quotedName = $this->quoter->quoteColumnName($name);

            if (strrpos($quotedName, '.') === false) {
                $quotedName = '"EXCLUDED".' . $quotedName;
            }

            $insertValues[] = $quotedName;
        }

        $insertSql = 'INSERT (' . implode(', ', $insertNames) . ')' . ' VALUES (' . implode(', ', $insertValues) . ')';

        if ($updateColumns === false) {
            return "$mergeSql WHEN NOT MATCHED THEN $insertSql";
        }

        if ($updateColumns === true) {
            $updateColumns = [];
            /** @psalm-var string[] $updateNames */
            foreach ($updateNames as $name) {
                $quotedName = $this->quoter->quoteColumnName($name);

                if (strrpos($quotedName, '.') === false) {
                    $quotedName = '"EXCLUDED".' . $quotedName;
                }
                $updateColumns[$name] = new Expression($quotedName);
            }
        }

        /** @psalm-var string[] $updates */
        [$updates, $params] = $this->prepareUpdateSets($table, $updateColumns, (array) $params);
        $updateSql = 'UPDATE SET ' . implode(', ', $updates);

        return "$mergeSql WHEN MATCHED THEN $updateSql WHEN NOT MATCHED THEN $insertSql";
    }

    protected function prepareInsertValues(string $table, array|QueryInterface $columns, array $params = []): array
    {
        /**
         * @var array $names
         * @var array $placeholders
         */
        [$names, $placeholders, $values, $params] = parent::prepareInsertValues($table, $columns, $params);

        if (!$columns instanceof QueryInterface && empty($names)) {
            $tableSchema = $this->schema->getTableSchema($table);

            if ($tableSchema !== null) {
                $tableColumns = $tableSchema->getColumns();
                $columns = !empty($tableSchema->getPrimaryKey())
                    ? $tableSchema->getPrimaryKey() : [reset($tableColumns)->getName()];
                foreach ($columns as $name) {
                    /** @psalm-var mixed */
                    $names[] = $this->quoter->quoteColumnName($name);
                    $placeholders[] = 'DEFAULT';
                }
            }
        }

        return [$names, $placeholders, $values, $params];
    }

    public function resetSequence(string $table, int|string $value = null): string
    {
        $tableSchema = $this->schema->getTableSchema($table);

        if ($tableSchema === null) {
            throw new InvalidArgumentException("Table not found: '$table'.");
        }

        $sequenceName = $tableSchema->getSequenceName();

        if ($sequenceName === null) {
            throw new InvalidArgumentException("There is not sequence associated with table '$table'.");
        }

        if ($value === null && count($tableSchema->getPrimaryKey()) > 1) {
            throw new InvalidArgumentException("Can't reset sequence for composite primary key in table: $table");
        }

        /**
         * Oracle needs at least many queries to reset a sequence (see adding transactions and/or use an alter method to
         * avoid grant issue?)
         */
        return 'declare
    lastSeq number' . ($value !== null ? (' := ' . $value) : '') . ';
begin' . ($value === null ? '
    SELECT MAX("' . $tableSchema->getPrimaryKey()[0] . '") + 1 INTO lastSeq FROM "' . $tableSchema->getName() . '";' : '') . '
    if lastSeq IS NULL then lastSeq := 1; end if;
    execute immediate \'DROP SEQUENCE "' . $sequenceName . '"\';
    execute immediate \'CREATE SEQUENCE "' . $sequenceName . '" START WITH \' || lastSeq || \' INCREMENT BY 1 NOMAXVALUE NOCACHE\';
end;';
    }
}
