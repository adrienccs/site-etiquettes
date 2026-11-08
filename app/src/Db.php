<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

use PDO;

/** Petite surcouche PDO : requêtes préparées uniquement. */
final class Db
{
    /** @var PDO */
    public $pdo;

    public function __construct(array $cfg)
    {
        $this->pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Heure de Paris : MySQL mutualisé n'a pas toujours les noms de fuseaux, on passe le décalage
        $this->pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    public function run(string $sql, array $args = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public function one(string $sql, array $args = []): ?array
    {
        $row = $this->run($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $args = []): array
    {
        return $this->run($sql, $args)->fetchAll();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @return mixed */
    public function value(string $sql, array $args = [])
    {
        return $this->run($sql, $args)->fetchColumn();
    }

    /** Exécute $fn dans une transaction. @return mixed */
    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
