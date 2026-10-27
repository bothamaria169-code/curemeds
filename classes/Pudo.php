<?php
declare(strict_types=1);

/**
 * The Courier Guy's Pudo locker network (api-pudo.co.za), ported from FairCart.
 *
 * On the enquiry list the customer can pick a Pudo locker on a map instead of
 * courier delivery; the fee is a live locker-to-locker (or door-to-locker) rate
 * for the box size in Settings → Pudo lockers, unless a fixed fee is set there.
 * Once the enquiry is paid, Admin → Enquiries → "Book Pudo shipment" creates
 * the waybill.
 *
 * Auth is the API key from customer.pudo.co.za → Settings → API keys, sent as a
 * Bearer token.
 *   live    https://api-pudo.co.za
 *   sandbox https://sandbox.api-pudo.co.za
 */
final class Pudo
{
    /** Pudo box sizes (cm, kg) — what the parcel must fit in. */
    public const BOXES = [
        'XS' => ['label' => 'Extra small', 'dims' => '60 × 17 × 8 cm',  'kg' => 2],
        'S'  => ['label' => 'Small',       'dims' => '60 × 41 × 8 cm',  'kg' => 5],
        'M'  => ['label' => 'Medium',      'dims' => '60 × 41 × 19 cm', 'kg' => 10],
        'L'  => ['label' => 'Large',       'dims' => '60 × 41 × 41 cm', 'kg' => 15],
        'XL' => ['label' => 'Extra large', 'dims' => '60 × 41 × 69 cm', 'kg' => 20],
    ];

    private const CACHE_FILE = __DIR__ . '/../uploads/cache/pudo-lockers.json';
    private const CACHE_TTL  = 12 * 3600;

    public static function apiKey(): string
    {
        return trim((string) get_setting('pudo_api_key', ''));
    }

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    /** Offered on the enquiry list: switched on and an API key saved (the map needs the locker list). */
    public static function enabled(): bool
    {
        return get_setting('pudo_enabled', '1') === '1' && self::configured();
    }

    public static function baseUrl(): string
    {
        return get_setting('pudo_sandbox', '0') === '1' ? 'https://sandbox.api-pudo.co.za' : 'https://api-pudo.co.za';
    }

    /** Forget the remembered API prefix and the locker list, e.g. after the key or mode changes. */
    public static function reset(): void
    {
        set_setting('pudo_prefix', '');
        @unlink(self::CACHE_FILE);
    }

    /**
     * Calls the API. Pudo has published both /api/v1/… and bare paths over the
     * years, so the first call tries /api/v1 and falls back; whichever answers
     * is remembered.
     */
    public static function call(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        if (!self::configured()) {
            throw new RuntimeException('Add the Pudo API key under Admin → Settings → Pudo lockers first.');
        }
        $known    = (string) get_setting('pudo_prefix', '');
        $prefixes = $known !== '' ? [$known === 'none' ? '' : $known] : ['/api/v1', ''];
        $last     = null;
        foreach ($prefixes as $prefix) {
            $ch = curl_init(self::baseUrl() . $prefix . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . self::apiKey(), 'Accept: application/json', 'Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
            $raw  = (string) curl_exec($ch);
            $last = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'error' => curl_error($ch), 'body' => $raw, 'json' => json_decode($raw, true)];
            curl_close($ch);
            if ($last['status'] !== 404) {
                if ($known === '' && $last['status'] > 0 && $last['status'] < 500) {
                    set_setting('pudo_prefix', $prefix === '' ? 'none' : $prefix);
                }
                break;
            }
        }
        if ($last['error'] !== '') {
            throw new RuntimeException('Could not reach Pudo: ' . $last['error']);
        }
        if ($last['status'] === 401 || $last['status'] === 403) {
            throw new RuntimeException('Pudo refused the API key (HTTP ' . $last['status'] . ').' . (get_setting('pudo_sandbox') === '1' ? ' Sandbox mode is on, which needs a sandbox key.' : ''));
        }
        if ($last['status'] >= 400) {
            $msg = is_array($last['json']) ? ($last['json']['message'] ?? json_encode($last['json']['errors'] ?? $last['json'])) : mb_substr(strip_tags($last['body']), 0, 200);
            throw new RuntimeException('Pudo said (HTTP ' . $last['status'] . '): ' . $msg);
        }
        return is_array($last['json']) ? $last['json'] : [];
    }

    // ── Lockers ──────────────────────────────────────────────────────────

