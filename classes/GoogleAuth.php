<?php
declare(strict_types=1);

/**
 * "Continue with Google" — the OAuth 2.0 authorisation-code flow, no library.
 *
 * The Client ID and secret come from Google Cloud Console (see
 * GOOGLE-LOGIN-SETUP.md) and are saved in Admin → Settings → Google sign-in.
 * Google tells us who the person is (verified email, name, Google ID); we
 * sign in the matching account, link it if the email already has one, or
 * create a customer account.
 */
final class GoogleAuth
{
    private const AUTH     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN    = 'https://oauth2.googleapis.com/token';
    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    public static function enabled(): bool
    {
        return get_setting('google_login_enabled', '1') === '1'
            && trim((string) get_setting('google_client_id', '')) !== ''
            && trim((string) get_setting('google_client_secret', '')) !== '';
    }

    /** Must match an "Authorised redirect URI" in Google Cloud Console exactly. */
    public static function redirectUri(): string
    {
        return SITE_URL . '/modules/auth/google.php';
    }

    public static function loginUrl(string $state): string
    {
        return self::AUTH . '?' . http_build_query([
            'client_id'     => trim((string) get_setting('google_client_id', '')),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Swaps the code Google sent back for the person's verified profile.
     *
     * @return array{sub: string, email: string, name: string}
     */
    public static function profile(string $code): array
    {
        $token = self::post(self::TOKEN, [
            'code'          => $code,
            'client_id'     => trim((string) get_setting('google_client_id', '')),
            'client_secret' => trim((string) get_setting('google_client_secret', '')),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (empty($token['access_token'])) {
            throw new RuntimeException('Google did not accept the sign-in (' . ($token['error_description'] ?? $token['error'] ?? 'no reason given') . ').');
        }
        $ch = curl_init(self::USERINFO);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $me = json_decode((string) curl_exec($ch), true) ?: [];
        curl_close($ch);
        if (empty($me['sub']) || empty($me['email'])) {
            throw new RuntimeException('Google did not share your email address.');
        }
        if (empty($me['email_verified'])) {
            throw new RuntimeException('That Google account\'s email is not verified yet.');
        }
        return ['sub' => (string) $me['sub'], 'email' => strtolower((string) $me['email']), 'name' => trim((string) ($me['name'] ?? ''))];
    }

    /** The account for this Google profile: found by Google ID, linked by email, or newly created. */
    public static function userFor(array $p): array
    {
        $find = function (string $sql, array $args): ?array {
            $s = db()->prepare($sql);
            $s->execute($args);
            return $s->fetch() ?: null;
        };
        $user = $find('SELECT * FROM users WHERE google_id = :g LIMIT 1', [':g' => $p['sub']])
             ?? $find('SELECT * FROM users WHERE email = :e LIMIT 1', [':e' => $p['email']]);
        if ($user) {
            if (empty($user['google_id'])) {
                db_update('users', (int) $user['id'], ['google_id' => $p['sub']]);
            }
        } else {
            $id = db_insert('users', [
                'name'      => mb_substr($p['name'] ?: explode('@', $p['email'])[0], 0, 120),
                'email'     => $p['email'],
                'google_id' => $p['sub'],
                'role'      => 'customer',
            ]);
            $user = $find('SELECT * FROM users WHERE id = :id', [':id' => $id]);
            Mailer::send(ADMIN_EMAIL, 'New customer account — ' . $user['name'],
                email_wrap('<p>' . e($user['name']) . ' (' . e($p['email']) . ') created an account with Google.</p>'));
        }
        db_update('users', (int) $user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
        // Guest orders placed with this email join the account.
        db()->prepare('UPDATE orders SET user_id = :u WHERE user_id IS NULL AND customer_email = :e')
            ->execute([':u' => (int) $user['id'], ':e' => $p['email']]);
        return $user;
    }

    private static function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = (string) curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return $err !== '' ? ['error' => $err] : (json_decode($body, true) ?: ['error' => 'Unexpected reply from Google']);
    }
}
