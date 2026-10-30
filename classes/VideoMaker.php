<?php
declare(strict_types=1);

/**
 * MP4 for Facebook and Instagram, made on our own server with ffmpeg — no
 * ImageKit video credits. (Ported from EasyAuctions' App\Support\Video\Ffmpeg.)
 *
 *  - toMp4():       any video (the reel maker's recording, an uploaded clip) →
 *                   H.264 MP4, yuv420p, 30 fps, AAC audio, fast start.
 *  - productReel(): the automatic product reel — four scenes drawn with PHP GD
 *                   (same design the ImageKit layers used), joined with fades.
 *
 * Finished files are served from /social-videos/ on the website for Meta to
 * fetch, under long random names, and deleted after three days.
 *
 * The host is shared hosting with no SSH or terminal, so ffmpeg is installed
 * from the admin (AI & social media → Video converter → Install ffmpeg): the
 * site downloads a pinned static Linux build, checks its SHA-256, unpacks it
 * into bin/ (404'd by .htaccess) and test-runs it. PHP's proc_open must be
 * allowed; the admin says so plainly when it isn't.
 */
final class VideoMaker
{
    public const FOLDER = 'social-videos';

    /** Static ffmpeg 6.1.1 for 64-bit Linux, from github.com/eugeneware/ffmpeg-static (GPL). */
    private const DOWNLOAD_URL  = 'https://github.com/eugeneware/ffmpeg-static/releases/download/b6.1.1/ffmpeg-linux-x64.gz';
    private const DOWNLOAD_SHA  = 'bfe8a8fc511530457b528c48d77b5737527b504a3797a9bc4866aeca69c2dffa';
    private const DOWNLOAD_SIZE = 29354986;

    private const W = 1080;
    private const H = 1920;

    /** Why the last check or conversion failed (shown in the admin). */
    public static string $error = '';

    // ── Finding and installing ffmpeg ────────────────────────────────────

