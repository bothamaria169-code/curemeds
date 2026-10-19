<?php
declare(strict_types=1);

/**
 * The patient health tracker: medication schedules, dose logging, adherence,
 * email reminders, side-effect reports and health readings.
 *
 * Patients sign in with their cellphone number and a 4-digit PIN. A PIN has only
 * 10 000 possibilities, so failed attempts are counted on the account itself
 * (not the session, which an attacker can simply drop): five wrong PINs lock the
 * account for fifteen minutes.
 *
 * Times are worked out in PHP (Africa/Johannesburg) and passed to MySQL as
 * parameters, so a database server set to UTC never shifts a dose by two hours.
 */
final class Health
{
    public const PIN_MAX_FAILS   = 5;
    /** Checked when a number has no account, so both cases take the same time. */
    private const DECOY_HASH = '$2y$12$4cjr.jsurmou0rOU9aPNm.5x0aYEu44AV59PNc8kb6dmEAN7RDh6m';
    public const PIN_LOCK_MINUTES = 15;

    /** A dose this long overdue with nothing logged counts as missed. */
    public const MISSED_AFTER_MINUTES = 120;

    /** Reminders go out for doses that fell due within this window, so a cron every 5–15 minutes catches each once. */
    public const REMINDER_WINDOW_MINUTES = 30;

    /** A refill reminder goes out when this many days' supply is left. */
    public const REFILL_DAYS = 7;

    public const SEVERITIES = [
        'mild'     => ['label' => 'Mild',     'tone' => 'muted',  'hint' => 'Noticeable, but not getting in the way of my day'],
        'moderate' => ['label' => 'Moderate', 'tone' => 'warn',   'hint' => 'Bothering me and affecting what I can do'],
        'severe'   => ['label' => 'Severe',   'tone' => 'danger', 'hint' => 'Very unwell — I need help'],
    ];

    // ── Phone + PIN ──────────────────────────────────────────────────────

    /**
     * South African cellphone number as digits only, 27XXXXXXXXX.
     * Accepts "082 123 4567", "+27 82 123 4567", "27821234567" and "0821234567".
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '27' . substr($digits, 1);
        }
        return preg_match('/^27[1-8]\d{8}$/', $digits) ? $digits : null;
    }

    /** 27821234567 -> 082 123 4567 */
    public static function displayPhone(?string $phone): string
    {
        $p = (string) $phone;
        return preg_match('/^27(\d{2})(\d{3})(\d{4})$/', $p, $m) ? "0{$m[1]} {$m[2]} {$m[3]}" : $p;
    }

