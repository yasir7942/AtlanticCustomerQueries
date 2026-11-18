<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query goes through prepared statements with bound parameters.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @param array{host:string,port:int,name:string,user:string,pass:string} $cfg */
    public static function connect(array $cfg, ?string $databaseOverride = null): self
    {
        $name = $databaseOverride ?? $cfg['name'];
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']);
        if ($name !== '') {
            $dsn .= ';dbname=' . $name;
        }
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
            . "sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $params = array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, $params);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $value) {
            $stmt->bindValue($i + 1, $value, self::type($value));
        }
        if (!array_is_list($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . ltrim((string) $key, ':'), $value, self::type($value));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Build "?, ?, ?" for an IN() list. Callers must handle the empty case themselves.
     */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, max(1, $count), '?'));
    }

    public static function isDuplicateKey(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private static function type(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
