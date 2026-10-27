<?php
declare(strict_types=1);

/**
 * ImageKit (imagekit.io) hosts copies of our product photos and the reels made
 * in Admin → AI & social media, so Facebook and Instagram fetch media from a
 * fast public CDN, and ImageKit re-encodes reels into the H.264 MP4 Meta wants.
 *
 * Setup: Admin → AI & social media → ImageKit: public key, private key and URL
 * endpoint from imagekit.io → Developer options.
 */
final class ImageKit
{
    private const UPLOAD = 'https://upload.imagekit.io/api/v1/files/upload';
    private const API    = 'https://api.imagekit.io/v1/';

    /** Instagram photo: 4:5 portrait JPEG filling the frame (landscape photos are cropped around the subject, not padded with white). */
    public const PHOTO_TR = 'w-1080,h-1350,fo-auto,f-jpg,q-90';
    /** Reel: plain H.264 MP4, no audio track; wait for the encode instead of redirecting to the original. */
    public const REEL_TR  = 'f-mp4,vc-h264,ac-none,ov-redirect_false';
    /** Your own videos keep their sound (AAC). */
    public const VIDEO_TR = 'f-mp4,vc-h264,ac-aac,ov-redirect_false';

    /** @return array{public_key: string, private_key: string, endpoint: string} */
    public static function config(): array
    {
        return [
            'public_key'  => trim((string) get_setting('imagekit_public_key', '')),
            'private_key' => trim((string) get_setting('imagekit_private_key', '')),
            'endpoint'    => rtrim(trim((string) get_setting('imagekit_url_endpoint', '')), '/'),
        ];
    }

    public static function ready(): bool
    {
        $c = self::config();
        return $c['public_key'] !== '' && $c['private_key'] !== '' && str_starts_with($c['endpoint'], 'https://');
    }

    /** True when $url is a file in our own ImageKit account. */
    public static function owns(string $url): bool
    {
        $endpoint = self::config()['endpoint'];
        return $endpoint !== '' && str_starts_with($url, $endpoint . '/');
    }

    public static function withTransform(string $url, string $tr): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'tr=' . $tr;
    }

    /** Checks the private key by listing one file. Returns '' when it works, else the reason. */
    public static function test(): string
    {
        $reply = self::request('GET', self::API . 'files?limit=1');
        return $reply['code'] === 200 ? '' : ($reply['json']['message'] ?? ('ImageKit said HTTP ' . $reply['code']));
    }

    /**
     * Signed parameters that let the admin's browser upload a reel straight to
     * ImageKit (videos are too big for many PHP upload limits).
     *
     * @return array{token: string, expire: int, signature: string, publicKey: string}
     */
    public static function clientAuth(): array
    {
        $c      = self::config();
        $token  = bin2hex(random_bytes(16));
        $expire = time() + 1800;
        return ['token' => $token, 'expire' => $expire, 'publicKey' => $c['public_key'],
                'signature' => hash_hmac('sha1', $token . $expire, $c['private_key'])];
    }

    /**
     * Public ImageKit address of a photo on this site (assets/… or uploads/…),
     * uploading it the first time and again whenever the file changes.
     */
    public static function hosted(string $sitePath): ?string
    {
        $sitePath = ltrim($sitePath, '/');
        $file     = realpath(__DIR__ . '/../' . $sitePath);
        $root     = realpath(__DIR__ . '/..');
        if (!self::ready() || !$file || !$root || !str_starts_with($file, $root) || !is_file($file)) {
            return null;
        }
        $mtime = (int) filemtime($file);
        $stmt  = db()->prepare('SELECT url FROM imagekit_files WHERE local_path = :p AND local_mtime = :m ORDER BY id DESC LIMIT 1');
        $stmt->execute([':p' => $sitePath, ':m' => $mtime]);
        if ($url = $stmt->fetchColumn()) {
            return (string) $url;
        }
        $up = self::upload($file, basename($file), '/curemeds/photos');
        if (!$up['ok']) {
            error_log('ImageKit upload failed for ' . $sitePath . ': ' . $up['error']);
            return null;
        }
        db_insert('imagekit_files', ['local_path' => $sitePath, 'local_mtime' => $mtime, 'file_id' => $up['file_id'], 'url' => $up['url']]);
        return $up['url'];
    }

    /** @return array{ok: bool, url: string, file_id: string, error: string} */
    public static function upload(string $path, string $name, string $folder): array
    {
        $reply = self::request('POST', self::UPLOAD, [
            'file' => new CURLFile($path), 'fileName' => $name, 'folder' => $folder, 'useUniqueFileName' => 'true',
        ]);
        $url = (string) ($reply['json']['url'] ?? '');
        return ['ok' => $url !== '', 'url' => $url, 'file_id' => (string) ($reply['json']['fileId'] ?? ''),
                'error' => $url !== '' ? '' : (string) ($reply['json']['message'] ?? ('HTTP ' . $reply['code']))];
    }

    /**
     * The reel as an MP4 Meta can fetch. ImageKit encodes it in the background,
     * so we wait (up to $seconds) until the encoded file is actually served.
     *
     * @return array{ok: bool, url: string, error: string}
     */
    public static function reelUrl(string $fileUrl, int $seconds = 120, string $layers = '', string $tr = self::REEL_TR): array
    {
        $path = (string) parse_url($fileUrl, PHP_URL_PATH);
        $base = preg_match('/\.(mp4|mov)$/i', $path) ? $fileUrl : rtrim($fileUrl, '/') . '/ik-video.mp4';
        // Optional photo/text layers first, then the MP4 encode as the last step of the chain.
        $url  = self::withTransform($base, ($layers !== '' ? $layers . ':' : '') . $tr);
        $until = time() + $seconds;
        do {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RANGE => '0-1023', CURLOPT_TIMEOUT => 30,
                                    CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if (in_array($code, [200, 206], true) && str_starts_with($type, 'video/mp4')) {
                return ['ok' => true, 'url' => $url, 'error' => ''];
            }
            if ($code >= 400) {
                return ['ok' => false, 'url' => $url, 'error' => 'ImageKit could not prepare the MP4 (HTTP ' . $code . ').'];
            }
            sleep(4);
        } while (time() < $until);
        return ['ok' => false, 'url' => $url, 'error' => 'ImageKit is still preparing the video. Try posting again in a minute.'];
    }

    /** @return array{code: int, json: array} */
    private static function request(string $method, string $url, ?array $fields = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERPWD        => self::config()['private_key'] . ':',
            CURLOPT_CUSTOMREQUEST  => $method,
        ] + ($fields !== null ? [CURLOPT_POSTFIELDS => $fields] : []));
        $body  = (string) curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'json' => $error !== '' ? ['message' => $error] : (json_decode($body, true) ?: [])];
    }
}
