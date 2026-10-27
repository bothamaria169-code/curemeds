<?php
declare(strict_types=1);

/**
 * Minimal HS256 JWT issuer/verifier used as a signed session-cookie
 * mechanism for the main server-rendered site (not a bearer-token API
 * scheme). No external dependency — this app has no Composer/build step.
 *
 * Reseller status is deliberately NOT carried in the token: it is re-read
 * from the users row on every request, so revoking or upgrading a reseller
 * takes effect on their very next page load rather than in 7 days.
 */
final class JWTHandler
{
    private static ?array $resolvedUser = null;
    private static bool $resolved = false;

    public static function issue(array $user): string
    {
        $header  = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = self::b64(json_encode([
            'sub'  => (int) $user['id'],
            'role' => $user['role'],
            'iat'  => time(),
            'exp'  => time() + JWT_EXPIRY,
        ]));
        $signature = self::b64(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
        return "$header.$payload.$signature";
    }

    public static function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $data = json_decode(self::unb64($payload), true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return null;
        }
        return $data;
    }

    public static function setToken(array $user): void
    {
        $token = self::issue($user);
        $_SESSION['jwt_token'] = $token;
        setcookie(JWT_COOKIE_NAME, $token, [
            'expires'  => time() + JWT_EXPIRY,
            'path'     => '/',
            'httponly' => true,
            // Lax, not Strict: coming back from TikTok or Facebook sign-in is a
            // cross-site redirect, and a Strict cookie would log the admin out.
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    }

    public static function clearToken(): void
    {
        unset($_SESSION['jwt_token']);
        setcookie(JWT_COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/']);
    }

    public static function getCurrentUser(): ?array
    {
        if (self::$resolved) {
            return self::$resolvedUser;
        }
        self::$resolved = true;

        $token = $_SESSION['jwt_token'] ?? $_COOKIE[JWT_COOKIE_NAME] ?? null;
        if (!$token) {
            return self::$resolvedUser = null;
        }

        $data = self::verify($token);
        if (!$data || empty($data['sub'])) {
            return self::$resolvedUser = null;
        }

        $stmt = db()->prepare("SELECT id, name, email, role, phone, avatar, is_active,
                                      reseller_status, reseller_tier, reseller_code, business_name
                               FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $data['sub']]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            return self::$resolvedUser = null;
        }
        $_SESSION['jwt_token'] = $token;
        return self::$resolvedUser = $user;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
