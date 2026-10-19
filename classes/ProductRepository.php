<?php
declare(strict_types=1);

final class ProductRepository
{
    private const SELECT = "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                                   b.name AS brand_name, b.slug AS brand_slug, b.accent AS brand_accent
                            FROM products p
                            LEFT JOIN categories c ON c.id = p.category_id
                            LEFT JOIN brands     b ON b.id = p.brand_id";

    public function getById(int $id): ?array
    {
        $stmt = db()->prepare(self::SELECT . " WHERE p.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getBySlug(string $slug): ?array
    {
        $stmt = db()->prepare(self::SELECT . " WHERE p.slug = :s LIMIT 1");
        $stmt->execute([':s' => $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{category?:string,brand?:string,search?:string,featured?:bool,bestseller?:bool,
     *              sale?:bool,in_stock?:bool,min?:float,max?:float,status?:string,sort?:string,
     *              exclude?:int,ids?:int[]} $f
     */
    public function getAll(array $f = [], int $page = 1, int $perPage = PRODUCTS_PER_PAGE): array
    {
        $where  = ['1=1'];
        $params = [];

        $status = $f['status'] ?? 'published';
        if ($status !== 'all') {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }
        if (!empty($f['category']))   { $where[] = 'c.slug = :cat';   $params[':cat']   = $f['category']; }
        if (!empty($f['brand']))      { $where[] = 'b.slug = :brand'; $params[':brand'] = $f['brand']; }
        if (!empty($f['search'])) {
            // PDO runs with emulated prepares off, so a named placeholder can
            // only appear once in a statement — hence the numbered copies.
            $where[] = '(p.name LIKE :q1 OR p.short_desc LIKE :q2 OR p.tags LIKE :q3 OR b.name LIKE :q4)';
            $like = '%' . $f['search'] . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }
        if (!empty($f['featured']))   { $where[] = 'p.is_featured = 1'; }
        if (!empty($f['bestseller'])) { $where[] = 'p.is_bestseller = 1'; }
        if (!empty($f['sale']))       { $where[] = 'p.is_sale = 1'; }
        if (!empty($f['in_stock']))   { $where[] = "p.stock_status <> 'out_of_stock'"; }
        if (isset($f['min']) && $f['min'] !== '') { $where[] = 'p.retail_price >= :min'; $params[':min'] = (float) $f['min']; }
        if (isset($f['max']) && $f['max'] !== '') { $where[] = 'p.retail_price <= :max'; $params[':max'] = (float) $f['max']; }
        if (!empty($f['exclude']))    { $where[] = 'p.id <> :ex'; $params[':ex'] = (int) $f['exclude']; }
        if (!empty($f['ids'])) {
            $ids = array_map('intval', $f['ids']);
            $where[] = 'p.id IN (' . implode(',', $ids) . ')';
        }

        $orderBy = match ($f['sort'] ?? 'featured') {
            'price_asc'  => 'COALESCE(NULLIF(p.sale_price,0), p.retail_price) ASC',
            'price_desc' => 'COALESCE(NULLIF(p.sale_price,0), p.retail_price) DESC',
            'name'       => 'p.name ASC',
            'newest'     => 'p.created_at DESC, p.id DESC',
            'popular'    => 'p.sold_count DESC, p.view_count DESC',
            // "featured" puts the hand-picked items first, then what actually sells.
            default      => 'p.is_featured DESC, p.is_bestseller DESC, p.sold_count DESC, p.id ASC',
        };

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     LEFT JOIN brands     b ON b.id = p.brand_id
                     WHERE $whereSql";
        $countStmt = db()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, $perPage);
        $pages   = (int) max(1, ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        $stmt = db()->prepare(self::SELECT . " WHERE $whereSql ORDER BY $orderBy LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    public function getFeatured(int $limit = 8): array
    {
        return $this->getAll(['featured' => true, 'sort' => 'featured'], 1, $limit)['items'];
    }

    public function getBestsellers(int $limit = 8): array
    {
        return $this->getAll(['bestseller' => true, 'sort' => 'popular'], 1, $limit)['items'];
    }

    public function getNewest(int $limit = 8): array
    {
        return $this->getAll(['sort' => 'newest'], 1, $limit)['items'];
    }

    /** Same category first; topped up from the same brand so the rail is never short. */
    public function getRelated(array $product, int $limit = 4): array
    {
        $found = $this->getAll([
            'category' => $product['category_slug'] ?? null,
            'exclude'  => (int) $product['id'],
            'sort'     => 'popular',
        ], 1, $limit)['items'];

        if (count($found) < $limit && !empty($product['brand_slug'])) {
            $seen  = array_column($found, 'id');
            $extra = $this->getAll([
                'brand'   => $product['brand_slug'],
                'exclude' => (int) $product['id'],
                'sort'    => 'popular',
            ], 1, $limit)['items'];
            foreach ($extra as $row) {
                if (count($found) >= $limit) break;
                if (!in_array($row['id'], $seen, false)) { $found[] = $row; $seen[] = $row['id']; }
            }
        }
        return array_slice($found, 0, $limit);
    }

    /** Lightweight rows for the header search suggester. */
    public function suggest(string $q, int $limit = 6): array
    {
        $stmt = db()->prepare(
            "SELECT p.id, p.name, p.slug, p.retail_price, p.sale_price, p.is_sale, p.featured_image,
                    p.pack_size, b.name AS brand_name
             FROM products p LEFT JOIN brands b ON b.id = p.brand_id
             WHERE p.status = 'published' AND (p.name LIKE :q1 OR p.tags LIKE :q2 OR b.name LIKE :q3)
             ORDER BY p.is_bestseller DESC, p.sold_count DESC LIMIT :lim"
        );
        $like = '%' . $q . '%';
        $stmt->bindValue(':q1', $like);
        $stmt->bindValue(':q2', $like);
        $stmt->bindValue(':q3', $like);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getVariants(int $productId): array
    {
        $stmt = db()->prepare("SELECT * FROM product_variants WHERE product_id = :p ORDER BY sort_order, id");
        $stmt->execute([':p' => $productId]);
        return $stmt->fetchAll();
    }

    public function getVariantById(int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM product_variants WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getGallery(int $productId): array
    {
        $stmt = db()->prepare("SELECT * FROM product_gallery WHERE product_id = :p ORDER BY sort_order, id");
        $stmt->execute([':p' => $productId]);
        return $stmt->fetchAll();
    }

    public function reviewStats(int $productId): array
    {
        $stmt = db()->prepare("SELECT COUNT(*) c, COALESCE(AVG(rating),0) a FROM reviews WHERE product_id = :p AND is_approved = 1");
        $stmt->execute([':p' => $productId]);
        $r = $stmt->fetch();
        return ['count' => (int) $r['c'], 'average' => round((float) $r['a'], 1)];
    }

    public function approvedReviews(int $productId): array
    {
        $stmt = db()->prepare(
            "SELECT r.*, u.name AS user_name, u.reseller_status
             FROM reviews r JOIN users u ON u.id = r.user_id
             WHERE r.product_id = :p AND r.is_approved = 1 ORDER BY r.created_at DESC"
        );
        $stmt->execute([':p' => $productId]);
        return $stmt->fetchAll();
    }

    public function bumpView(int $id): void
    {
        db()->prepare("UPDATE products SET view_count = view_count + 1 WHERE id = :id")->execute([':id' => $id]);
    }

    public function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = slugify($name);
        $slug = $base;
        $n    = 1;
        while (true) {
            $sql  = 'SELECT id FROM products WHERE slug = :s' . ($excludeId ? ' AND id <> :id' : '');
            $stmt = db()->prepare($sql);
            $p    = [':s' => $slug];
            if ($excludeId) { $p[':id'] = $excludeId; }
            $stmt->execute($p);
            if (!$stmt->fetch()) { return $slug; }
            $slug = $base . '-' . (++$n);
        }
    }

    public function create(array $data): int   { return db_insert('products', $data); }
    public function update(int $id, array $d): bool { return db_update('products', $id, $d); }
    public function delete(int $id): bool
    {
        return db()->prepare('DELETE FROM products WHERE id = :id')->execute([':id' => $id]);
    }

    public function addVariant(array $d): int { return db_insert('product_variants', $d); }
    public function deleteVariant(int $id): bool
    {
        return db()->prepare('DELETE FROM product_variants WHERE id = :id')->execute([':id' => $id]);
    }
    public function addGalleryImage(int $productId, string $url, ?string $caption = null, int $sort = 0): int
    {
        return db_insert('product_gallery', ['product_id' => $productId, 'image' => $url, 'caption' => $caption, 'sort_order' => $sort]);
    }
    public function deleteGalleryImage(int $id): bool
    {
        return db()->prepare('DELETE FROM product_gallery WHERE id = :id')->execute([':id' => $id]);
    }

    /** Decrement stock and count a sale. Called once an order is created. */
    public function recordSale(int $productId, int $qty, ?int $variantId = null): void
    {
        db()->prepare("UPDATE products
                       SET stock_qty = GREATEST(0, stock_qty - :q),
                           sold_count = sold_count + :q2,
                           stock_status = CASE
                             WHEN GREATEST(0, stock_qty - :q3) = 0 THEN 'out_of_stock'
                             WHEN GREATEST(0, stock_qty - :q4) <= 10 THEN 'low_stock'
                             ELSE stock_status END
                       WHERE id = :id")
            ->execute([':q' => $qty, ':q2' => $qty, ':q3' => $qty, ':q4' => $qty, ':id' => $productId]);

        if ($variantId) {
            db()->prepare("UPDATE product_variants SET stock_qty = GREATEST(0, stock_qty - :q) WHERE id = :id")
                ->execute([':q' => $qty, ':id' => $variantId]);
        }
    }
}
