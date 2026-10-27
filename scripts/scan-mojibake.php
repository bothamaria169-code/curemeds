<?php
declare(strict_types=1);

/**
 * Finds text mangled by a CP1252 -> CP850 round trip.
 *
 * Some of the seed SQL was imported through a Windows console that re-encoded
 * it, so punctuation came out as the CP850 glyph sitting at the same byte the
 * CP1252 character used: "·" (0xB7) became "À", "–" (0x96) became "û", and so
 * on. The text is valid UTF-8, so nothing errors — it just reads as noise.
 *
 * This scans every text column of every table and reports what it finds.
 * With --fix it repairs them in place.
 *
 *   php scripts/scan-mojibake.php
 *   php scripts/scan-mojibake.php --fix
 */

if (!IS_CLI) {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/functions.php';

/**
 * Mangled glyph => what it should have been.
 *
 * Keyed by the CP850 character that the original CP1252 byte maps to. Only
 * characters that cannot plausibly appear in this catalogue's English/Afrikaans
 * copy are listed, so a legitimate "é" or "à" is never touched.
 */
const REPAIRS = [
    'À' => '·',   // 0xB7 middot
    'û' => '–',   // 0x96 en dash
    'ù' => '—',   // 0x97 em dash
    'Æ' => '’',   // 0x92 right single quote
    'æ' => '‘',   // 0x91 left single quote
    'ô' => '“',   // 0x93 left double quote
    'ö' => '”',   // 0x94 right double quote
    'à' => '…',   // 0x85 ellipsis
    'ò' => '•',   // 0x95 bullet
    'Ø' => '˜',   // 0x98
    'ƒ' => '™',   // 0x99
];

$fix = in_array('--fix', $argv, true);

$tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$totalRows = 0;
$totalHits = 0;

foreach ($tables as $table) {
    $cols = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

    $textCols = [];
    $pk = null;
    foreach ($cols as $c) {
        if (($c['Key'] ?? '') === 'PRI' && $pk === null) { $pk = $c['Field']; }
        if (preg_match('/char|text|blob/i', (string) $c['Type'])) { $textCols[] = $c['Field']; }
    }
    if ($textCols === [] || $pk === null) { continue; }

    $select = '`' . $pk . '`, `' . implode('`, `', $textCols) . '`';
    foreach (db()->query("SELECT $select FROM `$table`") as $row) {
        $updates = [];

        foreach ($textCols as $col) {
            $val = $row[$col];
            if (!is_string($val) || $val === '') { continue; }

            $fixed = strtr($val, REPAIRS);
            if ($fixed !== $val) {
                $updates[$col] = $fixed;
                $totalHits++;
                printf(
                    "%-18s #%-4s %-16s %s\n",
                    $table,
                    (string) $row[$pk],
                    $col,
                    mb_substr(trim(preg_replace('/\s+/', ' ', $val)), 0, 90)
                );
                printf("%-18s %-5s %-16s -> %s\n", '', '', '', mb_substr(trim(preg_replace('/\s+/', ' ', $fixed)), 0, 90));
            }
        }

        if ($updates !== [] && $fix) {
            $sets   = [];
            $params = [':pk' => $row[$pk]];
            foreach ($updates as $col => $newVal) {
                $sets[] = "`$col` = :c$col";
                $params[":c$col"] = $newVal;
            }
            $stmt = db()->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$pk` = :pk");
            $stmt->execute($params);
            $totalRows++;
        } elseif ($updates !== []) {
            $totalRows++;
        }
    }
}

echo "\n";
printf("%d mangled value(s) across %d row(s).\n", $totalHits, $totalRows);
echo $fix
    ? "Repaired. Re-run scripts/build-deploy-sql.php to regenerate the deploy file.\n"
    : "Dry run — nothing changed. Re-run with --fix to repair.\n";
