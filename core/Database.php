<?php

class Database
{
    private static ?PDO $pdo = null;

    /**
     * Initialize database connection
     *
     * @param array $config [
     *     'host' => 'localhost',
     *     'dbname' => 'dbname',
     *     'user' => 'dbuser',
     *     'pass' => 'dbpass',
     *     'charset' => 'utf8mb4'
     * ]
     * @throws PDOException
     */
    public static function init(array $config): void
    {
        if (self::$pdo === null)
        {
            $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,          // Throw exceptions
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Fetch associative arrays
                PDO::ATTR_EMULATE_PREPARES => false,                  // Use native prepares
            ];

            try
            {
                self::$pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
            }
            catch (PDOException $e)
            {
                // Log error to server log, do not expose details to user
                error_log("Database connection error: " . $e->getMessage());
                throw new PDOException("Database connection failed.");
            }
        }
    }

    /**
     * Execute a query with optional parameters
     *
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        self::ensureConnection();
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Fetch single row
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $stmt = self::query($sql, $params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);

        return $stmt->fetchAll();
    }

    /**
     * Insert a row and return last insert ID
     *
     * @param string $sql
     * @param array $params
     * @return string Last insert ID
     */
    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);

        return self::$pdo->lastInsertId();
    }

    /**
     * Update or delete row(s)
     *
     * @param string $sql
     * @param array $params
     * @return int Rows affected
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): void
    {
        self::ensureConnection();
        self::$pdo->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): void
    {
        self::ensureConnection();
        self::$pdo->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollBack(): void
    {
        self::ensureConnection();
        self::$pdo->rollBack();
    }

    /**
     * Ensure PDO connection exists
     */
    private static function ensureConnection(): void
    {
        if (self::$pdo === null)
        {
            throw new RuntimeException("Database not initialized. Call DB::init() first.");
        }
    }

    /**
     * Get PDO instance (if needed)
     *
     * @return PDO
     */
    public static function getPDO(): PDO
    {
        self::ensureConnection();

        return self::$pdo;
    }
}