    public static function binDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin';
    }

    /** proc_open is how PHP starts ffmpeg; some hosts switch it off. */
    public static function canRun(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    /** The ffmpeg that works on this server, or null. */
    public static function path(bool $fresh = false): ?string
    {
        static $found = false;
        if ($found !== false && !$fresh) {
            return $found;
        }
        if (!self::canRun()) {
            self::$error = 'This server does not let PHP start programs (proc_open is disabled). Ask the host to allow proc_open for the site\'s PHP version.';
            return $found = null;
        }
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
        if ($home === '' && preg_match('~^(/home\d*/[^/]+)/~', __DIR__, $m)) {
            $home = $m[1];   // cPanel: /home/USER/public_html/…
        }
        $candidates = array_filter([
            trim((string) get_setting('ffmpeg_path', '')),
            self::binDir() . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg'),
            $home ? $home . '/bin/ffmpeg' : null,
            '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', '/opt/ffmpeg/ffmpeg', '/bin/ffmpeg',
            PHP_OS_FAMILY === 'Windows' ? 'C:\\ffmpeg\\bin\\ffmpeg.exe' : null,
        ]);
        foreach (array_unique($candidates) as $bin) {
            if (@is_file($bin) && self::run([$bin, '-hide_banner', '-version'], 20)[0] === 0) {
                return $found = $bin;
            }
        }
        self::$error = 'ffmpeg is not installed yet. Press "Install ffmpeg".';
        return $found = null;
    }

    public static function ready(): bool
    {
        return self::path() !== null;
    }

    /** "ffmpeg version 6.1.1 …" for the admin page. */
    public static function version(): ?string
    {
        $bin = self::path();
        return $bin ? mb_substr(trim((string) strtok(self::run([$bin, '-hide_banner', '-version'], 20)[1], "\n")), 0, 80) : null;
    }

    /**
     * Downloads the pinned static ffmpeg into bin/, checks its SHA-256, unpacks it
     * and test-runs it. Returns '' on success, else the reason.
     */
    public static function install(): string
    {
        if (!self::canRun()) {
            return 'This server does not let PHP start programs (proc_open is disabled), so ffmpeg could not run even once installed. Ask the host to allow proc_open.';
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'The one-click install is for the Linux web server. On this computer, put ffmpeg in C:\\ffmpeg\\bin instead.';
        }
        $dir = self::binDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'Could not create the folder ' . $dir . '.';
        }
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @set_time_limit(300);

        // 1. Download (about 29 MB).
        $gz = $dir . '/ffmpeg-download.gz';
        $fh = @fopen($gz, 'wb');
        if (!$fh) {
            return 'Could not write to ' . $dir . '.';
        }
        $ch = curl_init(self::DOWNLOAD_URL);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                                CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_USERAGENT => 'CureMeds']);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $code !== 200) {
            @unlink($gz);
            return 'The download failed (' . ($err ?: 'HTTP ' . $code) . '). The host may block outgoing downloads; see the manual steps.';
        }

        // 2. Exactly the file we expect, or nothing.
        if (filesize($gz) !== self::DOWNLOAD_SIZE || !hash_equals(self::DOWNLOAD_SHA, (string) hash_file('sha256', $gz))) {
            @unlink($gz);
            return 'The downloaded file did not match the expected checksum, so it was deleted and not used.';
        }

        // 3. Unpack.
        $bin = $dir . '/ffmpeg';
        $in  = gzopen($gz, 'rb');
        $out = @fopen($bin . '.new', 'wb');
        if (!$in || !$out) {
            @unlink($gz);
            return 'Could not unpack ffmpeg.';
        }
        while (!gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);
        @unlink($gz);
        @rename($bin . '.new', $bin);
        @chmod($bin, 0755);

        // 4. It must actually run here.
        [$rc, $log] = self::run([$bin, '-hide_banner', '-version'], 30);
        if ($rc !== 0) {
            return 'ffmpeg was installed but would not start on this server: ' . mb_substr(trim($log) ?: 'exit code ' . $rc, 0, 200);
        }
        set_setting('ffmpeg_path', $bin);
        self::path(true);
        return '';
    }

    // ── Converting ───────────────────────────────────────────────────────

    /** Which converter will make the next MP4: 'ffmpeg', 'imagekit' or null. */
    public static function converter(): ?string
    {
        return self::ready() ? 'ffmpeg' : (ImageKit::ready() ? 'imagekit' : null);
    }

    /**
     * The MP4 Meta downloads, for a video already uploaded (the reel maker and
     * Create a post upload to ImageKit): made with ffmpeg here when it is
     * installed, otherwise ImageKit's own conversion (uses ImageKit credits).
     *
     * @return array{ok: bool, url: string, error: string}
     */
    public static function mp4(string $fileUrl, bool $silent = true, int $imageKitWait = 180): array
    {
        if (self::ready()) {
            try {
                return ['ok' => true, 'url' => self::toMp4($fileUrl, $silent), 'error' => ''];
            } catch (Throwable $e) {
                return ['ok' => false, 'url' => '', 'error' => $e->getMessage()];
            }
        }
        if (!ImageKit::ready()) {
            return ['ok' => false, 'url' => '', 'error' => 'No video converter: install ffmpeg in AI & social media → Video converter.'];
        }
        return ImageKit::reelUrl($fileUrl, $imageKitWait, '', $silent ? ImageKit::REEL_TR : ImageKit::VIDEO_TR);
    }

    /**
     * Any video (local file or URL) → MP4 Meta accepts. Returns the public URL.
     * $silent: replace the sound with a silent track (reels made from photos).
     */
    public static function toMp4(string $input, bool $silent = false): string
    {
        $bin = self::path() ?? throw new RuntimeException(self::$error);
        $tmp = null;
        if (preg_match('~^https?://~i', $input)) {
            $tmp = self::download($input, 'video');
            $input = $tmp;
        }
        try {
            [$file, $url] = self::outFile();
            $audio = $silent
                ? ['-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100', '-map', '0:v:0', '-map', '1:a:0', '-shortest']
                : ['-map', '0:v:0', '-map', '0:a?'];
            self::encode($bin, array_merge(['-i', $input], $audio, [
                // Even dimensions (H.264 needs them), at most 1080 wide, 9:16 or whatever shape it was.
                '-vf', "scale='min(1080,iw)':-2:out_range=tv,format=yuv420p",
            ]), $file, 180);
            return $url;
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }
    }

    /** The automatic product reel as an MP4. Returns the public URL. */
    public static function productReel(array $p): string
    {
        $bin = self::path() ?? throw new RuntimeException(self::$error);
        if (!function_exists('imagettftext')) {
            throw new RuntimeException('PHP GD with FreeType is needed to draw the reel.');
        }
        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm-reel-' . bin2hex(random_bytes(6));
        @mkdir($work, 0700, true);
        $made = [];
        try {
            $scenes = self::scenes($p, $work, $made);
            // Each scene is a still shown for its length; xfade blends them with a short fade.
            $args = [];
            foreach ($scenes as [$png, $secs]) {
                array_push($args, '-loop', '1', '-t', (string) $secs, '-framerate', '30', '-i', $png);
            }
            $fade = 0.5;
            $chain = '';
            $offset = 0.0;
            $last = '[0:v]';
            foreach ($scenes as $i => [, $secs]) {
                if ($i === 0) {
                    $offset = $secs - $fade;
                    continue;
                }
                $label = $i === count($scenes) - 1 ? '[vx]' : "[x$i]";
                $chain .= "{$last}[{$i}:v]xfade=transition=fade:duration={$fade}:offset=" . number_format($offset, 2, '.', '') . "{$label};";
                $last = $label;
                $offset += $secs - $fade;
            }
            $n = count($scenes);
            $chain .= '[vx]format=yuv420p[v]';
            array_push($args, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
                '-filter_complex', $chain, '-map', '[v]', '-map', "{$n}:a", '-shortest');
            [$file, $url] = self::outFile();
            self::encode($bin, $args, $file, 240);
            return $url;
        } finally {
            foreach ($made as $f) {
                @unlink($f);
            }
            @rmdir($work);
        }
    }

    /** Deletes MP4s older than $days (Facebook and Instagram keep their own copies). */
    public static function cleanup(int $days = 3): int
    {
        $n = 0;
        foreach (glob(self::folder() . '/*.mp4') ?: [] as $f) {
            if (filemtime($f) < time() - $days * 86400 && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    // ── Drawing the product reel ─────────────────────────────────────────

    /**
     * The four scenes, as [png path, seconds]. Same design as the ImageKit layers:
     * 1 photo with the name on a navy band · 2 pack shot on a white card ·
     * 3 price and benefits · 4 how to order.
     *
     * @return list<array{0: string, 1: float}>
     */
    private static function scenes(array $p, string $work, array &$made): array
    {
        $font = ['bold' => dirname(__DIR__) . '/assets/fonts/WorkSans-Bold.ttf', 'regular' => dirname(__DIR__) . '/assets/fonts/WorkSans-Regular.ttf'];
        $shot   = self::loadImage((string) $p['featured_image'], $made, $work);
        $header = !empty($p['header_image']) ? self::loadImage((string) $p['header_image'], $made, $work) : null;
        $logo   = self::loadImage('assets/img/logo/curemeds-icon.png', $made, $work);
        if (!$shot) {
            throw new RuntimeException('The product photo could not be opened.');
        }
        $name   = mb_substr((string) $p['name'], 0, 60);
        $points = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', strip_tags((string) ($p['benefits'] ?? ''))) ?: []))), 0, 3);
        $out = [];
        $save = function (GdImage $im, string $n) use ($work, &$made, &$out): string {
            $f = $work . DIRECTORY_SEPARATOR . $n . '.png';
            imagepng($im, $f, 1);
            imagedestroy($im);
            $made[] = $f;
            return $f;
        };

        // 1 · photo (in-store if there is one) with the name on a navy band.
        $im = self::canvas('0A1B3D');
        self::cover($im, $header ?: $shot, 0, 0, self::W, self::H);
        self::rect($im, 0, 1360, self::W, 560, '0A1B3D', 0);
        self::text($im, 'CUREMEDS', $font['bold'], 30, 70, 1440, 940, '1BB3C8');
        self::text($im, $name, $font['bold'], 60, 70, 1540, 940, 'FFFFFF', 3);
        $out[] = [$save($im, '1'), 4.0];

        // 2 · pack shot on a white card.
        $im = self::canvas('E8F0FC');
        self::rect($im, 90, 160, 900, 1200, 'FFFFFF', 0);
        self::contain($im, $shot, 130, 200, 820, 1120);
        $y = self::text($im, $name, $font['bold'], 52, 70, 1480, 940, '0A1B3D', 2);
        if (!empty($p['pack_size'])) {
            self::text($im, (string) $p['pack_size'], $font['regular'], 34, 70, $y + 70, 940, '3A4D72');
        }
        $out[] = [$save($im, '2'), 4.0];

        // 3 · price and benefits on brand blue.
        $im = self::canvas('1557D0');
        self::text($im, 'Only', $font['regular'], 48, 70, 520, 940, 'FFFFFF');
        $price = format_price_short(Pricing::retail($p));
        self::pill($im, $price, $font['bold'], 110, 70, 600, '1BB3C8', '0A1B3D');
        $y = 1060;
        foreach ($points as $pt) {
            $y = self::text($im, '•  ' . mb_substr($pt, 0, 60), $font['regular'], 38, 100, $y, 880, 'FFFFFF', 2) + 90;
        }
        $out[] = [$save($im, '3'), 2.5];

        // 4 · how to order.
        $im = self::canvas('FFFFFF');
        if ($logo) {
            self::contain($im, $logo, 340, 380, 400, 400);
        }
        self::text($im, 'Shop online', $font['bold'], 64, 70, 960, 940, '0A1B3D', 1, 'center');
        self::pill($im, SITE_DOMAIN, $font['bold'], 54, 0, 1060, '1557D0', 'FFFFFF', true);
        self::text($im, 'Courier or Pudo locker, nationwide', $font['regular'], 36, 70, 1360, 940, '3A4D72', 2, 'center');
        $out[] = [$save($im, '4'), 2.5];

        foreach ([$shot, $header, $logo] as $g) {
            if ($g instanceof GdImage) {
                imagedestroy($g);
            }
        }
        return $out;
    }

    private static function canvas(string $hex): GdImage
    {
        $im = imagecreatetruecolor(self::W, self::H);
        imagefill($im, 0, 0, self::colour($im, $hex));
        return $im;
    }

    private static function colour(GdImage $im, string $hex, int $alpha = 0): int
    {
        return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    private static function rect(GdImage $im, int $x, int $y, int $w, int $h, string $hex, int $alpha): void
    {
        imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, self::colour($im, $hex, $alpha));
    }

    /** Fills the box with the image, cropping the overflow (like CSS object-fit: cover). */
    private static function cover(GdImage $dst, GdImage $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $chh = (int) round($h / $scale);
        imagecopyresampled($dst, $src, $x, $y, (int) (($sw - $cw) / 2), (int) (($sh - $chh) / 2), $w, $h, $cw, $chh);
    }

    /** Fits the whole image inside the box, centred (object-fit: contain). */
    private static function contain(GdImage $dst, GdImage $src, int $x, int $y, int $w, int $h): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($w / $sw, $h / $sh);
        $nw = (int) round($sw * $scale);
        $nh = (int) round($sh * $scale);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, $x + (int) (($w - $nw) / 2), $y + (int) (($h - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
    }

    /**
     * Wrapped text; $y is the first baseline. Returns the last baseline used.
     */
    private static function text(GdImage $im, string $text, string $font, float $size, int $x, int $y, int $width, string $hex, int $maxLines = 3, string $align = 'left'): int
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : "$line $w";
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && ($box[2] - $box[0]) > $width) {
                $lines[] = $line;
                $line = $w;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], '.,;: ') . '…';
        }
        $col = self::colour($im, $hex);
        $step = (int) round($size * 1.3);
        foreach ($lines as $i => $l) {
            $box = imagettfbbox($size, 0, $font, $l);
            $left = $align === 'center' ? (int) ((self::W - ($box[2] - $box[0])) / 2) : $x;
            imagettftext($im, $size, 0, $left, $y + $i * $step, $col, $font, $l);
        }
        return $y + max(0, count($lines) - 1) * $step;
    }

    /** Text on a rounded coloured pill (the price, the web address). */
    private static function pill(GdImage $im, string $text, string $font, float $size, int $x, int $y, string $bg, string $fg, bool $centre = false): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $tw = $box[2] - $box[0];
        $th = (int) ($size * 1.05);
        $padX = (int) ($size * 0.55);
        $padY = (int) ($size * 0.35);
        $w = $tw + 2 * $padX;
        $h = $th + 2 * $padY;
        if ($centre) {
            $x = (int) ((self::W - $w) / 2);
        }
        $r = (int) min($h / 2, 60);
        $c = self::colour($im, $bg);
        imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $c);
        imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $c);
        foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $c);
        }
        imagettftext($im, $size, 0, $x + $padX - $box[0], $y + $padY + $th - (int) ($size * 0.12), self::colour($im, $fg), $font, $text);
    }

    /** A site path or URL → GD image (downloaded to $work if remote). */
    private static function loadImage(string $src, array &$made, string $work): ?GdImage
    {
        if ($src === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $src)) {
            try {
                $file = self::download($src, 'img', $work);
            } catch (Throwable) {
                return null;
            }
            $made[] = $file;
        } else {
            $file = dirname(__DIR__) . '/' . ltrim($src, '/');
            if (!is_file($file)) {
                return null;
            }
        }
        $data = @file_get_contents($file);
        $im = $data !== false ? @imagecreatefromstring($data) : false;
        return $im instanceof GdImage ? $im : null;
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    private static function folder(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . self::FOLDER;
    }

    /** @return array{0: string, 1: string} a new random file path and its public URL */
    private static function outFile(): array
    {
        $dir = self::folder();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the folder ' . $dir . ' for the MP4 files.');
        }
        // A long random name: the link is public but cannot be guessed.
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.mp4';
        return [$dir . DIRECTORY_SEPARATOR . $name, SITE_URL . '/' . self::FOLDER . '/' . $name];
    }

    /** Runs the H.264 encode with Meta-friendly settings; throws with ffmpeg's own words on failure. */
    private static function encode(string $bin, array $inputArgs, string $out, int $timeout): void
    {
        [$code, $log] = self::run(array_merge([$bin, '-hide_banner', '-loglevel', 'error', '-y'], $inputArgs, [
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-r', '30', '-color_range', 'tv',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100',
            '-movflags', '+faststart',
            $out,
        ]), $timeout);
        if ($code !== 0 || !is_file($out) || filesize($out) < 10_000) {
            @unlink($out);
            self::$error = 'ffmpeg could not make the MP4: ' . mb_substr(trim($log) ?: 'exit code ' . $code, 0, 300);
            throw new RuntimeException(self::$error);
        }
    }

    /** Downloads a URL to a temp file (max 200 MB). Returns the path. */
    private static function download(string $url, string $prefix, ?string $dir = null): string
    {
        $file = tempnam($dir ?? sys_get_temp_dir(), 'cm-' . $prefix);
        $fh = fopen($file, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => 'CureMeds',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn($c, $dlTotal, $dl) => $dl > 200 * 1024 * 1024 ? 1 : 0]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $code >= 400 || filesize($file) === 0) {
            @unlink($file);
            throw new RuntimeException('Could not download ' . $url . ' (HTTP ' . $code . ').');
        }
        return $file;
    }

    /** @return array{0: int, 1: string} exit code and combined output */
    private static function run(array $cmd, int $timeout): array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [127, 'could not start ' . $cmd[0]];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $code = $status['exitcode'];
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc);
                $code = 124;
                $out .= "\n(stopped after {$timeout} s)";
                break;
            }
            usleep(100_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [(int) $code, mb_substr($out, -4000)];
    }
}
