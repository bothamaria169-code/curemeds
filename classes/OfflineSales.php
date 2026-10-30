<?php
declare(strict_types=1);

/**
 * Offline sales: anything sold outside the website checkout — over the counter,
 * at an event or clinic visit, or agreed on WhatsApp and paid by cash or card.
 *
 * Recording a sale counts the stock down and adds to the product's sold count
 * (so best-sellers stay honest); deleting one puts the stock back. A sale can
 * be linked to a patient, and its products then become their medication with
 * dose reminders. Tables are created by curemeds_migrate() step 5.
 */
final class OfflineSales
{
    public const PAYMENT = [
        'cash'  => 'Cash',
        'card'  => 'Card machine',
        'eft'   => 'EFT / bank deposit',
        'other' => 'Other',
    ];

    public const CHANNELS = [
        'counter'  => 'Over the counter',
        'whatsapp' => 'WhatsApp / phone',
        'event'    => 'Event or clinic visit',
        'delivery' => 'Delivered by us',
        'other'    => 'Other',
    ];

    /**
     * Records a sale. $lines: list of ['product_id' => int, 'qty' => int, 'price' => float].
     *
     * @return array{0: ?int, 1: list<string>} [sale id, errors]
     */
    public static function record(array $in, array $lines, int $staffId): array
    {
        $repo   = new ProductRepository();
        $errors = [];
        $items  = [];
        foreach ($lines as $l) {
            $qty = (int) ($l['qty'] ?? 0);
            if ($qty < 1 || empty($l['product_id'])) {
                continue;
            }
            $p = $repo->getById((int) $l['product_id']);
            if (!$p) {
                continue;
            }
            $price = isset($l['price']) && $l['price'] !== '' ? max(0.0, (float) $l['price']) : Pricing::retail($p);
            $items[] = ['product' => $p, 'qty' => min($qty, 9999), 'price' => round($price, 2)];
        }
        if (!$items) {
            $errors[] = 'Add at least one product with a quantity.';
        }
        $soldOn = (string) ($in['sold_on'] ?? '') ?: date('Y-m-d');
        if (!strtotime($soldOn) || $soldOn > date('Y-m-d')) {
            $errors[] = 'Choose the date of the sale (not in the future).';
        }
        $phone = trim((string) ($in['customer_phone'] ?? ''));
        $phoneN = $phone !== '' ? Health::normalizePhone($phone) : null;
        if ($phone !== '' && !$phoneN) {
            $errors[] = 'That cellphone number doesn\'t look right — leave it empty if you don\'t have it.';
        }
        if ($errors) {
            return [null, $errors];
        }

        $subtotal = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));
        $discount = min($subtotal, max(0.0, (float) ($in['discount'] ?? 0)));
        $payment  = array_key_exists($in['payment_method'] ?? '', self::PAYMENT) ? $in['payment_method'] : 'cash';
        $channel  = array_key_exists($in['channel'] ?? '', self::CHANNELS) ? $in['channel'] : 'counter';

        // The patient: an existing account with this number, if there is one.
        $userId = null;
        if ($phoneN) {
            $s = db()->prepare("SELECT id FROM users WHERE phone = :p AND role <> 'admin'");
            $s->execute([':p' => $phoneN]);
            $userId = ($id = $s->fetchColumn()) ? (int) $id : null;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $saleId = db_insert('offline_sales', [
                'sold_on'        => date('Y-m-d', strtotime($soldOn)),
                'customer_name'  => mb_substr(trim(strip_tags((string) ($in['customer_name'] ?? ''))), 0, 120) ?: null,
                'customer_phone' => $phoneN,
                'user_id'        => $userId,
                'payment_method' => $payment,
                'channel'        => $channel,
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'total'          => $subtotal - $discount,
                'notes'          => mb_substr(trim(strip_tags((string) ($in['notes'] ?? ''))), 0, 1000) ?: null,
                'created_by'     => $staffId,
            ]);
            $line  = $pdo->prepare('INSERT INTO offline_sale_items (sale_id, product_id, name, pack_size, qty, unit_price) VALUES (:s, :p, :n, :k, :q, :u)');
            $stock = $pdo->prepare('UPDATE products SET stock_qty = :qty, stock_status = :st, sold_count = sold_count + :q WHERE id = :p');
            foreach ($items as $i) {
                $p = $i['product'];
                $line->execute([':s' => $saleId, ':p' => $p['id'], ':n' => $p['name'], ':k' => $p['pack_size'], ':q' => $i['qty'], ':u' => $i['price']]);
                $old = (int) $p['stock_qty'];
                $new = max(0, $old - $i['qty']);
                $stock->execute([':qty' => $new, ':st' => self::stockStatus($old, $new, (string) $p['stock_status']), ':q' => $i['qty'], ':p' => $p['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Offline sale not saved: ' . $e->getMessage());
            return [null, ['The sale could not be saved. Please try again.']];
        }

        // Start reminders for a patient who bought something they take.
        if ($userId && !empty($in['add_medication'])) {
            foreach ($items as $i) {
                $p = $i['product'];
                if (($p['category_slug'] ?? '') === 'devices-care') {
                    continue;
                }
                $units = preg_match('/^(\d+)\s*(tablets?|capsules?|softgels?|caps|tabs|sachets?)/i', (string) $p['pack_size'], $m) ? (int) $m[1] * $i['qty'] : null;
                $existing = db()->prepare("SELECT id, units_left FROM patient_medications WHERE user_id = :u AND product_id = :p AND status = 'active' LIMIT 1");
                $existing->execute([':u' => $userId, ':p' => $p['id']]);
                if ($row = $existing->fetch()) {
                    // A refill of something they already take: top the count up instead of adding it twice.
                    if ($units !== null) {
                        db_update('patient_medications', (int) $row['id'], ['units_left' => (int) $row['units_left'] + $units, 'refill_notified_at' => null]);
                    }
                    continue;
                }
                Health::saveMedication($userId, ['product_id' => $p['id'], 'name' => $p['name'], 'dosage' => '1 dose',
                    'dose_times' => '08:00', 'start_date' => date('Y-m-d'), 'units_left' => $units, 'reminders' => 1], null, $staffId);
            }
        }
        return [$saleId, []];
    }

    /**
     * The stock status after a sale. A product whose stock was never counted (0 before
     * the sale) keeps its status, so selling it doesn't mark it sold out by mistake.
     */
    private static function stockStatus(int $old, int $new, string $current): string
    {
        if ($old <= 0 || $current === 'on_backorder') {
            return $current;
        }
        return $new === 0 ? 'out_of_stock' : ($new <= 5 ? 'low_stock' : $current);
    }

    /** Deletes a sale and puts its stock back. */
    public static function delete(int $saleId): bool
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $items = $pdo->prepare('SELECT i.product_id, i.qty, p.stock_qty, p.stock_status FROM offline_sale_items i
                                     JOIN products p ON p.id = i.product_id WHERE i.sale_id = :s');
            $items->execute([':s' => $saleId]);
            $back = $pdo->prepare('UPDATE products SET stock_qty = :qty, stock_status = :st, sold_count = GREATEST(0, CAST(sold_count AS SIGNED) - :q) WHERE id = :p');
            foreach ($items->fetchAll() as $i) {
                $new = (int) $i['stock_qty'] + (int) $i['qty'];
                $st  = in_array($i['stock_status'], ['out_of_stock', 'low_stock'], true) ? ($new > 5 ? 'in_stock' : 'low_stock') : $i['stock_status'];
                $back->execute([':qty' => $new, ':st' => $st, ':q' => (int) $i['qty'], ':p' => $i['product_id']]);
            }
            $pdo->prepare('DELETE FROM offline_sales WHERE id = :id')->execute([':id' => $saleId]);   // items cascade
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Offline sale not deleted: ' . $e->getMessage());
            return false;
        }
    }

    /** @return list<array> sales with their items, newest first */
    public static function list(string $from, string $to, string $q = '', int $limit = 300): array
    {
        $where  = ['s.sold_on BETWEEN :f AND :t'];
        $params = [':f' => $from, ':t' => $to];
        if ($q !== '') {
            $where[] = '(s.customer_name LIKE :q1 OR s.customer_phone LIKE :q2 OR s.notes LIKE :q3
                         OR EXISTS (SELECT 1 FROM offline_sale_items i WHERE i.sale_id = s.id AND i.name LIKE :q4))';
            $params[':q1'] = $params[':q3'] = $params[':q4'] = "%$q%";
            $params[':q2'] = '%' . ltrim((string) preg_replace('/\D/', '', $q), '0') . '%';
        }
        $stmt = db()->prepare('SELECT s.*, u.name AS staff_name FROM offline_sales s LEFT JOIN users u ON u.id = s.created_by
                               WHERE ' . implode(' AND ', $where) . ' ORDER BY s.sold_on DESC, s.id DESC LIMIT ' . (int) $limit);
        $stmt->execute($params);
        $sales = $stmt->fetchAll();
        if (!$sales) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_column($sales, 'id')));
        $byId = [];
        foreach (db()->query("SELECT * FROM offline_sale_items WHERE sale_id IN ($ids) ORDER BY id") as $i) {
            $byId[(int) $i['sale_id']][] = $i;
        }
        foreach ($sales as &$s) {
            $s['items'] = $byId[(int) $s['id']] ?? [];
        }
        return $sales;
    }

    /** @return array{count:int, units:int, total:float, by_payment: array<string,float>} */
    public static function totals(string $from, string $to): array
    {
        $s = db()->prepare('SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM offline_sales WHERE sold_on BETWEEN :f AND :t');
        $s->execute([':f' => $from, ':t' => $to]);
        $row = $s->fetch();
        $u = db()->prepare('SELECT COALESCE(SUM(i.qty),0) FROM offline_sale_items i JOIN offline_sales s ON s.id = i.sale_id WHERE s.sold_on BETWEEN :f AND :t');
        $u->execute([':f' => $from, ':t' => $to]);
        $p = db()->prepare('SELECT payment_method, SUM(total) t FROM offline_sales WHERE sold_on BETWEEN :f AND :t GROUP BY payment_method');
        $p->execute([':f' => $from, ':t' => $to]);
        return ['count' => (int) $row['n'], 'total' => (float) $row['t'], 'units' => (int) $u->fetchColumn(),
                'by_payment' => array_map('floatval', array_column($p->fetchAll(), 't', 'payment_method'))];
    }

    /**
     * Every product with its stock and offline sales in the period: the "manage all
     * products" view.
     *
     * @return list<array>
     */
    public static function byProduct(string $from, string $to): array
    {
        $stmt = db()->prepare("SELECT p.id, p.name, p.pack_size, p.retail_price, p.sale_price, p.is_sale, p.stock_qty, p.stock_status, p.status, p.featured_image,
                                      COALESCE(x.units, 0) units, COALESCE(x.revenue, 0) revenue, x.last_sold
                               FROM products p
                               LEFT JOIN (SELECT i.product_id, SUM(i.qty) units, SUM(i.qty * i.unit_price) revenue, MAX(s.sold_on) last_sold
                                          FROM offline_sale_items i JOIN offline_sales s ON s.id = i.sale_id
                                          WHERE s.sold_on BETWEEN :f AND :t GROUP BY i.product_id) x ON x.product_id = p.id
                               WHERE p.status <> 'archived'
                               ORDER BY units DESC, p.name");
        $stmt->execute([':f' => $from, ':t' => $to]);
        return $stmt->fetchAll();
    }
}
