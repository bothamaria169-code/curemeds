<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    public static function connect(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
        return self::$instance;
    }
}

function db(): PDO
{
    return Database::connect();
}

/** Generic INSERT from an assoc array; returns the new row id. */
function db_insert(string $table, array $data): int
{
    $cols  = array_keys($data);
    $ph    = array_map(fn($c) => ':' . $c, $cols);
    $sql   = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols), implode(',', $ph));
    $stmt  = db()->prepare($sql);
    $stmt->execute(array_combine($ph, array_values($data)));
    return (int) db()->lastInsertId();
}

/** Generic UPDATE by id from an assoc array. */
function db_update(string $table, int $id, array $data, string $pk = 'id'): bool
{
    if (empty($data)) {
        return true;
    }
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = "`$col` = :$col";
    }
    $sql  = sprintf('UPDATE `%s` SET %s WHERE `%s` = :__pk', $table, implode(',', $sets), $pk);
    $stmt = db()->prepare($sql);
    $params = [];
    foreach ($data as $k => $v) {
        $params[":$k"] = $v;
    }
    $params[':__pk'] = $id;
    return $stmt->execute($params);
}
