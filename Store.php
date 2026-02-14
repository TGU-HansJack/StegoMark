<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class StegoMark_Store
{
    private const DATA_DIR = __DIR__ . '/data';
    private const META_FILE = __DIR__ . '/data/meta.json';
    private const POSTS_FILE = __DIR__ . '/data/posts.json';
    private const LOG_FILE = __DIR__ . '/data/logs.json';

    public static function ensureReady(): void
    {
        self::ensureDir(self::DATA_DIR);
        self::ensureFile(self::META_FILE, self::defaultMeta());
        self::ensureFile(self::POSTS_FILE, self::defaultPosts());
        self::ensureFile(self::LOG_FILE, self::defaultLogs());

        $index = self::DATA_DIR . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '', LOCK_EX);
        }

        $htaccess = self::DATA_DIR . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Order allow,deny\nDeny from all\n", LOCK_EX);
        }
    }

    public static function defaultMeta(): array
    {
        return [
            'siteFingerprint' => '',
            'siteSecret' => '',
            'siteCreatedAt' => 0,
            'updatedAt' => time(),
        ];
    }

    public static function defaultPosts(): array
    {
        return [
            'posts' => [],
            'updatedAt' => time(),
        ];
    }

    public static function defaultLogs(): array
    {
        return [
            'access' => [],
            'copy' => [],
            'decode' => [],
            'alert' => [],
            'updatedAt' => time(),
        ];
    }

    public static function ensureSiteIdentity(): array
    {
        self::ensureReady();

        return self::updateJson(
            self::META_FILE,
            self::defaultMeta(),
            function (array $meta): array {
                $changed = false;
                if (empty($meta['siteFingerprint'])) {
                    $meta['siteFingerprint'] = 'SM-' . strtoupper(self::randomHex(8));
                    $changed = true;
                }
                if (empty($meta['siteSecret'])) {
                    $meta['siteSecret'] = self::randomHex(32);
                    $changed = true;
                }
                if (empty($meta['siteCreatedAt'])) {
                    $meta['siteCreatedAt'] = time();
                    $changed = true;
                }
                if ($changed) {
                    $meta['updatedAt'] = time();
                }
                return $meta;
            }
        );
    }

    public static function getSiteSecret(): string
    {
        $meta = self::ensureSiteIdentity();
        return (string) ($meta['siteSecret'] ?? '');
    }

    public static function getSiteFingerprint(): string
    {
        $meta = self::ensureSiteIdentity();
        return (string) ($meta['siteFingerprint'] ?? '');
    }

    public static function ensurePostWatermark(int $cid, int $tokenLen = 12): array
    {
        self::ensureReady();
        if ($cid <= 0) {
            return [
                'wmid' => '',
                'createdAt' => 0,
                'updatedAt' => 0,
            ];
        }

        $tokenLen = self::normalizeTokenLength($tokenLen);
        $key = (string) $cid;
        $secret = self::getSiteSecret();

        $data = self::updateJson(
            self::POSTS_FILE,
            self::defaultPosts(),
            function (array $payload) use ($key, $cid, $tokenLen, $secret): array {
                if (!isset($payload['posts']) || !is_array($payload['posts'])) {
                    $payload['posts'] = [];
                }

                $row = is_array($payload['posts'][$key] ?? null) ? $payload['posts'][$key] : [];
                if (empty($row['wmid'])) {
                    $row['wmid'] = self::buildWatermarkId($cid, $tokenLen, $secret);
                    $row['createdAt'] = time();
                }

                $row['updatedAt'] = time();
                $payload['posts'][$key] = $row;
                $payload['updatedAt'] = time();

                return $payload;
            }
        );

        $row = is_array($data['posts'][$key] ?? null) ? $data['posts'][$key] : [];
        return [
            'wmid' => (string) ($row['wmid'] ?? ''),
            'createdAt' => (int) ($row['createdAt'] ?? 0),
            'updatedAt' => (int) ($row['updatedAt'] ?? 0),
        ];
    }

    public static function rebuildAllPostWatermarks(bool $force = false, int $tokenLen = 12): array
    {
        self::ensureReady();
        $tokenLen = self::normalizeTokenLength($tokenLen);
        $secret = self::getSiteSecret();

        $cids = [];
        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('cid')
                    ->from('table.contents')
                    ->where('type IN ?', ['post', 'page'])
                    ->where('status = ?', 'publish')
            );
            foreach ((array) $rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cid = (int) ($row['cid'] ?? 0);
                if ($cid > 0) {
                    $cids[] = $cid;
                }
            }
        } catch (Throwable $e) {
            return [
                'total' => 0,
                'changed' => 0,
                'kept' => 0,
                'error' => $e->getMessage(),
            ];
        }

        $cids = array_values(array_unique($cids));
        sort($cids);

        $changed = 0;
        $kept = 0;
        $now = time();

        self::updateJson(
            self::POSTS_FILE,
            self::defaultPosts(),
            function (array $payload) use ($cids, $force, $tokenLen, $secret, &$changed, &$kept, $now): array {
                if (!isset($payload['posts']) || !is_array($payload['posts'])) {
                    $payload['posts'] = [];
                }

                foreach ($cids as $cid) {
                    $key = (string) $cid;
                    $row = is_array($payload['posts'][$key] ?? null) ? $payload['posts'][$key] : [];
                    $shouldRenew = $force || empty($row['wmid']);
                    if ($shouldRenew) {
                        $row['wmid'] = self::buildWatermarkId($cid, $tokenLen, $secret);
                        $row['createdAt'] = $now;
                        $changed++;
                    } else {
                        $kept++;
                    }
                    $row['updatedAt'] = $now;
                    $payload['posts'][$key] = $row;
                }

                $payload['updatedAt'] = $now;
                return $payload;
            }
        );

        return [
            'total' => count($cids),
            'changed' => $changed,
            'kept' => $kept,
            'error' => '',
        ];
    }

    public static function appendLog(string $type, array $entry, int $max = 5000): void
    {
        $type = self::normalizeLogType($type);
        $max = max(100, min(20000, $max));
        $entry['createdAt'] = (int) ($entry['createdAt'] ?? time());

        self::updateJson(
            self::LOG_FILE,
            self::defaultLogs(),
            function (array $logs) use ($type, $entry, $max): array {
                if (!isset($logs[$type]) || !is_array($logs[$type])) {
                    $logs[$type] = [];
                }
                $logs[$type][] = $entry;
                if (count($logs[$type]) > $max) {
                    $logs[$type] = array_slice($logs[$type], -$max);
                }
                $logs['updatedAt'] = time();
                return $logs;
            }
        );
    }

    public static function appendCopyAndDetect(array $entry, int $windowSec = 300, int $threshold = 5): array
    {
        self::ensureReady();

        $windowSec = max(30, min(86400, $windowSec));
        $threshold = max(2, min(1000, $threshold));
        $entry['createdAt'] = (int) ($entry['createdAt'] ?? time());

        $result = self::updateJson(
            self::LOG_FILE,
            self::defaultLogs(),
            function (array $logs) use ($entry, $windowSec, $threshold): array {
                if (!isset($logs['copy']) || !is_array($logs['copy'])) {
                    $logs['copy'] = [];
                }
                if (!isset($logs['alert']) || !is_array($logs['alert'])) {
                    $logs['alert'] = [];
                }

                $logs['copy'][] = $entry;
                if (count($logs['copy']) > 8000) {
                    $logs['copy'] = array_slice($logs['copy'], -8000);
                }

                $visitorId = trim((string) ($entry['visitorId'] ?? ''));
                $now = (int) ($entry['createdAt'] ?? time());
                $count = 0;

                if ($visitorId !== '') {
                    foreach (array_reverse($logs['copy']) as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $ts = (int) ($row['createdAt'] ?? 0);
                        if ($ts <= 0) {
                            continue;
                        }
                        if ($now - $ts > $windowSec) {
                            break;
                        }
                        if ((string) ($row['visitorId'] ?? '') === $visitorId) {
                            $count++;
                        }
                    }
                }

                if ($visitorId !== '' && $count >= $threshold) {
                    $logs['alert'][] = [
                        'kind' => 'copy_abnormal',
                        'visitorId' => $visitorId,
                        'cid' => (int) ($entry['cid'] ?? 0),
                        'count' => $count,
                        'windowSec' => $windowSec,
                        'message' => '短时间内检测到高频复制行为',
                        'createdAt' => $now,
                    ];
                    if (count($logs['alert']) > 5000) {
                        $logs['alert'] = array_slice($logs['alert'], -5000);
                    }
                }

                $logs['_runtime'] = [
                    'count' => $count,
                    'abnormal' => ($visitorId !== '' && $count >= $threshold) ? 1 : 0,
                ];
                $logs['updatedAt'] = time();
                return $logs;
            }
        );

        $runtime = is_array($result['_runtime'] ?? null) ? $result['_runtime'] : [];
        return [
            'count' => (int) ($runtime['count'] ?? 0),
            'abnormal' => (int) ($runtime['abnormal'] ?? 0),
        ];
    }

    public static function listLogs(string $type = '', int $limit = 120): array
    {
        self::ensureReady();
        $logs = self::readJson(self::LOG_FILE, self::defaultLogs());
        $limit = max(1, min(2000, $limit));

        if ($type !== '') {
            $type = self::normalizeLogType($type);
            $rows = isset($logs[$type]) && is_array($logs[$type]) ? $logs[$type] : [];
            usort($rows, function ($a, $b) {
                return (int) ($b['createdAt'] ?? 0) <=> (int) ($a['createdAt'] ?? 0);
            });
            return array_slice($rows, 0, $limit);
        }

        $flat = [];
        foreach (['alert', 'copy', 'decode', 'access'] as $k) {
            $rows = isset($logs[$k]) && is_array($logs[$k]) ? $logs[$k] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['_type'] = $k;
                $flat[] = $row;
            }
        }

        usort($flat, function ($a, $b) {
            return (int) ($b['createdAt'] ?? 0) <=> (int) ($a['createdAt'] ?? 0);
        });

        return array_slice($flat, 0, $limit);
    }

    public static function logStats(): array
    {
        $logs = self::readJson(self::LOG_FILE, self::defaultLogs());
        $stats = [
            'access' => 0,
            'copy' => 0,
            'decode' => 0,
            'alert' => 0,
            'lastUpdatedAt' => (int) ($logs['updatedAt'] ?? 0),
        ];

        foreach (['access', 'copy', 'decode', 'alert'] as $k) {
            $stats[$k] = is_array($logs[$k] ?? null) ? count($logs[$k]) : 0;
        }

        return $stats;
    }

    public static function clearLogs(string $type = ''): void
    {
        self::ensureReady();
        $type = trim($type);

        self::updateJson(
            self::LOG_FILE,
            self::defaultLogs(),
            function (array $logs) use ($type): array {
                if ($type === '') {
                    $logs = self::defaultLogs();
                    $logs['updatedAt'] = time();
                    return $logs;
                }

                $key = self::normalizeLogType($type);
                $logs[$key] = [];
                $logs['updatedAt'] = time();
                return $logs;
            }
        );
    }

    public static function getPostWatermarkMap(int $limit = 1000): array
    {
        $data = self::readJson(self::POSTS_FILE, self::defaultPosts());
        $map = isset($data['posts']) && is_array($data['posts']) ? $data['posts'] : [];

        $rows = [];
        foreach ($map as $cid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'cid' => (int) $cid,
                'wmid' => (string) ($row['wmid'] ?? ''),
                'createdAt' => (int) ($row['createdAt'] ?? 0),
                'updatedAt' => (int) ($row['updatedAt'] ?? 0),
            ];
        }

        usort($rows, function ($a, $b) {
            return (int) ($b['updatedAt'] ?? 0) <=> (int) ($a['updatedAt'] ?? 0);
        });

        return array_slice($rows, 0, max(1, min(10000, $limit)));
    }

    public static function buildCertificate(): array
    {
        $meta = self::ensureSiteIdentity();
        $siteUrl = '';
        try {
            $siteUrl = (string) (Helper::options()->siteUrl ?? '');
        } catch (Throwable $e) {
            $siteUrl = '';
        }

        $domain = '';
        if ($siteUrl !== '') {
            $domain = (string) parse_url($siteUrl, PHP_URL_HOST);
        }

        $issuedAt = (int) ($meta['siteCreatedAt'] ?? 0);
        $fingerprint = (string) ($meta['siteFingerprint'] ?? '');
        $certificateId = strtoupper(substr(hash('sha256', $fingerprint . '|' . $domain . '|' . $issuedAt), 0, 24));

        $text = "StegoMark 站点版权证书\n"
            . "Certificate ID: {$certificateId}\n"
            . "Site Fingerprint: {$fingerprint}\n"
            . "Domain: {$domain}\n"
            . "Issued At: " . date('Y-m-d H:i:s', max(1, $issuedAt)) . "\n"
            . "Verified By: StegoMark Integrity Layer";

        return [
            'certificateId' => $certificateId,
            'siteFingerprint' => $fingerprint,
            'domain' => $domain,
            'issuedAt' => $issuedAt,
            'text' => $text,
        ];
    }

    private static function buildWatermarkId(int $cid, int $tokenLen, string $secret): string
    {
        $seed = $cid . '|' . microtime(true) . '|' . self::randomHex(8);
        $hash = strtoupper(hash_hmac('sha256', $seed, $secret));
        return 'WM-' . substr($hash, 0, $tokenLen);
    }

    private static function normalizeTokenLength(int $tokenLen): int
    {
        return max(6, min(40, $tokenLen));
    }

    private static function normalizeLogType(string $type): string
    {
        $type = strtolower(trim($type));
        if (in_array($type, ['access', 'copy', 'decode', 'alert'], true)) {
            return $type;
        }
        return 'access';
    }

    private static function randomHex(int $bytes): string
    {
        $bytes = max(1, min(64, $bytes));
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable $e) {
            return substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, $bytes * 2);
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private static function ensureFile(string $path, array $default): void
    {
        if (is_file($path)) {
            return;
        }
        self::writeJson($path, $default);
    }

    private static function readJson(string $path, array $default): array
    {
        return self::withLock($path, function ($fp) use ($default) {
            rewind($fp);
            $raw = stream_get_contents($fp);
            if (!is_string($raw) || trim($raw) === '') {
                return $default;
            }

            $json = json_decode($raw, true);
            return is_array($json) ? $json : $default;
        }, $default);
    }

    private static function writeJson(string $path, array $data): void
    {
        self::withLock($path, function ($fp) use ($data) {
            $payload = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if ($payload === false) {
                $payload = '{}';
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $payload);
            fflush($fp);
            return true;
        }, false);
    }

    private static function updateJson(string $path, array $default, callable $updater): array
    {
        return self::withLock($path, function ($fp) use ($default, $updater) {
            rewind($fp);
            $raw = stream_get_contents($fp);
            $current = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $default;
            if (!is_array($current)) {
                $current = $default;
            }

            $next = $updater($current);
            if (!is_array($next)) {
                $next = $current;
            }

            // Runtime keys are for caller result and should not persist.
            $persist = $next;
            unset($persist['_runtime']);

            $payload = json_encode(
                $persist,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if ($payload === false) {
                $payload = '{}';
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $payload);
            fflush($fp);

            return $next;
        }, $default);
    }

    private static function withLock(string $path, callable $fn, $fallback)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return $fallback;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return $fallback;
            }
            return $fn($fp);
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }
}

