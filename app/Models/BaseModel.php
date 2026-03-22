<?php

/**
 * Base model shared database helpers.
 *
 * Models in this project remain lightweight static wrappers so they fit the
 * existing controller style without introducing dependency injection or
 * changing runtime boot order.
 */
abstract class BaseModel
{
    /**
     * Execute one query and return the PDO statement.
     *
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    protected static function query(string $sql, array $params = []): PDOStatement
    {
        return Database::query($sql, $params);
    }

    /**
     * Fetch a single row.
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    protected static function fetch(string $sql, array $params = []): ?array
    {
        return Database::fetch($sql, $params);
    }

    /**
     * Fetch all rows.
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    protected static function fetchAll(string $sql, array $params = []): array
    {
        return Database::fetchAll($sql, $params);
    }

    /**
     * Execute a data-changing query.
     *
     * @param string $sql
     * @param array $params
     * @return int
     */
    protected static function execute(string $sql, array $params = []): int
    {
        return Database::execute($sql, $params);
    }

    /**
     * Execute an insert and return the new identifier.
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    protected static function insert(string $sql, array $params = []): string
    {
        return Database::insert($sql, $params);
    }

    /**
     * Access the shared PDO instance for bound integer limits/offsets.
     *
     * @return PDO
     */
    protected static function getPDO(): PDO
    {
        return Database::getPDO();
    }
}
