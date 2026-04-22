<?php
/**
 * Database Connection — shim extending TpCommon\Database\Connection
 *
 * Keeps legacy API (getInstance, getConnection, beginTransaction, etc.)
 * while delegating to the shared library.
 *
 * @see TpCommon\Database\Connection
 */

use TpCommon\Database\Connection;

class Database
{
    private static ?self $instance = null;

    private function __construct()
    {
        Connection::configureFromConstants();
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
        return Connection::getConnection();
    }

    public function beginTransaction(): bool
    {
        return Connection::getInstance()->beginTransaction();
    }

    public function commit(): bool
    {
        return Connection::getInstance()->commit();
    }

    public function rollback(): bool
    {
        return Connection::getInstance()->rollback();
    }

    public function lastInsertId(): string
    {
        return Connection::getInstance()->lastInsertId();
    }

    private function __clone() {}

    public function __wakeup(): never
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
