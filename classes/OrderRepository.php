<?php
declare(strict_types=1);

final class OrderRepository
{
    public function generateOrderNumber(): string
    {
        return 'FC' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    /**
     * Creates the order, its lines, its first timeline event and adjusts stock —
     * all inside one transaction, so a failure halfway never leaves an order
     * with half its lines or stock decremented for a sale that did not happen.
     *
     * @param array $items list of ['product_id','variant_id','name','variant_text','sku','qty','unit_price','unit_rrp','image']
     */
    public function create(array $order, array $items): array
    {
        $order['order_number'] ??= $this->generateOrderNumber();

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $orderId = db_insert('orders', $order);

            $repo = new ProductRepository();
            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $variantId = $item['variant_id'] ? (int) $item['variant_id'] : null;
                $qty       = (int) $item['qty'];
                $item['order_id'] = $orderId;
                db_insert('order_items', $item);
                if ($productId) {
                    $repo->recordSale($productId, $qty, $variantId);
                }
            }

            db_insert('order_events', [
                'order_id' => $orderId,
                'status'   => 'pending',
                'note'     => 'Order placed. Awaiting proof of payment.',
            ]);

            $pdo->commit();
            return ['id' => $orderId, 'order_number' => $order['order_number']];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function getById(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT o.*, u.name AS user_name, u.email AS user_email, u.reseller_code,
                    pp.name AS pickup_name, pp.address AS pickup_address, pp.hours AS pickup_hours
             FROM orders o
             LEFT JOIN users u ON u.id = o.user_id
             LEFT JOIN pickup_points pp ON pp.id = o.pickup_point_id
             WHERE o.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getByNumber(string $number): ?array
    {
        $stmt = db()->prepare(
            "SELECT o.*, u.name AS user_name, u.email AS user_email, u.reseller_code,
                    pp.name AS pickup_name, pp.address AS pickup_address, pp.hours AS pickup_hours
             FROM orders o
             LEFT JOIN users u ON u.id = o.user_id
             LEFT JOIN pickup_points pp ON pp.id = o.pickup_point_id
             WHERE o.order_number = :n LIMIT 1"
        );
        $stmt->execute([':n' => $number]);
        return $stmt->fetch() ?: null;
    }

    public function getItems(int $orderId): array
    {
        $stmt = db()->prepare(
            "SELECT oi.*, p.slug AS product_slug
             FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :id ORDER BY oi.id"
        );
        $stmt->execute([':id' => $orderId]);
        return $stmt->fetchAll();
    }

    public function getEvents(int $orderId): array
    {
        $stmt = db()->prepare("SELECT * FROM order_events WHERE order_id = :id ORDER BY created_at, id");
        $stmt->execute([':id' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @param array{user_id?:int,status?:string,type?:string,search?:string,payment_status?:string} $f */
    public function getAll(array $f = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($f['user_id']))        { $where[] = 'o.user_id = :u';        $params[':u']  = (int) $f['user_id']; }
        if (!empty($f['status']))         { $where[] = 'o.status = :st';        $params[':st'] = $f['status']; }
        if (!empty($f['type']))           { $where[] = 'o.order_type = :ty';    $params[':ty'] = $f['type']; }
        if (!empty($f['payment_status'])) { $where[] = 'o.payment_status = :ps';$params[':ps'] = $f['payment_status']; }
        if (!empty($f['search'])) {
            // Emulated prepares are off, so each LIKE needs its own placeholder.
            $where[] = '(o.order_number LIKE :q1 OR o.customer_name LIKE :q2 OR o.customer_email LIKE :q3)';
            $like = '%' . $f['search'] . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = db()->prepare("SELECT COUNT(*) FROM orders o WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, $perPage);
        $pages   = (int) max(1, ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        $stmt = db()->prepare(
            "SELECT o.*, u.name AS user_name, u.email AS user_email,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
             FROM orders o LEFT JOIN users u ON u.id = o.user_id
             WHERE $whereSql ORDER BY o.created_at DESC, o.id DESC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** Status change + timeline entry, so the customer view is always in sync. */
    public function setStatus(int $id, string $status, string $note = ''): bool
    {
        $ok = db_update('orders', $id, ['status' => $status]);
        db_insert('order_events', [
            'order_id' => $id,
            'status'   => $status,
            'note'     => $note !== '' ? $note : (ORDER_STATUSES[$status]['label'] ?? $status),
        ]);
        return $ok;
    }

    public function setPaymentStatus(int $id, string $s): bool
    {
        return db_update('orders', $id, ['payment_status' => $s]);
    }

    public function attachPop(int $id, string $path): bool
    {
        db_insert('order_events', ['order_id' => $id, 'status' => 'pending', 'note' => 'Proof of payment uploaded.']);
        return db_update('orders', $id, ['pop_path' => $path]);
    }

    public function stats(): array
    {
        $pdo = db();
        return [
            'orders_total'   => (int)   $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'orders_pending' => (int)   $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
            'revenue'        => (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status = 'paid'")->fetchColumn(),
            'revenue_month'  => (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status='paid' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn(),
            'customers'      => (int)   $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('customer','reseller')")->fetchColumn(),
            'resellers'      => (int)   $pdo->query("SELECT COUNT(*) FROM users WHERE reseller_status = 'approved'")->fetchColumn(),
            'pending_apps'   => (int)   $pdo->query("SELECT COUNT(*) FROM users WHERE reseller_status = 'pending'")->fetchColumn(),
            'products'       => (int)   $pdo->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn(),
            'low_stock'      => (int)   $pdo->query("SELECT COUNT(*) FROM products WHERE stock_status IN ('low_stock','out_of_stock')")->fetchColumn(),
            'unread_msgs'    => (int)   $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0")->fetchColumn(),
            'pending_reviews'=> (int)   $pdo->query("SELECT COUNT(*) FROM reviews WHERE is_approved = 0")->fetchColumn(),
        ];
    }

    /** Paid revenue by day for the last N days — feeds the admin chart. */
    public function revenueSeries(int $days = 30): array
    {
        $stmt = db()->prepare(
            "SELECT DATE(created_at) d, COALESCE(SUM(total_amount),0) v, COUNT(*) n
             FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
             GROUP BY DATE(created_at) ORDER BY d"
        );
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['d']] = ['value' => (float) $r['v'], 'count' => (int) $r['n']];
        }

        // Fill the gaps so the chart has a continuous x-axis.
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $out[] = ['date' => $day, 'value' => $rows[$day]['value'] ?? 0.0, 'count' => $rows[$day]['count'] ?? 0];
        }
        return $out;
    }

    public function topProducts(int $limit = 5): array
    {
        $stmt = db()->prepare(
            "SELECT oi.name, SUM(oi.qty) q, SUM(oi.qty * oi.unit_price) v
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.payment_status = 'paid'
             GROUP BY oi.name ORDER BY q DESC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Rolling spend for a reseller — drives automatic tier promotion. */
    public function resellerSpend(int $userId, int $days = 90): float
    {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders
             WHERE user_id = :u AND payment_status = 'paid'
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL :d DAY)"
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        return (float) $stmt->fetchColumn();
    }
}
