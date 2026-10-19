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
            // cPanel prefixes database users with the account name ("curemedsco_"),
            // so if the user as written is refused, try it with the database's prefix.
            $users  = [DB_USER];
            $prefix = strstr(DB_NAME, '_', true);
            if ($prefix && !str_starts_with(DB_USER, $prefix . '_')) {
                $users[] = $prefix . '_' . DB_USER;
            }
            $last = null;
            foreach ($users as $user) {
                try {
                    self::$instance = new PDO($dsn, $user, DB_PASS, $options);
                    break;
                } catch (PDOException $e) {
                    $last = $e;
                }
            }
            if (self::$instance === null) {
                self::fail($last, $users);
            }
        }
        return self::$instance;
    }

    /**
     * A plain-language page instead of a blank "HTTP ERROR 500" when the site
     * can't reach its database. Shows no password; it names only what to check.
     */
    private static function fail(?PDOException $e, array $users): never
    {
        error_log('CureMeds database connection failed: ' . ($e ? $e->getMessage() : 'unknown'));
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Database connection failed: ' . ($e ? $e->getMessage() : 'unknown') . "\n");
            exit(1);
        }
        $code = $e ? (int) $e->errorInfo[1] : 0;
        $hint = match ($code) {
            1045    => 'The database refused the username or password.',
            1044, 1049 => 'The database name is wrong, or the user has not been added to that database.',
            2002    => 'The database server could not be reached (DB host).',
            default => 'The database connection failed (error ' . $code . ').',
        };
        $secrets = is_file(__DIR__ . '/secrets.php');
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>CureMeds — setup needed</title>'
           . '<body style="font-family:system-ui,Segoe UI,Arial,sans-serif;background:#F6F7F9;color:#101A2B;margin:0;padding:40px 16px">'
           . '<div style="max-width:620px;margin:auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 2px 12px rgba(16,26,43,.08)">'
           . '<h1 style="margin-top:0;font-size:22px;color:#0A1B3D">CureMeds can\'t reach its database yet</h1>'
           . '<p><b>' . $h($hint) . '</b></p><ol style="line-height:1.8">'
           . ($secrets ? '' : '<li><b>config/secrets.php is missing on the server.</b> Upload it from the build computer to <code>public_html/config/</code> with cPanel File Manager.</li>')
           . '<li>In <code>config/secrets.php</code> check <code>db_name</code> = <code>' . $h(DB_NAME) . '</code> and the password.</li>'
           . '<li>The site tried the user ' . implode(' and ', array_map(fn($u) => '<code>' . $h($u) . '</code>', $users)) . '. In cPanel → MySQL® Databases, check which one exists, its password, and that it is <b>added to the database with ALL PRIVILEGES</b>.</li>'
           . '</ol><p style="color:#7C8899;font-size:13px;margin-bottom:0">This page disappears as soon as the connection works.</p></div></body>';
        exit;
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