    public static function validPin(string $pin): bool
    {
        // Reject the PINs people guess first.
        return (bool) preg_match('/^\d{4}$/', $pin)
            && !in_array($pin, ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321', '0123'], true);
    }

    public static function hashPin(string $pin): string
    {
        return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** A PIN a person can read out over WhatsApp, avoiding the obvious ones. */
    public static function randomPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (!self::validPin($pin));
        return $pin;
    }

    /**
     * @return array{ok: bool, user?: array, error?: string}
     */
    public static function pinLogin(string $phone, string $pin): array
    {
        $generic = 'That number and PIN do not match. Check both and try again.';
        $number  = self::normalizePhone($phone);
        if (!$number) {
            return ['ok' => false, 'error' => 'Enter your cellphone number, for example 082 123 4567.'];
        }

        $stmt = db()->prepare('SELECT * FROM users WHERE phone = :p LIMIT 1');
        $stmt->execute([':p' => $number]);
        $user = $stmt->fetch();

        if (!$user || empty($user['pin_hash'])) {
            // Same work and the same answer as a wrong PIN, so the form can't be
            // used to find out which numbers are patients.
            password_verify($pin, self::DECOY_HASH);
            return ['ok' => false, 'error' => $generic];
        }
        if (!empty($user['pin_locked_until']) && strtotime($user['pin_locked_until']) > time()) {
            $mins = (int) ceil((strtotime($user['pin_locked_until']) - time()) / 60);
            return ['ok' => false, 'error' => "Too many wrong PINs. Try again in $mins minute" . ($mins === 1 ? '' : 's') . ', or WhatsApp us to reset it.'];
        }
        if (!password_verify($pin, $user['pin_hash'])) {
            $fails = (int) $user['pin_failed'] + 1;
            $lock  = $fails >= self::PIN_MAX_FAILS ? date('Y-m-d H:i:s', time() + self::PIN_LOCK_MINUTES * 60) : null;
            db()->prepare('UPDATE users SET pin_failed = :f, pin_locked_until = :l WHERE id = :id')
                ->execute([':f' => $lock ? 0 : $fails, ':l' => $lock, ':id' => $user['id']]);
            return ['ok' => false, 'error' => $lock
                ? 'Too many wrong PINs. Your account is locked for ' . self::PIN_LOCK_MINUTES . ' minutes.'
                : $generic];
        }
        if (!$user['is_active']) {
            return ['ok' => false, 'error' => 'That account is switched off. Please WhatsApp us.'];
        }

        db()->prepare('UPDATE users SET pin_failed = 0, pin_locked_until = NULL, last_login_at = :now WHERE id = :id')
            ->execute([':now' => date('Y-m-d H:i:s'), ':id' => $user['id']]);
        return ['ok' => true, 'user' => $user];
    }

    // ── Medications ──────────────────────────────────────────────────────

    public static function medication(int $id, ?int $userId = null): ?array
    {
        $sql = 'SELECT m.*, p.featured_image, p.slug AS product_slug FROM patient_medications m
                LEFT JOIN products p ON p.id = m.product_id WHERE m.id = :id';
        $params = [':id' => $id];
        if ($userId !== null) {
            $sql .= ' AND m.user_id = :u';
            $params[':u'] = $userId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array> */
    public static function medications(int $userId, bool $activeOnly = false): array
    {
        $stmt = db()->prepare('SELECT m.*, p.featured_image, p.slug AS product_slug FROM patient_medications m
                               LEFT JOIN products p ON p.id = m.product_id
                               WHERE m.user_id = :u' . ($activeOnly ? " AND m.status = 'active'" : '') . '
                               ORDER BY FIELD(m.status, \'active\', \'paused\', \'completed\'), m.name');
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll();
    }

    /** "8:00, 20:00 ,8:00" -> ["08:00", "20:00"] */
    public static function parseTimes(string $times): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $times) ?: [] as $t) {
            if (preg_match('/^([01]?\d|2[0-3])[:h.]?([0-5]\d)$/', trim($t), $m)) {
                $out[] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** Validates and saves a medication from a form post. Returns [id, errors]. */
    public static function saveMedication(int $userId, array $in, ?int $id = null, ?int $addedBy = null): array
    {
        $errors = [];
        $name   = trim((string) ($in['name'] ?? ''));
        $productId = (int) ($in['product_id'] ?? 0) ?: null;
        if ($name === '' && $productId) {
            $p = (new ProductRepository())->getById($productId);
            $name = (string) ($p['name'] ?? '');
        }
        $times = self::parseTimes((string) ($in['dose_times'] ?? ''));
        $start = (string) ($in['start_date'] ?? '') ?: date('Y-m-d');
        $end   = (string) ($in['end_date'] ?? '');

        if (mb_strlen($name) < 2)                                   { $errors[] = 'Give the medication a name.'; }
        if (!$times)                                                { $errors[] = 'Add at least one dose time, for example 08:00.'; }
        if (count($times) > 6)                                      { $errors[] = 'Six dose times a day is the most we can remind you about.'; }
        if (!strtotime($start))                                     { $errors[] = 'Choose a start date.'; }
        if ($end !== '' && strtotime($end) < strtotime($start))     { $errors[] = 'The end date is before the start date.'; }

        if ($errors) {
            return [null, $errors];
        }

        $units = trim((string) ($in['units_left'] ?? ''));
        $data = [
            'product_id'     => $productId,
            'name'           => mb_substr(strip_tags($name), 0, 200),
            'dosage'         => mb_substr(strip_tags(trim((string) ($in['dosage'] ?? ''))), 0, 120) ?: null,
            'instructions'   => mb_substr(strip_tags(trim((string) ($in['instructions'] ?? ''))), 0, 255) ?: null,
            'dose_times'     => implode(',', $times),
            'start_date'     => date('Y-m-d', strtotime($start)),
            'end_date'       => $end !== '' ? date('Y-m-d', strtotime($end)) : null,
            'units_left'     => $units !== '' ? max(0, (int) $units) : null,
            'units_per_dose' => max(0.25, (float) ($in['units_per_dose'] ?? 1) ?: 1),
            'reminders'      => !empty($in['reminders']) ? 1 : 0,
            'status'         => in_array($in['status'] ?? '', ['active', 'paused', 'completed'], true) ? $in['status'] : 'active',
        ];

        if ($id) {
            $old = self::medication($id, $userId);
            if (!$old) {
                return [null, ['That medication was not found.']];
            }
            // A restock means the next "running low" email should go out again.
            if ($data['units_left'] !== null && (int) $data['units_left'] > (int) $old['units_left']) {
                $data['refill_notified_at'] = null;
            }
            db_update('patient_medications', $id, $data);
            return [$id, []];
        }

        $data['user_id']  = $userId;
        $data['added_by'] = $addedBy;
        return [db_insert('patient_medications', $data), []];
    }

    public static function dosesPerDay(array $med): int
    {
        return max(1, count(self::parseTimes((string) $med['dose_times'])));
    }

    /** Days of supply left, or null when nobody is counting. */
    public static function daysLeft(array $med): ?int
    {
        if ($med['units_left'] === null) {
            return null;
        }
        $perDay = self::dosesPerDay($med) * max(0.25, (float) $med['units_per_dose']);
        return (int) floor((int) $med['units_left'] / $perDay);
    }

    /** Is this medication scheduled on that date at all? */
    private static function runsOn(array $med, string $date): bool
    {
        return $med['status'] === 'active'
            && $med['start_date'] <= $date
            && ($med['end_date'] === null || $med['end_date'] >= $date);
    }

    // ── Doses ────────────────────────────────────────────────────────────

    /**
     * Every dose on one day for one patient, in time order, each with a state:
     * taken | skipped | due (within the last two hours) | missed | upcoming.
     *
     * @return list<array>
     */
    public static function daySchedule(int $userId, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $now  = time();
        $meds = array_filter(self::medications($userId, true), fn($m) => self::runsOn($m, $date));
        if (!$meds) {
            return [];
        }

        $logs = db()->prepare('SELECT medication_id, scheduled_for, status FROM dose_logs
                               WHERE user_id = :u AND scheduled_for BETWEEN :a AND :b');
        $logs->execute([':u' => $userId, ':a' => "$date 00:00:00", ':b' => "$date 23:59:59"]);
        $logged = [];
        foreach ($logs as $l) {
            $logged[$l['medication_id'] . '|' . $l['scheduled_for']] = $l['status'];
        }

        $doses = [];
        foreach ($meds as $m) {
            foreach (self::parseTimes((string) $m['dose_times']) as $time) {
                $at  = "$date $time:00";
                $ts  = strtotime($at);
                $log = $logged[$m['id'] . '|' . $at] ?? null;
                $state = $log ?? match (true) {
                    $ts > $now                                         => 'upcoming',
                    $ts > $now - self::MISSED_AFTER_MINUTES * 60       => 'due',
                    default                                            => 'missed',
                };
                $doses[] = ['medication' => $m, 'time' => $time, 'scheduled_for' => $at, 'state' => $state];
            }
        }
        usort($doses, fn($a, $b) => strcmp($a['time'], $b['time']) ?: strcmp($a['medication']['name'], $b['medication']['name']));
        return $doses;
    }

    /**
     * Records a dose. Taking one counts the stock down. Logging the same dose
     * twice just updates it.
     */
    public static function logDose(int $userId, int $medicationId, string $scheduledFor, string $status = 'taken', string $source = 'portal'): bool
    {
        $med = self::medication($medicationId, $userId);
        $ts  = strtotime($scheduledFor);
        if (!$med || !$ts || !in_array($status, ['taken', 'skipped'], true)) {
            return false;
        }
        $at = date('Y-m-d H:i:s', $ts);
        // Only a real dose time of this medication can be logged.
        if (!in_array(date('H:i', $ts), self::parseTimes((string) $med['dose_times']), true) || $ts > time() + 12 * 3600) {
            return false;
        }

        $prev = db()->prepare('SELECT status FROM dose_logs WHERE medication_id = :m AND scheduled_for = :s');
        $prev->execute([':m' => $medicationId, ':s' => $at]);
        $before = $prev->fetchColumn() ?: null;

        db()->prepare('INSERT INTO dose_logs (medication_id, user_id, scheduled_for, status, source, logged_at)
                       VALUES (:m, :u, :s, :st, :src, :now)
                       ON DUPLICATE KEY UPDATE status = :st2, source = :src2, logged_at = :now2')
            ->execute([':m' => $medicationId, ':u' => $userId, ':s' => $at, ':st' => $status, ':src' => $source,
                       ':now' => date('Y-m-d H:i:s'), ':st2' => $status, ':src2' => $source, ':now2' => date('Y-m-d H:i:s')]);

        if ($med['units_left'] !== null && $before !== $status) {
            $delta = (float) $med['units_per_dose'];
            if ($status === 'taken') {
                db()->prepare('UPDATE patient_medications SET units_left = GREATEST(0, units_left - :d) WHERE id = :id')
                    ->execute([':d' => (int) ceil($delta), ':id' => $medicationId]);
            } elseif ($before === 'taken') {
                db()->prepare('UPDATE patient_medications SET units_left = units_left + :d WHERE id = :id')
                    ->execute([':d' => (int) ceil($delta), ':id' => $medicationId]);
            }
        }
        return true;
    }

    /**
     * Adherence per day for the last $days days (today included, counting only
     * doses whose time has passed). Days with nothing scheduled are left out.
     *
     * @return array<string, array{taken:int, skipped:int, missed:int, total:int, pct:int}>
     */
    public static function dailyAdherence(int $userId, int $days = 14): array
    {
        $meds = self::medications($userId);
        if (!$meds) {
            return [];
        }
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $stmt = db()->prepare('SELECT medication_id, scheduled_for, status FROM dose_logs WHERE user_id = :u AND scheduled_for >= :f');
        $stmt->execute([':u' => $userId, ':f' => "$from 00:00:00"]);
        $logged = [];
        foreach ($stmt as $l) {
            $logged[$l['medication_id'] . '|' . $l['scheduled_for']] = $l['status'];
        }

        $cutoff = time() - self::MISSED_AFTER_MINUTES * 60;
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $row  = ['taken' => 0, 'skipped' => 0, 'missed' => 0, 'total' => 0, 'pct' => 0];
            foreach ($meds as $m) {
                // Paused or finished medication still counts for the days it was running.
                $ran = $m['start_date'] <= $date && ($m['end_date'] === null || $m['end_date'] >= $date)
                    && ($m['status'] === 'active' || substr((string) $m['updated_at'], 0, 10) > $date);
                if (!$ran) {
                    continue;
                }
                foreach (self::parseTimes((string) $m['dose_times']) as $time) {
                    $at = "$date $time:00";
                    $status = $logged[$m['id'] . '|' . $at] ?? null;
                    if ($status === null && strtotime($at) > $cutoff) {
                        continue; // not due yet, or still within its grace period
                    }
                    $row[$status ?? 'missed']++;
                    $row['total']++;
                }
            }
            if ($row['total'] > 0) {
                $row['pct'] = (int) round($row['taken'] / $row['total'] * 100);
                $out[$date] = $row;
            }
        }
        return $out;
    }

    /** @return array{pct: ?int, taken: int, total: int} */
    public static function adherence(int $userId, int $days = 7): array
    {
        $rows  = self::dailyAdherence($userId, $days);
        $taken = array_sum(array_column($rows, 'taken'));
        $total = array_sum(array_column($rows, 'total'));
        return ['pct' => $total ? (int) round($taken / $total * 100) : null, 'taken' => $taken, 'total' => $total];
    }

    /** Consecutive days, ending yesterday or today, on which every due dose was taken. */
    public static function streak(int $userId): int
    {
        $rows = self::dailyAdherence($userId, 60);
        $streak = 0;
        foreach (array_reverse($rows, true) as $date => $row) {
            if ($row['taken'] === $row['total']) {
                $streak++;
            } elseif ($date === date('Y-m-d')) {
                continue; // today isn't over yet
            } else {
                break;
            }
        }
        return $streak;
    }

    // ── Signed links in reminder emails ──────────────────────────────────

    public static function doseSignature(int $medicationId, string $scheduledFor, int $userId): string
    {
        return substr(hash_hmac('sha256', "$medicationId|$scheduledFor|$userId", REMINDER_SECRET), 0, 32);
    }

    public static function doseLink(array $med, string $scheduledFor): string
    {
        return SITE_URL . '/dose.php?' . http_build_query([
            'm' => (int) $med['id'],
            't' => strtotime($scheduledFor),
            's' => self::doseSignature((int) $med['id'], $scheduledFor, (int) $med['user_id']),
        ]);
    }

    // ── Reminders (run by scripts/cron.php) ──────────────────────────────

    /**
     * Emails every dose that fell due in the last REMINDER_WINDOW_MINUTES and
     * hasn't been logged or reminded yet, plus "running low" refill notices.
     * Safe to run as often as you like: reminder_log has a unique key, and a
     * row is claimed before the email goes out.
     *
     * @return array{sent:int, failed:int, refills:int}
     */
    public static function sendDueReminders(?int $now = null): array
    {
        $now   = $now ?? time();
        $today = date('Y-m-d', $now);
        $stats = ['sent' => 0, 'failed' => 0, 'refills' => 0];

        $stmt = db()->prepare("SELECT m.*, u.name AS patient_name, u.email AS patient_email, u.phone
                               FROM patient_medications m JOIN users u ON u.id = m.user_id
                               WHERE m.status = 'active' AND m.reminders = 1
                                 AND u.is_active = 1 AND u.reminders_enabled = 1
                                 AND u.email IS NOT NULL AND u.email <> ''
                                 AND m.start_date <= :d1 AND (m.end_date IS NULL OR m.end_date >= :d2)");
        $stmt->execute([':d1' => $today, ':d2' => $today]);

        foreach ($stmt->fetchAll() as $med) {
            foreach (self::parseTimes((string) $med['dose_times']) as $time) {
                $at = "$today $time:00";
                $ts = strtotime($at);
                if ($ts > $now || $ts < $now - self::REMINDER_WINDOW_MINUTES * 60) {
                    continue;
                }
                $done = db()->prepare('SELECT 1 FROM dose_logs WHERE medication_id = :m AND scheduled_for = :s');
                $done->execute([':m' => $med['id'], ':s' => $at]);
                if ($done->fetchColumn()) {
                    continue;
                }
                if (!self::claimReminder((int) $med['id'], (int) $med['user_id'], 'dose', $at)) {
                    continue; // another run already sent it
                }
                $ok = Mailer::send((string) $med['patient_email'], 'Time for your ' . $med['name'], self::doseEmail($med, $at));
                self::finishReminder((int) $med['id'], 'dose', $at, $ok);
                $stats[$ok ? 'sent' : 'failed']++;
            }

            $daysLeft = self::daysLeft($med);
            if ($daysLeft !== null && $daysLeft <= self::REFILL_DAYS && empty($med['refill_notified_at'])) {
                db()->prepare('UPDATE patient_medications SET refill_notified_at = :n WHERE id = :id')
                    ->execute([':n' => date('Y-m-d H:i:s', $now), ':id' => $med['id']]);
                $ok = Mailer::send((string) $med['patient_email'], 'Running low: ' . $med['name'], self::refillEmail($med, $daysLeft));
                self::claimReminder((int) $med['id'], (int) $med['user_id'], 'refill', date('Y-m-d H:i:s', $now));
                self::finishReminder((int) $med['id'], 'refill', date('Y-m-d H:i:s', $now), $ok);
                $stats['refills']++;
            }
        }
        set_setting('reminders_last_run', date('Y-m-d H:i:s', $now));
        return $stats;
    }

    private static function claimReminder(int $medId, int $userId, string $kind, string $at): bool
    {
        $stmt = db()->prepare("INSERT IGNORE INTO reminder_log (medication_id, user_id, kind, scheduled_for, status, created_at)
                               VALUES (:m, :u, :k, :s, 'skipped', :now)");
        $stmt->execute([':m' => $medId, ':u' => $userId, ':k' => $kind, ':s' => $at, ':now' => date('Y-m-d H:i:s')]);
        return $stmt->rowCount() === 1;
    }

    private static function finishReminder(int $medId, string $kind, string $at, bool $ok): void
    {
        db()->prepare('UPDATE reminder_log SET status = :st, note = :n WHERE medication_id = :m AND kind = :k AND scheduled_for = :s')
            ->execute([':st' => $ok ? 'sent' : 'failed', ':n' => $ok ? null : 'Mail server refused or unreachable',
                       ':m' => $medId, ':k' => $kind, ':s' => $at]);
    }

    public static function doseEmail(array $med, string $scheduledFor): string
    {
        $link   = self::doseLink($med, $scheduledFor);
        $portal = SITE_URL . '/dashboards/customer/index.php';
        $first  = e(explode(' ', trim((string) ($med['patient_name'] ?? '')))[0] ?? '');
        $html = '<p style="font-size:17px;margin:0 0 6px;">Hi ' . $first . ',</p>'
              . '<p>It is <b>' . date('H:i', strtotime($scheduledFor)) . '</b> — time for your dose of:</p>'
              . '<div style="background:#E8F0FC;border-radius:12px;padding:16px 18px;margin:14px 0;">'
              . '<div style="font-size:18px;font-weight:800;color:#0A1B3D;">' . e($med['name']) . '</div>'
              . ($med['dosage'] ? '<div style="margin-top:4px;">' . e($med['dosage']) . '</div>' : '')
              . ($med['instructions'] ? '<div style="margin-top:2px;color:#4A5768;">' . e($med['instructions']) . '</div>' : '')
              . '</div>'
              . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1557D0;color:#fff;padding:13px 26px;border-radius:999px;font-weight:700;text-decoration:none;">I took it ✓</a></p>'
              . '<p style="font-size:13px;color:#7C8899;">Feeling any side effects? <a href="' . $portal . '" style="color:#1557D0;">Tell us in your health tracker</a>. '
              . 'If you feel very unwell, go to your nearest clinic or call 10177.</p>';
        return email_wrap($html, null, 'Time for ' . $med['name'] . ' — tap to log it');
    }

    public static function refillEmail(array $med, int $daysLeft): string
    {
        $wa = wa_link('Hi CureMeds, I would like to reorder ' . $med['name'] . '. My number is ' . self::displayPhone((string) ($med['phone'] ?? '')) . '.');
        $html = '<p>Hi ' . e(explode(' ', trim((string) ($med['patient_name'] ?? '')))[0] ?? '') . ',</p>'
              . '<p>You have about <b>' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . '</b> of <b>' . e($med['name']) . '</b> left.</p>'
              . '<p>Reorder now so you don\'t run out:</p>'
              . '<p><a href="' . e($wa) . '" style="display:inline-block;background:#25D366;color:#fff;padding:13px 26px;border-radius:999px;font-weight:700;text-decoration:none;">Reorder on WhatsApp</a></p>';
        return email_wrap($html, null, 'About ' . $daysLeft . ' days of ' . $med['name'] . ' left');
    }

    // ── Side effects ─────────────────────────────────────────────────────

    public static function reportSideEffect(int $userId, array $in): array
    {
        $symptom  = mb_substr(strip_tags(trim((string) ($in['symptom'] ?? ''))), 0, 160);
        $severity = (string) ($in['severity'] ?? 'mild');
        $medId    = (int) ($in['medication_id'] ?? 0) ?: null;
        $errors   = [];
        if (mb_strlen($symptom) < 2)                       { $errors[] = 'Tell us what you are feeling.'; }
        if (!isset(self::SEVERITIES[$severity]))           { $errors[] = 'Choose how bad it is.'; }
        if ($medId && !self::medication($medId, $userId))  { $medId = null; }
        if ($errors) {
            return [null, $errors];
        }

        $id = db_insert('side_effects', [
            'user_id'       => $userId,
            'medication_id' => $medId,
            'symptom'       => $symptom,
            'severity'      => $severity,
            'started_on'    => !empty($in['started_on']) && strtotime((string) $in['started_on']) ? date('Y-m-d', strtotime((string) $in['started_on'])) : null,
            'details'       => mb_substr(strip_tags(trim((string) ($in['details'] ?? ''))), 0, 2000) ?: null,
        ]);

        // Staff hear about every report; a severe one is flagged in the subject.
        $u = db()->prepare('SELECT name, phone FROM users WHERE id = :id');
        $u->execute([':id' => $userId]);
        $patient = $u->fetch() ?: ['name' => 'A patient', 'phone' => ''];
        $html = '<p><b>' . e($patient['name']) . '</b> (' . e(self::displayPhone($patient['phone'])) . ') reported a <b>' . e($severity) . '</b> side effect:</p>'
              . '<p style="font-size:17px;"><b>' . e($symptom) . '</b></p>'
              . (!empty($in['details']) ? '<p>' . nl2br(e((string) $in['details'])) . '</p>' : '')
              . '<p><a href="' . SITE_URL . '/dashboards/admin/patient.php?id=' . $userId . '#side-effects" style="display:inline-block;background:#1557D0;color:#fff;padding:11px 22px;border-radius:999px;font-weight:700;">Open the patient</a></p>';
        Mailer::send(ADMIN_EMAIL, ($severity === 'severe' ? '⚠ SEVERE side effect — ' : 'Side effect reported — ') . $patient['name'], email_wrap($html));

        return [$id, []];
    }

    /** @return list<array> */
    public static function sideEffects(?int $userId = null, ?string $status = null, int $limit = 100): array
    {
        $where = ['1=1'];
        $params = [];
        if ($userId !== null) { $where[] = 's.user_id = :u';  $params[':u'] = $userId; }
        if ($status !== null) { $where[] = 's.status = :st'; $params[':st'] = $status; }
        $stmt = db()->prepare('SELECT s.*, m.name AS medication_name, u.name AS patient_name, u.phone AS patient_phone, r.name AS reviewer_name
                               FROM side_effects s
                               JOIN users u ON u.id = s.user_id
                               LEFT JOIN patient_medications m ON m.id = s.medication_id
                               LEFT JOIN users r ON r.id = s.reviewed_by
                               WHERE ' . implode(' AND ', $where) . "
                               ORDER BY s.status = 'new' DESC, FIELD(s.severity, 'severe', 'moderate', 'mild'), s.created_at DESC
                               LIMIT " . (int) $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Health readings ──────────────────────────────────────────────────

    public static function saveReading(int $userId, array $in): array
    {
        $num = fn(string $k, float $min, float $max) => isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k]) && (float) $in[$k] >= $min && (float) $in[$k] <= $max
            ? (float) $in[$k] : null;
        $data = [
            'weight_kg' => $num('weight_kg', 20, 350),
            'systolic'  => $num('systolic', 60, 260),
            'diastolic' => $num('diastolic', 30, 160),
            'glucose'   => $num('glucose', 1, 40),
            'mood'      => $num('mood', 1, 5),
            'energy'    => $num('energy', 1, 5),
            'notes'     => mb_substr(strip_tags(trim((string) ($in['notes'] ?? ''))), 0, 255) ?: null,
        ];
        if (count(array_filter($data, fn($v) => $v !== null)) === 0) {
            return ['Fill in at least one reading.'];
        }
        if (($data['systolic'] === null) !== ($data['diastolic'] === null)) {
            return ['Blood pressure needs both numbers, for example 120 over 80.'];
        }
        $date = !empty($in['recorded_on']) && strtotime((string) $in['recorded_on']) ? date('Y-m-d', strtotime((string) $in['recorded_on'])) : date('Y-m-d');
        if ($date > date('Y-m-d')) {
            return ['A reading can\'t be in the future.'];
        }
        $cols = array_keys($data);
        $sql  = 'INSERT INTO health_readings (user_id, recorded_on, ' . implode(', ', $cols) . ') VALUES (:u, :d, :' . implode(', :', $cols) . ')
                 ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));
        $params = [':u' => $userId, ':d' => $date];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        db()->prepare($sql)->execute($params);
        return [];
    }

    /** @return list<array> newest first */
    public static function readings(int $userId, int $limit = 60): array
    {
        $stmt = db()->prepare('SELECT * FROM health_readings WHERE user_id = :u ORDER BY recorded_on DESC LIMIT ' . (int) $limit);
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll();
    }

    // ── Admin overview ───────────────────────────────────────────────────

    /** @return array{patients:int, active_meds:int, new_side_effects:int, severe_open:int, reminders_today:int, enquiries_new:int} */
    public static function stats(): array
    {
        $q = fn(string $sql, array $p = []) => (function () use ($sql, $p) {
            try {
                $s = db()->prepare($sql);
                $s->execute($p);
                return (int) $s->fetchColumn();
            } catch (Throwable) {
                return 0;
            }
        })();
        return [
            'patients'         => $q("SELECT COUNT(*) FROM users WHERE role = 'customer' AND pin_hash IS NOT NULL"),
            'active_meds'      => $q("SELECT COUNT(*) FROM patient_medications WHERE status = 'active'"),
            'new_side_effects' => $q("SELECT COUNT(*) FROM side_effects WHERE status = 'new'"),
            'severe_open'      => $q("SELECT COUNT(*) FROM side_effects WHERE status <> 'resolved' AND severity = 'severe'"),
            'reminders_today'  => $q("SELECT COUNT(*) FROM reminder_log WHERE status = 'sent' AND created_at >= :d", [':d' => date('Y-m-d') . ' 00:00:00']),
            'enquiries_new'    => $q("SELECT COUNT(*) FROM enquiries WHERE status = 'new'"),
        ];
    }
}
