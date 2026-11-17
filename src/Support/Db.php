<?php

declare(strict_types=1);

namespace Agente\Support;

use PDO;

/**
 * Una sola base de datos, dos usuarios:
 *  - agente: SELECT/INSERT/UPDATE solo sobre ndb_agent_* (escribe el estado del agente).
 *  - solo lectura: SELECT sobre toda la base (reservado para consultas de solo lectura; hoy ningún paso lo usa).
 */
final class Db
{
    public static function agent(Config $c): PDO
    {
        return self::connect($c, $c->require('DB_AGENT_USER'), $c->require('DB_AGENT_PASS'), false);
    }

    public static function readonly(Config $c): PDO
    {
        return self::connect($c, $c->require('DB_RO_USER'), $c->require('DB_RO_PASS'), true);
    }

    /** Conexión administrativa: solo la usan las herramientas de migración. */
    public static function admin(Config $c): PDO
    {
        return self::connect($c, $c->require('DB_ADMIN_USER'), $c->require('DB_ADMIN_PASS'), false);
    }

    private static function connect(Config $c, string $user, string $pass, bool $readOnly): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c->require('DB_HOST'),
            $c->int('DB_PORT', 3306),
            $c->require('DB_NAME'),
        );
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => $c->int('DB_TIMEOUT', 10),
        ]);
        // Fechas siempre en UTC dentro de la BD del agente; la hora del tenant se aplica en PHP.
        $pdo->exec("SET time_zone = '+00:00'");
        if ($readOnly) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('SET SESSION max_execution_time = ' . ($c->int('DB_RO_QUERY_TIMEOUT', 30) * 1000));
        }

        return $pdo;
    }
}
