<?php
/**
 * Database Connection (Singleton)
 *
 * When TpCommon is available, delegates to TpCommon\Database\Connection.
 * Otherwise, falls back to standalone PDO singleton.
 */
class Database
{
    private static ?self $instance = null;
    private PDO $connection;

    private function __construct()
    {
        if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE) {
            \TpCommon\Database\Connection::configureFromConstants();
            $this->connection = \TpCommon\Database\Connection::getConnection();
        } else {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            try {
                $this->connection = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
            } catch (PDOException $e) {
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    throw new RuntimeException("Database connection failed: " . $e->getMessage());
                }
                throw new RuntimeException("Database connection failed");
            }
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    private function __clone() {}

    public function __wakeup(): never
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
