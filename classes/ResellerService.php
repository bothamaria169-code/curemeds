<?php
declare(strict_types=1);

/**
 * The reseller programme: applications, approval, tier movement and the
 * numbers a reseller dashboard needs.
 *
 * Tiers move on rolling 90-day PAID spend only — not on order count, not on
 * downline, not on anything a member cannot verify for themselves from their
 * own order history. That is deliberate: the thing that gets these programmes
 * into trouble is a reward rule nobody outside the company can check.
 */
final class ResellerService
{
    /** Short, human-readable, unambiguous (no O/0/I/1). */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'FC-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $stmt = db()->prepare('SELECT id FROM users WHERE reseller_code = :c LIMIT 1');
            $stmt->execute([':c' => $code]);
        } while ($stmt->fetch());
        return $code;
    }

    /** Records an application against an existing user account. */
    public static function apply(int $userId, array $data): void
    {
        db_update('users', $userId, [
            'reseller_status' => 'pending',
            'business_name'   => $data['business_name'] ?? null,
            'id_number'       => $data['id_number'] ?? null,
            'trading_area'    => $data['trading_area'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'sponsor_id'      => $data['sponsor_id'] ?? null,
            'applied_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public static function approve(int $userId): string
    {
        $user = self::find($userId);
        $code = $user['reseller_code'] ?: self::generateCode();

        db_update('users', $userId, [
            'reseller_status' => 'approved',
            'reseller_code'   => $code,
            'role'            => $user['role'] === 'admin' ? 'admin' : 'reseller',
            'approved_at'     => date('Y-m-d H:i:s'),
        ]);
        return $code;
    }

    public static function setStatus(int $userId, string $status): void
    {
        if (!in_array($status, RESELLER_STATUSES, true)) {
            return;
        }
        $patch = ['reseller_status' => $status];
        if ($status === 'approved') {
            self::approve($userId);
            return;
        }
        // Demoting an approved reseller also drops the role, so pricing follows.
        if (in_array($status, ['none', 'suspended'], true)) {
            $user = self::find($userId);
            if (($user['role'] ?? '') === 'reseller') {
                $patch['role'] = 'customer';
            }
        }
        db_update('users', $userId, $patch);
    }

    public static function setTier(int $userId, string $tier): void
    {
        if (isset(RESELLER_TIERS[$tier])) {
            db_update('users', $userId, ['reseller_tier' => $tier]);
        }
    }

    public static function find(int $userId): array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: [];
    }

    /** @param array{status?:string,tier?:string,search?:string} $f */
    public static function listAll(array $f = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ["u.reseller_status <> 'none'"];
        $params = [];
        if (!empty($f['status'])) { $where[] = 'u.reseller_status = :s'; $params[':s'] = $f['status']; }
        if (!empty($f['tier']))   { $where[] = 'u.reseller_tier = :t';   $params[':t'] = $f['tier']; }
        if (!empty($f['search'])) {
            // Emulated prepares are off, so each LIKE needs its own placeholder.
            $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR u.business_name LIKE :q3 OR u.reseller_code LIKE :q4)';
            $like = '%' . $f['search'] . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = (int) max(1, ceil($total / $perPage));
        $page  = max(1, min($page, $pages));

        $stmt = db()->prepare(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.payment_status='paid') AS orders_paid,
                    (SELECT COALESCE(SUM(o.total_amount),0) FROM orders o
                       WHERE o.user_id = u.id AND o.payment_status='paid'
                         AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS spend_90d
             FROM users u WHERE $whereSql
             ORDER BY FIELD(u.reseller_status,'pending','approved','suspended'), u.applied_at DESC, u.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Everything the reseller dashboard shows: spend, tier, distance to the
     * next tier, and the resale value of what they have bought so far.
     */
    public static function dashboard(int $userId): array
    {
        $orders = new OrderRepository();
        $spend  = $orders->resellerSpend($userId, 90);
        $user   = self::find($userId);

        $currentTier = $user['reseller_tier'] ?? 'starter';
        $earnedTier  = Pricing::tierForSpend($spend);

        // Find the next rung up from whichever tier is higher.
        $keys    = array_keys(RESELLER_TIERS);
        $refIdx  = max(array_search($currentTier, $keys, true) ?: 0, array_search($earnedTier, $keys, true) ?: 0);
        $nextKey = $keys[$refIdx + 1] ?? null;

        $stmt = db()->prepare(
            "SELECT COUNT(*) n,
                    COALESCE(SUM(total_amount),0) spent,
                    COALESCE(SUM(est_resale_value),0) resale
             FROM orders WHERE user_id = :u AND payment_status = 'paid'"
        );
        $stmt->execute([':u' => $userId]);
        $lifetime = $stmt->fetch();

        return [
            'user'          => $user,
            'spend_90d'     => $spend,
            'current_tier'  => $currentTier,
            'earned_tier'   => $earnedTier,
            'next_tier'     => $nextKey,
            'next_at'       => $nextKey ? (float) RESELLER_TIERS[$nextKey]['min_spend'] : null,
            'to_next'       => $nextKey ? max(0.0, RESELLER_TIERS[$nextKey]['min_spend'] - $spend) : 0.0,
            'orders_paid'   => (int) $lifetime['n'],
            'lifetime_spend'=> (float) $lifetime['spent'],
            'lifetime_resale'=> (float) $lifetime['resale'],
            'lifetime_profit'=> max(0.0, (float) $lifetime['resale'] - (float) $lifetime['spent']),
        ];
    }

    /**
     * Promotes anyone whose rolling spend has earned a higher tier.
     * Never demotes — a tier once earned is kept, which is what members expect
     * and what stops a quiet month silently cutting someone's margin.
     * Run from scripts/refresh-tiers.php on a nightly cron.
     */
    public static function refreshTiers(): int
    {
        $rows = db()->query(
            "SELECT u.id, u.reseller_tier,
                    COALESCE((SELECT SUM(o.total_amount) FROM orders o
                              WHERE o.user_id = u.id AND o.payment_status='paid'
                                AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)),0) AS spend
             FROM users u WHERE u.reseller_status = 'approved'"
        )->fetchAll();

        $keys    = array_keys(RESELLER_TIERS);
        $changed = 0;
        foreach ($rows as $r) {
            $earned = Pricing::tierForSpend((float) $r['spend']);
            if (array_search($earned, $keys, true) > array_search($r['reseller_tier'], $keys, true)) {
                db_update('users', (int) $r['id'], ['reseller_tier' => $earned]);
                $changed++;
            }
        }
        return $changed;
    }
}
