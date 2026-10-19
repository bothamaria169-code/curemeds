<?php
declare(strict_types=1);

/**
 * The one place that decides what a given viewer pays for a given product.
 *
 * CureMeds's catalogue came from supplier "buy low, sell high" sheets, so every
 * product carries three figures rather than one:
 *
 *   retail_price    what a walk-up customer pays
 *   reseller_price  what an approved reseller pays (nullable — see below)
 *   rrp             what we recommend a reseller sells at
 *
 * When reseller_price is NULL we derive it from the viewer's tier discount, so
 * a new product is never accidentally sold at retail to a Gold reseller just
 * because someone forgot a field. Every price shown anywhere on the site —
 * cards, PDP, cart, checkout, invoices, the printed price list — comes through
 * here, so there is exactly one definition of "your price" to reason about.
 */
final class Pricing
{
    /** Tier discount for a user array (or the Starter rate for a guest). */
    public static function tierRate(?array $user): float
    {
        $tier = $user['reseller_tier'] ?? 'starter';
        return RESELLER_TIERS[$tier]['off_retail'] ?? RESELLER_TIERS['starter']['off_retail'];
    }

    /** Is this viewer entitled to reseller pricing right now? */
    /**
     * CureMeds sells retail only, so nobody is a reseller and every page shows
     * retail prices. The reseller code paths are left in place, switched off here.
     */
    public static function isReseller(?array $user): bool
    {
        return false;
    }

    /** The retail price a shopper sees, honouring an active sale. */
    public static function retail(array $product): float
    {
        $sale = $product['sale_price'] ?? null;
        return (!empty($product['is_sale']) && $sale !== null && (float) $sale > 0)
            ? (float) $sale
            : (float) $product['retail_price'];
    }

    /**
     * The wholesale price for a reseller at the given tier.
     *
     * Two numbers compete and the buyer gets the better of them:
     *
     *   1. the product's explicit `reseller_price` — the figure the supplier's
     *      own sheet quotes, which some brands fix and we honour;
     *   2. the tier discount off retail, which is CureMeds's volume reward.
     *
     * Taking the lower of the two is what makes the tiers mean anything. If we
     * simply returned the explicit price whenever it was set, every tier would
     * pay the same on every product and the whole programme would be theatre.
     * Taking the higher would penalise buyers on lines the supplier already
     * prices keenly. The minimum is the only version a reseller can look at
     * and find fair.
     */
    public static function reseller(array $product, ?array $user = null): float
    {
        $fromTier = round((float) $product['retail_price'] * (1 - self::tierRate($user)), 2);

        $explicit = isset($product['reseller_price']) ? (float) $product['reseller_price'] : 0.0;
        if ($explicit > 0) {
            return min($explicit, $fromTier);
        }
        return $fromTier;
    }

    /** What THIS viewer actually pays. */
    public static function effective(array $product, ?array $user = null): float
    {
        return self::isReseller($user) ? self::reseller($product, $user) : self::retail($product);
    }

    /** Recommended resale price — falls back to list price when unset. */
    public static function rrp(array $product): float
    {
        $rrp = $product['rrp'] ?? null;
        return ($rrp !== null && (float) $rrp > 0) ? (float) $rrp : (float) $product['retail_price'];
    }

    /**
     * The full margin picture for one unit. Used by the PDP panel, the product
     * card strip, the price list and the reseller dashboard.
     *
     * @return array{cost:float,rrp:float,profit:float,margin_pct:int,markup_pct:int}
     */
    public static function margin(array $product, ?array $user = null): array
    {
        $cost   = self::reseller($product, $user);
        $rrp    = self::rrp($product);
        $profit = max(0.0, $rrp - $cost);

        return [
            'cost'       => $cost,
            'rrp'        => $rrp,
            'profit'     => $profit,
            // margin  = profit as a share of the SELLING price
            'margin_pct' => $rrp > 0 ? (int) round(($profit / $rrp) * 100) : 0,
            // markup  = profit as a share of what you PAID
            'markup_pct' => $cost > 0 ? (int) round(($profit / $cost) * 100) : 0,
        ];
    }

    /** Discount percentage off list, for the sale badge. */
    public static function salePercent(array $product): int
    {
        $list = (float) $product['retail_price'];
        $sale = (float) ($product['sale_price'] ?? 0);
        if (empty($product['is_sale']) || $sale <= 0 || $list <= 0 || $sale >= $list) {
            return 0;
        }
        return (int) round((1 - $sale / $list) * 100);
    }

    /** Tier a rolling spend figure qualifies for. Used by the nightly re-rank. */
    public static function tierForSpend(float $spend): string
    {
        $best = 'starter';
        foreach (RESELLER_TIERS as $key => $tier) {
            if ($spend >= $tier['min_spend']) {
                $best = $key;
            }
        }
        return $best;
    }
}
