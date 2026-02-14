<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Plugin.php';

class StegoMark_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
    }

    public function action()
    {
        $do = strtolower(trim((string) $this->request->get('do', 'copy_log')));
        switch ($do) {
            case 'copy_log':
                $this->copyLog();
                return;
            case 'ping':
                $this->ping();
                return;
            case 'decode_api':
                $this->decodeApi();
                return;
            default:
                $this->json(false, 'Unknown action');
                return;
        }
    }

    private function copyLog(): void
    {
        $cfg = StegoMark_Plugin::getConfig();
        if (empty($cfg['copyLogEnabled'])) {
            $this->json(true, 'disabled');
        }
        $this->verifySignedRequest($cfg);

        $visitorId = trim((string) $this->request->get('visitorId', ''));
        if ($visitorId === '') {
            $visitorId = $this->fallbackVisitorId();
        }

        $entry = [
            'cid' => max(0, (int) $this->request->get('cid', 0)),
            'wmid' => trim((string) $this->request->get('watermarkId', '')),
            'visitorId' => $visitorId,
            'selectionLength' => max(0, (int) $this->request->get('selectionLength', 0)),
            'title' => self::cut((string) $this->request->get('title', ''), 300),
            'url' => self::cut((string) $this->request->get('url', ''), 500),
            'referrer' => self::cut((string) ($_SERVER['HTTP_REFERER'] ?? ''), 500),
            'ipHash' => self::ipHash(),
            'uaHash' => self::uaHash(),
            'createdAt' => time(),
        ];

        $flag = StegoMark_Store::appendCopyAndDetect(
            $entry,
            (int) ($cfg['copyAnomalyWindow'] ?? 300),
            (int) ($cfg['copyAnomalyThreshold'] ?? 5)
        );

        $this->json(true, 'ok', [
            'abnormal' => (int) ($flag['abnormal'] ?? 0),
            'count' => (int) ($flag['count'] ?? 0),
        ]);
    }

    private function ping(): void
    {
        $cfg = StegoMark_Plugin::getConfig();
        $this->verifySignedRequest($cfg);

        $visitorId = trim((string) $this->request->get('visitorId', ''));
        if ($visitorId === '') {
            $visitorId = $this->fallbackVisitorId();
        }

        StegoMark_Store::appendLog('access', [
            'cid' => max(0, (int) $this->request->get('cid', 0)),
            'wmid' => trim((string) $this->request->get('watermarkId', '')),
            'visitorId' => $visitorId,
            'url' => self::cut((string) $this->request->get('url', ''), 500),
            'ipHash' => self::ipHash(),
            'uaHash' => self::uaHash(),
            'createdAt' => time(),
            'source' => 'js',
        ]);

        $this->json(true, 'ok');
    }

    private function decodeApi(): void
    {
        $this->mustAdmin();
        $this->protect();

        $text = (string) $this->request->get('text', '');
        $items = StegoMark_Plugin::decodeWatermarksFromText($text);

        StegoMark_Store::appendLog('decode', [
            'count' => count($items),
            'cid' => max(0, (int) $this->request->get('cid', 0)),
            'visitorId' => trim((string) $this->request->get('visitorId', '')),
            'ipHash' => self::ipHash(),
            'uaHash' => self::uaHash(),
            'createdAt' => time(),
        ]);

        $this->json(true, 'ok', ['items' => $items]);
    }

    private function mustAdmin(): void
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->pass('administrator', true)) {
            $this->json(false, 'Forbidden');
        }
    }

    private function protect(): void
    {
        Helper::security()->protect();
    }

    private function fallbackVisitorId(): string
    {
        $raw = (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return substr(hash('sha256', $raw), 0, 24);
    }

    private function verifySignedRequest(array $cfg): void
    {
        if (empty($cfg['actionSignedRequests'])) {
            return;
        }

        $ts = (int) $this->request->get('sm_ts', 0);
        $sig = trim((string) $this->request->get('sm_sig', ''));
        if ($ts <= 0 || $sig === '') {
            $this->json(false, 'Bad signature');
        }

        $ttl = 7200;
        if (abs(time() - $ts) > $ttl) {
            $this->json(false, 'Signature expired');
        }

        $cid = max(0, (int) $this->request->get('cid', 0));
        $wmid = trim((string) $this->request->get('watermarkId', ''));

        $secret = StegoMark_Store::getSiteSecret();
        if ($secret === '') {
            $this->json(false, 'Bad signature');
        }

        $base = $ts . '|' . $cid . '|' . $wmid . '|' . self::ipHash() . '|' . self::uaHash();
        $expect = substr(hash_hmac('sha256', $base, $secret), 0, 32);
        if (!hash_equals($expect, $sig)) {
            $this->json(false, 'Bad signature');
        }
    }

    private static function ipHash(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip === '' ? '' : substr(hash('sha256', $ip), 0, 24);
    }

    private static function uaHash(): string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $ua === '' ? '' : substr(hash('sha256', $ua), 0, 24);
    }

    private static function cut(string $text, int $len): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $len);
        }
        return substr($text, 0, $len);
    }

    private function json(bool $ok, string $message, array $data = []): void
    {
        $this->response->throwJson(array_merge(['ok' => $ok, 'message' => $message], $data));
        exit;
    }
}