    /**
     * Every locker, slimmed to what the map needs. Cached on disk for 12 hours
     * (the list is a few hundred KB and changes rarely).
     *
     * @return list<array{code:string,name:string,lat:float,lng:float,address:string,town:string,hours:string}>
     */
    public static function lockers(bool $refresh = false): array
    {
        if (!$refresh && is_file(self::CACHE_FILE) && filemtime(self::CACHE_FILE) > time() - self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents(self::CACHE_FILE), true);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }
        try {
            $raw = self::call('GET', '/lockers-data', null, 45);
        } catch (Throwable $e) {
            // A stale list beats no map at all.
            if (is_file(self::CACHE_FILE)) {
                return json_decode((string) file_get_contents(self::CACHE_FILE), true) ?: [];
            }
            throw $e;
        }
        $rows = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
        $out  = [];
        foreach ($rows as $l) {
            if (!is_array($l) || empty($l['code']) || !is_numeric($l['latitude'] ?? null) || !is_numeric($l['longitude'] ?? null)) {
                continue;
            }
            $out[] = [
                'code'    => (string) $l['code'],
                'name'    => trim((string) ($l['name'] ?? $l['code'])),
                'lat'     => round((float) $l['latitude'], 6),
                'lng'     => round((float) $l['longitude'], 6),
                'address' => trim((string) ($l['address'] ?? '')),
                'town'    => trim((string) ($l['place']['town'] ?? '')),
                'hours'   => self::hoursText($l['openinghours'] ?? []),
            ];
        }
        if ($out) {
            @mkdir(dirname(self::CACHE_FILE), 0755, true);
            file_put_contents(self::CACHE_FILE, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $out;
    }

    public static function locker(string $code): ?array
    {
        foreach (self::lockers() as $l) {
            if (strcasecmp($l['code'], $code) === 0) {
                return $l;
            }
        }
        return null;
    }

    private static function hoursText(array $hours): string
    {
        $parts = [];
        foreach ($hours as $h) {
            if (!empty($h['day']) && !empty($h['open_time'])) {
                $parts[] = substr((string) $h['day'], 0, 3) . ' ' . substr((string) $h['open_time'], 0, 5) . '–' . substr((string) ($h['close_time'] ?? ''), 0, 5);
            }
        }
        return implode(', ', array_slice($parts, 0, 7));
    }

    // ── Rates ────────────────────────────────────────────────────────────

    /** Where parcels start: a locker we drop them in, or our street address (door-to-locker). */
    private static function collectionAddress(): array
    {
        if (get_setting('pudo_source_type', 'locker') === 'locker') {
            $code = trim((string) get_setting('pudo_source_locker', ''));
            if ($code === '') {
                throw new RuntimeException('Set the locker you drop parcels at under Admin → Settings → Pudo lockers.');
            }
            return ['terminal_id' => $code];
        }
        $a = fn(string $k) => trim((string) get_setting('pudo_from_' . $k, ''));
        return [
            'type'            => 'business',
            'company'         => (string) (get_setting('company_name', '') ?: SITE_NAME),
            'street_address'  => $a('street'),
            'local_area'      => $a('suburb'),
            'city'            => $a('city'),
            'code'            => $a('postcode'),
            'zone'            => $a('province') ?: 'GP',
            'country'         => 'South Africa',
            'entered_address' => implode(', ', array_filter([$a('street'), $a('suburb'), $a('city'), $a('postcode'), 'South Africa'])),
            'lat'             => $a('lat'),
            'lng'             => $a('lng'),
        ];
    }

    private static function prefix(): string
    {
        return get_setting('pudo_source_type', 'locker') === 'locker' ? 'L2L' : 'D2L';
    }

    /** Live rate (incl. VAT) for our box size to $lockerCode, cached 6 hours. Null if the API cannot quote. */
    public static function liveRate(string $lockerCode): ?array
    {
        $box = (string) get_setting('pudo_box', 'S');
        $key = 'pudo_rate_' . md5(self::baseUrl() . '|' . $lockerCode . '|' . $box . '|' . json_encode(self::collectionAddress()));
        if (isset($_SESSION[$key]) && $_SESSION[$key]['at'] > time() - 6 * 3600) {
            return $_SESSION[$key]['rate'];
        }
        $res   = self::call('POST', '/rates', ['collection_address' => self::collectionAddress(), 'delivery_address' => ['terminal_id' => $lockerCode]]);
        $best  = null;
        $cheap = null;
        foreach ($res['rates'] ?? [] as $r) {
            $code = (string) ($r['service_level']['code'] ?? '');
            $row  = ['rate' => (float) $r['rate'], 'code' => $code, 'name' => (string) ($r['service_level']['name'] ?? $code)];
            if (str_starts_with($code, self::prefix() . $box . ' ') || str_starts_with($code, self::prefix() . $box . '-')) {
                $best = $row;
            }
            if ($cheap === null || $row['rate'] < $cheap['rate']) {
                $cheap = $row;
            }
        }
        $rate = $best ?? $cheap;
        $_SESSION[$key] = ['at' => time(), 'rate' => $rate];
        return $rate;
    }

    /** What the customer pays to have the order sent to $lockerCode. */
    public static function fee(?string $lockerCode, float $subtotal): float
    {
        if (get_setting('pudo_free_threshold', '1') === '1' && $subtotal >= (float) get_setting('free_shipping_threshold', '1000')) {
            return 0.0;
        }
        $fixed = (float) get_setting('pudo_fixed_fee', '0');
        if ($fixed > 0) {
            return $fixed;
        }
        $fallback = (float) get_setting('pudo_fallback_fee', '65');
        if (!$lockerCode || !self::configured()) {
            return $fallback;
        }
        try {
            $rate = self::liveRate($lockerCode);
        } catch (Throwable $e) {
            error_log('Pudo rate failed: ' . $e->getMessage());
            $rate = null;
        }
        if (!$rate || $rate['rate'] <= 0) {
            return $fallback;
        }
        // Whole rands, plus any handling markup set in admin.
        return (float) ceil($rate['rate'] + (float) get_setting('pudo_markup', '0'));
    }

    // ── Shipments ────────────────────────────────────────────────────────

    private static function mobile(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '0')) {
            $d = '27' . substr($d, 1);
        }
        return $d !== '' ? '+' . $d : '';
    }

    /** Books an enquiry's parcel with Pudo and stores the waybill on it. Returns the API reply. */
    public static function book(array $enquiry): array
    {
        if (empty($enquiry['pudo_locker_code'])) {
            throw new RuntimeException('This enquiry is not going to a Pudo locker.');
        }
        if (!empty($enquiry['courier_waybill'])) {
            throw new RuntimeException('Already booked: waybill ' . $enquiry['courier_waybill'] . '.');
        }
        if (empty($enquiry['name']) || empty($enquiry['phone'])) {
            throw new RuntimeException('Add the customer\'s name and cellphone first — Pudo SMSes the locker PIN to that number.');
        }
        $rate = self::liveRate((string) $enquiry['pudo_locker_code']);
        if (!$rate) {
            throw new RuntimeException('Pudo could not quote this locker, so it cannot be booked. Try a different box size.');
        }
        $ref  = 'CureMeds E' . $enquiry['id'];
        $body = [
            'collection_address' => self::collectionAddress(),
            'collection_contact' => [
                'name'          => (string) (get_setting('pudo_sender_name', '') ?: SITE_NAME),
                'email'         => (string) (get_setting('pudo_sender_email', '') ?: get_setting('contact_email', '')),
                'mobile_number' => self::mobile((string) (get_setting('pudo_sender_phone', '') ?: get_setting('contact_phone', ''))),
            ],
            'delivery_address'   => ['terminal_id' => (string) $enquiry['pudo_locker_code']],
            'delivery_contact'   => [
                'name'          => (string) $enquiry['name'],
                'email'         => (string) ($enquiry['email'] ?? ''),
                'mobile_number' => self::mobile((string) $enquiry['phone']),
            ],
            'service_level_code'              => $rate['code'],
            'special_instructions_collection' => $ref,
            'special_instructions_delivery'   => $ref,
            'customer_reference'              => 'E' . $enquiry['id'],
            'collection_min_date'             => gmdate('Y-m-d\TH:i:s.000\Z'),
            'delivery_min_date'               => gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400),
        ];
        $res     = self::call('POST', '/shipments', $body, 45);
        $waybill = (string) ($res['custom_tracking_reference'] ?? $res['short_tracking_reference'] ?? '');
        if ($waybill === '' && empty($res['id'])) {
            throw new RuntimeException('Pudo did not return a waybill: ' . mb_substr(json_encode($res), 0, 200));
        }
        db_update('enquiries', (int) $enquiry['id'], [
            'courier_waybill'  => $waybill ?: null,
            'courier_shipment' => isset($res['id']) ? (string) $res['id'] : null,
            'courier_pin'      => isset($res['pincode']) ? (string) $res['pincode'] : null,
        ]);
        return $res;
    }

    /** Public tracking events for a waybill, newest first. */
    public static function track(string $waybill): array
    {
        $res    = self::call('GET', '/tracking/shipments/public?waybill=' . rawurlencode($waybill));
        $row    = isset($res[0]) ? $res[0] : $res;
        $events = $row['tracking_events'] ?? [];
        usort($events, fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
        return ['status' => (string) ($row['status'] ?? ''), 'events' => $events];
    }
}
