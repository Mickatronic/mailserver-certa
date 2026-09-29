<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Aligne NOW() de MySQL sur le fuseau PHP (tableaux de bord par jour)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** Message lisible pour une erreur SQL (trigger, doublon, contrainte). */
function db_error_message(PDOException $e): string
{
    $info = $e->errorInfo ?? [];
    $code = (int)($info[1] ?? 0);
    return match (true) {
        ($info[0] ?? '') === '45000' => $info[2] ?? 'Donnée refusée par la base.',
        $code === 1062              => 'Cette valeur existe déjà (doublon).',
        $code === 1451              => 'Suppression impossible : des données y sont rattachées.',
        $code === 3819              => 'Format invalide (contrainte CHECK).',
        default                     => 'Erreur de base de données.',
    };
}

function db_log_exception(PDOException $e, string $context): void
{
    $info = $e->errorInfo ?? [];
    error_log(sprintf(
        '[database] %s; SQLSTATE=%s; driver_code=%s; message=%s',
        $context,
        (string)($info[0] ?? $e->getCode()),
        (string)($info[1] ?? ''),
        (string)($info[2] ?? $e->getMessage())
    ));
}
