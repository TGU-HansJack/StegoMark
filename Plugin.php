<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Store.php';

/**
 * 为 Typecho 提供隐形水印、访客指纹与泄露溯源的内容版权保护插件
 * @package StegoMark
 * @author 寒士杰克
 * @version 1.0.3
 * @link https://www.hansjack.com
 */
class StegoMark_Plugin implements Typecho_Plugin_Interface
{
    private const VERSION = '1.0.3';

    private const ZW_ZERO = "\xE2\x80\x8B";
    private const ZW_ONE = "\xE2\x80\x8C";
    private const ZW_START = "\xE2\x80\x8D\xE2\x81\xA2";
    private const ZW_END = "\xE2\x81\xA3\xE2\x81\xA0";

    private static $footerRendered = false;
    private static $runtimeContextByCid = [];

    public static function activate()
    {
        StegoMark_Store::ensureReady();
        StegoMark_Store::ensureSiteIdentity();

        Helper::addAction('stegomark', 'StegoMark_Action');
        try {
            Helper::addPanel(3, 'StegoMark/manage.php', _t('StegoMark'), _t('StegoMark'), 'administrator');
        } catch (Exception $e) {
            Helper::addPanel(3, 'StegoMark/manage.php', _t('StegoMark'), _t('StegoMark'), 'administrator');
        }

        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = ['StegoMark_Plugin', 'filterContent'];
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = ['StegoMark_Plugin', 'filterContent'];
        Typecho_Plugin::factory('Widget_Archive')->header = ['StegoMark_Plugin', 'renderHeader'];
        Typecho_Plugin::factory('Widget_Archive')->footer = ['StegoMark_Plugin', 'renderFooter'];

        return _t('StegoMark 已启用');
    }

    public static function deactivate()
    {
        Helper::removeAction('stegomark');
        Helper::removePanel(3, 'StegoMark/manage.php');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        echo '<style>
            .stegomark-config-note{margin:12px 0 18px;padding:12px 14px;border:1px solid #dbe7ff;border-radius:10px;background:linear-gradient(120deg,#f7fbff,#eef5ff)}
            .stegomark-config-note strong{display:block;margin-bottom:4px;color:#1d4ed8}
            .stegomark-config-note code{background:#eef2ff;padding:1px 6px;border-radius:4px}
        </style>';
        echo '<div class="stegomark-config-note"><strong>StegoMark 配置说明</strong><div>支持字符水印 / 注释水印 / 视觉层组合。管理页提供解码工具、日志中心和批量重建。</div><div style="margin-top:6px;">功能清单：<code>'
            . htmlspecialchars(__DIR__ . '/FEATURES.md', ENT_QUOTES, 'UTF-8')
            . '</code></div></div>';

        $siteEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('siteEnabled', ['1' => _t('全站启用 StegoMark')], ['1'], _t('总开关'));
        $form->addInput($siteEnabled);

        $postOnly = new Typecho_Widget_Helper_Form_Element_Checkbox('postOnly', ['1' => _t('仅对文章页（post 单页）启用')], ['1'], _t('作用范围'));
        $form->addInput($postOnly);

        $strength = new Typecho_Widget_Helper_Form_Element_Select('watermarkStrength', ['weak' => _t('弱'), 'medium' => _t('中'), 'strong' => _t('强')], 'medium', _t('水印强度'));
        $form->addInput($strength);

        $algorithms = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'algorithms',
            ['char' => _t('字符水印（零宽字符）'), 'comment' => _t('注释水印（HTML 注释）'), 'css' => _t('CSS/视觉水印层')],
            ['char', 'comment', 'css'],
            _t('水印算法组合')
        );
        $form->addInput($algorithms);

        $distribution = new Typecho_Widget_Helper_Form_Element_Select('distributionMode', ['paragraph' => _t('段落随机'), 'sentence' => _t('句子优先'), 'random' => _t('完全随机')], 'paragraph', _t('字符水印分布策略'));
        $form->addInput($distribution);

        $tokenLen = new Typecho_Widget_Helper_Form_Element_Text('watermarkTokenLength', null, '12', _t('水印ID长度'), _t('6-40，影响文章 Watermark ID 长度。'));
        $form->addInput($tokenLen);

        $insertRatio = new Typecho_Widget_Helper_Form_Element_Text('insertRatio', null, '0.08', _t('插入比例'), _t('0.01-0.8，越高表示单篇插入次数越多。'));
        $form->addInput($insertRatio);

        $copyAppendEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('copyAppendEnabled', ['1' => _t('复制时自动追加版权信息')], ['1'], _t('复制追加'));
        $form->addInput($copyAppendEnabled);

        $copyAppendTemplate = new Typecho_Widget_Helper_Form_Element_Textarea(
            'copyAppendTemplate',
            null,
            "来源：{url}\n版权声明：本文为 {site} 原创内容，转载请注明出处。\n水印ID：{watermark_id}",
            _t('复制追加模板'),
            _t('可用变量：{title} {url} {site} {user} {watermark_id} {site_fingerprint} {visitor_id} {time}')
        );
        $form->addInput($copyAppendTemplate);

        $copyLogEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('copyLogEnabled', ['1' => _t('记录复制行为日志')], ['1'], _t('复制日志'));
        $form->addInput($copyLogEnabled);

        $copyAnomalyThreshold = new Typecho_Widget_Helper_Form_Element_Text('copyAnomalyThreshold', null, '5', _t('异常复制阈值'), _t('窗口期内达到阈值会标记异常。'));
        $form->addInput($copyAnomalyThreshold);

        $copyAnomalyWindow = new Typecho_Widget_Helper_Form_Element_Text('copyAnomalyWindow', null, '300', _t('异常检测窗口(秒)'));
        $form->addInput($copyAnomalyWindow);

        $visitorFingerprintEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('visitorFingerprintEnabled', ['1' => _t('启用访客指纹水印')], ['1'], _t('访客溯源'));
        $form->addInput($visitorFingerprintEnabled);

        $visitorBucketSeconds = new Typecho_Widget_Helper_Form_Element_Text('visitorBucketSeconds', null, '1800', _t('访客时间桶(秒)'), _t('用于访客ID加盐时间维度。'));
        $form->addInput($visitorBucketSeconds);

        $dynamicInjectEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('dynamicInjectEnabled', ['1' => _t('启用前端动态水印注入')], ['1'], _t('动态注入'));
        $form->addInput($dynamicInjectEnabled);

        $crawlerNoWatermark = new Typecho_Widget_Helper_Form_Element_Checkbox('crawlerNoWatermark', ['1' => _t('搜索引擎无水印模式')], ['1'], _t('SEO兼容'));
        $form->addInput($crawlerNoWatermark);

        $crawlerWhitelist = new Typecho_Widget_Helper_Form_Element_Textarea(
            'crawlerWhitelist',
            null,
            "googlebot\nbingbot\nbaiduspider\nyandexbot\nduckduckbot\nsogou spider\n360spider",
            _t('爬虫白名单'),
            _t('一行一个关键词，命中后输出无水印 HTML。')
        );
        $form->addInput($crawlerWhitelist);

        $apiFeedNoWatermark = new Typecho_Widget_Helper_Form_Element_Checkbox('apiFeedNoWatermark', ['1' => _t('API / Feed 无水印模式')], ['1'], _t('接口兼容'));
        $form->addInput($apiFeedNoWatermark);

        $visualBgEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('visualBgEnabled', ['1' => _t('截图层：超淡背景水印')], ['1'], _t('视觉微水印'));
        $form->addInput($visualBgEnabled);

        $visualNoiseEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('visualNoiseEnabled', ['1' => _t('截图层：随机噪点纹理')], [], _t('视觉微水印'));
        $form->addInput($visualNoiseEnabled);

        $visualCanvasEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox('visualCanvasEnabled', ['1' => _t('截图层：Canvas 访客图案')], [], _t('视觉微水印'));
        $form->addInput($visualCanvasEnabled);

        $visualBgOpacity = new Typecho_Widget_Helper_Form_Element_Text(
            'visualBgOpacity',
            null,
            '0.020',
            _t('背景水印透明度'),
            _t('0-0.2，建议 0.01-0.05。多层同时启用时会自动按层数均摊。')
        );
        $form->addInput($visualBgOpacity);

        $visualNoiseOpacity = new Typecho_Widget_Helper_Form_Element_Text(
            'visualNoiseOpacity',
            null,
            '0.015',
            _t('噪点纹理透明度'),
            _t('0-0.2，建议 0.005-0.03。多层同时启用时会自动按层数均摊。')
        );
        $form->addInput($visualNoiseOpacity);

        $visualCanvasOpacity = new Typecho_Widget_Helper_Form_Element_Text(
            'visualCanvasOpacity',
            null,
            '0.018',
            _t('Canvas 层透明度'),
            _t('0-0.2。Canvas 单独启用时会以微字/图案承载溯源信息。')
        );
        $form->addInput($visualCanvasOpacity);

        $blockIfNoFingerprint = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'blockIfNoFingerprint',
            ['1' => _t('无法生成指纹则遮罩封禁（防屏蔽）')],
            [],
            _t('访问控制'),
            _t('启用后，文章页会默认被遮罩；需前端 JS 正常运行并生成指纹后才解除。可能影响无 JS 用户体验。')
        );
        $form->addInput($blockIfNoFingerprint);

        $blockMaskText = new Typecho_Widget_Helper_Form_Element_Textarea(
            'blockMaskText',
            null,
            "正在验证浏览器环境…\n如果你使用了屏蔽插件，请将本站加入白名单后刷新页面。",
            _t('遮罩提示文本'),
            _t('支持换行。用于封禁遮罩的提示内容。')
        );
        $form->addInput($blockMaskText);

        $contentSelector = new Typecho_Widget_Helper_Form_Element_Text('contentSelector', null, '', _t('正文选择器'), _t('可留空自动匹配。示例：.post-content / #article / [data-post-content-body]'));
        $form->addInput($contentSelector);

        $customCss = new Typecho_Widget_Helper_Form_Element_Textarea('customCss', null, '', _t('自定义前端 CSS'), _t('会注入到前台页面，可用于自定义任意元素样式。'));
        $form->addInput($customCss);

        $customLayoutHtml = new Typecho_Widget_Helper_Form_Element_Textarea(
            'customLayoutHtml',
            null,
            '',
            _t('自定义前端布局 HTML'),
            _t('可插入任意布局片段，支持变量：{title} {url} {site} {user} {watermark_id} {site_fingerprint} {visitor_id}')
        );
        $form->addInput($customLayoutHtml);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function getConfig(): array
    {
        $defaults = [
            'siteEnabled' => true,
            'postOnly' => true,
            'watermarkStrength' => 'medium',
            'algorithms' => ['char', 'comment', 'css'],
            'distributionMode' => 'paragraph',
            'watermarkTokenLength' => 12,
            'insertRatio' => 0.08,
            'copyAppendEnabled' => true,
            'copyAppendTemplate' => "来源：{url}\n版权声明：本文为 {site} 原创内容，转载请注明出处。\n水印ID：{watermark_id}",
            'copyLogEnabled' => true,
            'copyAnomalyThreshold' => 5,
            'copyAnomalyWindow' => 300,
            'visitorFingerprintEnabled' => true,
            'visitorBucketSeconds' => 1800,
            'dynamicInjectEnabled' => true,
            'crawlerNoWatermark' => true,
            'crawlerWhitelist' => "googlebot\nbingbot\nbaiduspider\nyandexbot\nduckduckbot\nsogou spider\n360spider",
            'apiFeedNoWatermark' => true,
            'visualBgEnabled' => true,
            'visualNoiseEnabled' => false,
            'visualCanvasEnabled' => false,
            'visualBgOpacity' => 0.020,
            'visualNoiseOpacity' => 0.015,
            'visualCanvasOpacity' => 0.018,
            'blockIfNoFingerprint' => false,
            'blockMaskText' => "正在验证浏览器环境…\n如果你使用了屏蔽插件，请将本站加入白名单后刷新页面。",
            'contentSelector' => '',
            'customCss' => '',
            'customLayoutHtml' => '',
        ];

        try {
            $raw = Helper::options()->plugin('StegoMark')->toArray();
            if (!is_array($raw)) {
                $raw = [];
            }
        } catch (Throwable $e) {
            $raw = [];
        }

        $cfg = array_merge($defaults, $raw);
        $cfg['siteEnabled'] = self::checkboxEnabled($cfg['siteEnabled'] ?? null);
        $cfg['postOnly'] = self::checkboxEnabled($cfg['postOnly'] ?? null);
        $cfg['copyAppendEnabled'] = self::checkboxEnabled($cfg['copyAppendEnabled'] ?? null);
        $cfg['copyLogEnabled'] = self::checkboxEnabled($cfg['copyLogEnabled'] ?? null);
        $cfg['visitorFingerprintEnabled'] = self::checkboxEnabled($cfg['visitorFingerprintEnabled'] ?? null);
        $cfg['dynamicInjectEnabled'] = self::checkboxEnabled($cfg['dynamicInjectEnabled'] ?? null);
        $cfg['crawlerNoWatermark'] = self::checkboxEnabled($cfg['crawlerNoWatermark'] ?? null);
        $cfg['apiFeedNoWatermark'] = self::checkboxEnabled($cfg['apiFeedNoWatermark'] ?? null);
        $cfg['visualBgEnabled'] = self::checkboxEnabled($cfg['visualBgEnabled'] ?? null);
        $cfg['visualNoiseEnabled'] = self::checkboxEnabled($cfg['visualNoiseEnabled'] ?? null);
        $cfg['visualCanvasEnabled'] = self::checkboxEnabled($cfg['visualCanvasEnabled'] ?? null);
        $cfg['blockIfNoFingerprint'] = self::checkboxEnabled($cfg['blockIfNoFingerprint'] ?? null);

        $strength = strtolower(trim((string) ($cfg['watermarkStrength'] ?? 'medium')));
        $cfg['watermarkStrength'] = in_array($strength, ['weak', 'medium', 'strong'], true) ? $strength : 'medium';

        $dist = strtolower(trim((string) ($cfg['distributionMode'] ?? 'paragraph')));
        $cfg['distributionMode'] = in_array($dist, ['paragraph', 'sentence', 'random'], true) ? $dist : 'paragraph';

        $alg = $cfg['algorithms'] ?? [];
        if (!is_array($alg)) {
            $alg = $alg === '' ? [] : [(string) $alg];
        }
        $alg = array_values(array_intersect(array_unique(array_map('strtolower', $alg)), ['char', 'comment', 'css']));
        if (empty($alg)) {
            $alg = ['char'];
        }
        $cfg['algorithms'] = $alg;

        $cfg['watermarkTokenLength'] = max(6, min(40, (int) ($cfg['watermarkTokenLength'] ?? 12)));
        $cfg['insertRatio'] = max(0.01, min(0.8, (float) ($cfg['insertRatio'] ?? 0.08)));
        $cfg['copyAnomalyThreshold'] = max(2, min(1000, (int) ($cfg['copyAnomalyThreshold'] ?? 5)));
        $cfg['copyAnomalyWindow'] = max(30, min(86400, (int) ($cfg['copyAnomalyWindow'] ?? 300)));
        $cfg['visitorBucketSeconds'] = max(60, min(86400, (int) ($cfg['visitorBucketSeconds'] ?? 1800)));
        $cfg['visualBgOpacity'] = max(0.0, min(0.2, (float) ($cfg['visualBgOpacity'] ?? 0.020)));
        $cfg['visualNoiseOpacity'] = max(0.0, min(0.2, (float) ($cfg['visualNoiseOpacity'] ?? 0.015)));
        $cfg['visualCanvasOpacity'] = max(0.0, min(0.2, (float) ($cfg['visualCanvasOpacity'] ?? 0.018)));
        $cfg['copyAppendTemplate'] = self::cut((string) ($cfg['copyAppendTemplate'] ?? ''), 3000);
        $cfg['blockMaskText'] = self::cut((string) ($cfg['blockMaskText'] ?? ''), 2000);
        $cfg['crawlerWhitelist'] = self::cut((string) ($cfg['crawlerWhitelist'] ?? ''), 3000);
        $cfg['contentSelector'] = self::cut((string) ($cfg['contentSelector'] ?? ''), 500);
        $cfg['customCss'] = self::cut((string) ($cfg['customCss'] ?? ''), 12000);
        $cfg['customLayoutHtml'] = self::cut((string) ($cfg['customLayoutHtml'] ?? ''), 12000);

        return $cfg;
    }

    public static function filterContent($text, $widget, $lastResult)
    {
        $html = empty($lastResult) ? $text : $lastResult;
        if (!is_string($html) || $html === '' || self::isAdminRequest()) {
            return $html;
        }

        $cfg = self::getConfig();
        if (empty($cfg['siteEnabled'])) {
            return $html;
        }

        $ctx = self::resolveArchiveContext($widget, $cfg);
        if (!$ctx || self::shouldBypassForCrawler($cfg) || self::shouldBypassForApiFeed($cfg)) {
            return $html;
        }
        if (strpos($html, '<!--stegomark:block:') !== false) {
            return $html;
        }

        $payload = self::buildPayload($ctx);
        $token = self::payloadToToken($payload);
        $packet = self::tokenToZeroWidth($token);
        $modified = $html;

        if (in_array('char', $cfg['algorithms'], true)) {
            $modified = self::injectCharacterWatermark($modified, $packet, $cfg);
        }
        if (in_array('comment', $cfg['algorithms'], true)) {
            $modified = self::injectCommentWatermark($modified, $token, $cfg);
        }

        $modified .= '<!--stegomark:block:' . substr(hash('sha1', $token), 0, 14) . '-->';

        $cid = (int) ($ctx['cid'] ?? 0);
        if ($cid > 0) {
            self::$runtimeContextByCid[$cid] = [
                'context' => $ctx,
                'payload' => $payload,
                'token' => $token,
                'packet' => $packet,
            ];
        }

        return $modified;
    }

    public static function renderHeader($archive): void
    {
        if (self::isAdminRequest()) {
            return;
        }

        $cfg = self::getConfig();
        if (empty($cfg['siteEnabled']) || empty($cfg['blockIfNoFingerprint'])) {
            return;
        }
        if (self::shouldBypassForCrawler($cfg) || self::shouldBypassForApiFeed($cfg)) {
            return;
        }

        $ctx = self::resolveArchiveContext($archive, $cfg);
        if (!$ctx) {
            return;
        }

        $msg = trim((string) ($cfg['blockMaskText'] ?? ''));
        if ($msg === '') {
            $msg = "正在验证浏览器环境…\n如果你使用了屏蔽插件，请将本站加入白名单后刷新页面。";
        }

        echo '<style id="stegomark-block-style">'
            . 'html:not(.sm-unlocked){overflow:hidden;}'
            . 'html:not(.sm-unlocked) body{overflow:hidden;--sm-block-msg:' . self::cssStringLiteral($msg) . ';}'
            . 'html.sm-unlocked{overflow:auto;}'
            . 'html.sm-unlocked body{overflow:auto;}'
            . 'html:not(.sm-unlocked) body::before{content:"";position:fixed;inset:0;z-index:2147482600;'
            . 'background:rgba(248,250,255,.92);backdrop-filter:blur(10px) saturate(1.15);pointer-events:auto;}'
            . 'html:not(.sm-unlocked) body::after{content:var(--sm-block-msg);white-space:pre-wrap;position:fixed;'
            . 'left:50%;top:50%;transform:translate(-50%,-50%);z-index:2147482601;max-width:620px;'
            . 'width:calc(100% - 40px);padding:18px 18px;border-radius:14px;background:#fff;'
            . 'border:1px solid rgba(15,23,42,.12);box-shadow:0 18px 60px rgba(15,23,42,.18);'
            . 'color:#0f172a;font:14px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
            . 'html:not(.sm-unlocked) body::after{box-sizing:border-box;}'
            . '</style>';
    }

    public static function renderFooter($archive): void
    {
        if (self::isAdminRequest() || self::$footerRendered) {
            return;
        }

        $cfg = self::getConfig();
        if (empty($cfg['siteEnabled']) || self::shouldBypassForCrawler($cfg) || self::shouldBypassForApiFeed($cfg)) {
            return;
        }

        $ctx = self::resolveArchiveContext($archive, $cfg);
        if (!$ctx) {
            return;
        }

        $cid = (int) ($ctx['cid'] ?? 0);
        $runtime = is_array(self::$runtimeContextByCid[$cid] ?? null) ? self::$runtimeContextByCid[$cid] : [];
        $payload = is_array($runtime['payload'] ?? null) ? $runtime['payload'] : self::buildPayload($ctx);
        $token = is_string($runtime['token'] ?? null) ? $runtime['token'] : self::payloadToToken($payload);
        $packet = is_string($runtime['packet'] ?? null) ? $runtime['packet'] : self::tokenToZeroWidth($token);

        $security = Typecho_Widget::widget('Widget_Security');
        $actionUrl = (string) $security->getIndex('/action/stegomark');
        $pluginUrl = rtrim((string) Helper::options()->pluginUrl, '/') . '/StegoMark';
        $cssLayerEnabled = in_array('css', (array) ($cfg['algorithms'] ?? []), true);

        $bootstrap = [
            'version' => self::VERSION,
            'cid' => $cid,
            'title' => (string) ($ctx['title'] ?? ''),
            'url' => (string) ($ctx['permalink'] ?? ''),
            'site' => (string) (Helper::options()->title ?? ''),
            'siteFingerprint' => (string) ($ctx['siteFingerprint'] ?? ''),
            'watermarkId' => (string) ($ctx['watermark']['wmid'] ?? ''),
            'watermarkCreatedAt' => (int) ($ctx['watermark']['createdAt'] ?? 0),
            'token' => $token,
            'serverPacket' => $packet,
            'payloadBase' => $payload,
            'actionUrl' => $actionUrl,
            'copy' => [
                'enabled' => !empty($cfg['copyAppendEnabled']) ? 1 : 0,
                'template' => (string) ($cfg['copyAppendTemplate'] ?? ''),
                'logEnabled' => !empty($cfg['copyLogEnabled']) ? 1 : 0,
            ],
            'dynamic' => [
                'enabled' => !empty($cfg['dynamicInjectEnabled']) ? 1 : 0,
                'strength' => (string) ($cfg['watermarkStrength'] ?? 'medium'),
                'distribution' => (string) ($cfg['distributionMode'] ?? 'paragraph'),
                'contentSelector' => (string) ($cfg['contentSelector'] ?? ''),
            ],
            'visual' => [
                'bg' => (!empty($cfg['visualBgEnabled']) && $cssLayerEnabled) ? 1 : 0,
                'noise' => (!empty($cfg['visualNoiseEnabled']) && $cssLayerEnabled) ? 1 : 0,
                'canvas' => (!empty($cfg['visualCanvasEnabled']) && $cssLayerEnabled) ? 1 : 0,
                'bgOpacity' => (float) ($cfg['visualBgOpacity'] ?? 0.020),
                'noiseOpacity' => (float) ($cfg['visualNoiseOpacity'] ?? 0.015),
                'canvasOpacity' => (float) ($cfg['visualCanvasOpacity'] ?? 0.018),
            ],
            'block' => [
                'enabled' => !empty($cfg['blockIfNoFingerprint']) ? 1 : 0,
            ],
            'algorithms' => array_values($cfg['algorithms'] ?? []),
            'visitor' => [
                'enabled' => !empty($ctx['visitor']['enabled']) ? 1 : 0,
                'visitorId' => (string) ($ctx['visitor']['visitorId'] ?? ''),
                'bucket' => (int) ($ctx['visitor']['bucket'] ?? 0),
            ],
            'user' => [
                'logged' => !empty($ctx['user']['logged']) ? 1 : 0,
                'uid' => (int) ($ctx['user']['uid'] ?? 0),
                'name' => (string) ($ctx['user']['name'] ?? ''),
            ],
            'customCss' => (string) ($cfg['customCss'] ?? ''),
            'customLayoutHtml' => (string) ($cfg['customLayoutHtml'] ?? ''),
            'copyAnomalyThreshold' => (int) ($cfg['copyAnomalyThreshold'] ?? 5),
            'copyAnomalyWindow' => (int) ($cfg['copyAnomalyWindow'] ?? 300),
        ];

        $json = json_encode(
            $bootstrap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            return;
        }

        self::$footerRendered = true;

        echo '<link rel="stylesheet" href="' . htmlspecialchars($pluginUrl . '/assets/stegomark.css?v=' . self::VERSION, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div id="stegomark-root" class="stegomark-root" aria-hidden="true"></div>';
        echo '<script type="application/json" id="stegomark-bootstrap">' . $json . '</script>';
        echo '<script src="' . htmlspecialchars($pluginUrl . '/assets/stegomark.js?v=' . self::VERSION, ENT_QUOTES, 'UTF-8') . '"></script>';

        if (!empty($cfg['copyLogEnabled']) || !empty($ctx['visitor']['enabled'])) {
            StegoMark_Store::appendLog('access', [
                'cid' => $cid,
                'wmid' => (string) ($ctx['watermark']['wmid'] ?? ''),
                'visitorId' => (string) ($ctx['visitor']['visitorId'] ?? ''),
                'visitorBucket' => (int) ($ctx['visitor']['bucket'] ?? 0),
                'ipHash' => (string) ($ctx['visitor']['ipHash'] ?? ''),
                'uaHash' => (string) ($ctx['visitor']['uaHash'] ?? ''),
                'url' => (string) ($ctx['permalink'] ?? ''),
                'createdAt' => time(),
            ]);
        }
    }

    public static function decodeWatermarksFromText(string $text): array
    {
        $text = (string) $text;
        if ($text === '') {
            return [];
        }

        $items = [];
        $seen = [];

        foreach (self::extractZeroWidthTokens($text) as $token) {
            $payload = self::tokenToPayload($token);
            if (!is_array($payload)) {
                continue;
            }
            $row = self::normalizeDecodedPayload($payload, 'char');
            $sig = md5(json_encode($row));
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = 1;
            $items[] = $row;
        }

        if (preg_match_all('/<!--\s*SM:([A-Za-z0-9\-_]+)\s*-->/i', $text, $m)) {
            foreach ((array) ($m[1] ?? []) as $token) {
                $payload = self::tokenToPayload((string) $token);
                if (!is_array($payload)) {
                    continue;
                }
                $row = self::normalizeDecodedPayload($payload, 'comment');
                $sig = md5(json_encode($row));
                if (!isset($seen[$sig])) {
                    $seen[$sig] = 1;
                    $items[] = $row;
                }
            }
        }

        if (preg_match_all('/data-sm-token=["\']([A-Za-z0-9\-_]+)["\']/i', $text, $m2)) {
            foreach ((array) ($m2[1] ?? []) as $token) {
                $payload = self::tokenToPayload((string) $token);
                if (!is_array($payload)) {
                    continue;
                }
                $row = self::normalizeDecodedPayload($payload, 'css');
                $sig = md5(json_encode($row));
                if (!isset($seen[$sig])) {
                    $seen[$sig] = 1;
                    $items[] = $row;
                }
            }
        }

        usort($items, function ($a, $b) {
            return (int) ($b['generatedAt'] ?? 0) <=> (int) ($a['generatedAt'] ?? 0);
        });
        return $items;
    }

    public static function formatTime(int $ts): string
    {
        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
    }

    public static function resolveArchiveContext($widget, array $cfg): ?array
    {
        if (!is_object($widget) || !method_exists($widget, 'is') || !$widget->is('single')) {
            return null;
        }
        $type = strtolower((string) ($widget->type ?? ''));
        if (!in_array($type, ['post', 'page'], true)) {
            return null;
        }
        if (!empty($cfg['postOnly']) && $type !== 'post') {
            return null;
        }
        $cid = (int) ($widget->cid ?? 0);
        if ($cid <= 0) {
            return null;
        }

        $wm = StegoMark_Store::ensurePostWatermark($cid, (int) ($cfg['watermarkTokenLength'] ?? 12));
        $site = StegoMark_Store::ensureSiteIdentity();
        $visitor = self::buildVisitorFingerprint($cfg);
        $user = Typecho_Widget::widget('Widget_User');
        $userLogged = is_object($user) && method_exists($user, 'hasLogin') && $user->hasLogin();
        $userCtx = [
            'logged' => $userLogged ? 1 : 0,
            'uid' => $userLogged ? (int) ($user->uid ?? 0) : 0,
            'name' => $userLogged ? (string) ($user->screenName ?? '') : '',
        ];

        return [
            'cid' => $cid,
            'type' => $type,
            'title' => (string) ($widget->title ?? ''),
            'permalink' => (string) ($widget->permalink ?? ''),
            'siteFingerprint' => (string) ($site['siteFingerprint'] ?? ''),
            'watermark' => $wm,
            'visitor' => $visitor,
            'user' => $userCtx,
        ];
    }

    public static function buildVisitorFingerprint(array $cfg): array
    {
        if (empty($cfg['visitorFingerprintEnabled'])) {
            return ['enabled' => 0, 'visitorId' => '', 'bucket' => 0, 'salt' => '', 'ipHash' => '', 'uaHash' => ''];
        }
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $bucketSec = max(60, (int) ($cfg['visitorBucketSeconds'] ?? 1800));
        $bucket = (int) (floor(time() / $bucketSec) * $bucketSec);
        $salt = substr(hash('sha256', microtime(true) . '|' . mt_rand()), 0, 16);
        $secret = StegoMark_Store::getSiteSecret();
        $raw = $ip . '|' . $ua . '|' . $bucket . '|' . $salt;
        $visitorId = substr(hash_hmac('sha256', $raw, $secret), 0, 24);

        return [
            'enabled' => 1,
            'visitorId' => $visitorId,
            'bucket' => $bucket,
            'salt' => $salt,
            'ipHash' => $ip === '' ? '' : substr(hash('sha256', $ip), 0, 24),
            'uaHash' => $ua === '' ? '' : substr(hash('sha256', $ua), 0, 24),
        ];
    }

    public static function buildPayload(array $ctx): array
    {
        $payload = [
            'v' => 1,
            'sid' => (string) ($ctx['siteFingerprint'] ?? ''),
            'cid' => (int) ($ctx['cid'] ?? 0),
            'wid' => (string) ($ctx['watermark']['wmid'] ?? ''),
            'wct' => (int) ($ctx['watermark']['createdAt'] ?? 0),
            'ts' => time(),
        ];
        if (!empty($ctx['visitor']['enabled'])) {
            $payload['vi'] = (string) ($ctx['visitor']['visitorId'] ?? '');
            $payload['vb'] = (int) ($ctx['visitor']['bucket'] ?? 0);
        }
        if (!empty($ctx['user']['logged'])) {
            $payload['uid'] = (int) ($ctx['user']['uid'] ?? 0);
            $payload['un'] = self::cut((string) ($ctx['user']['name'] ?? ''), 60);
        }
        return $payload;
    }

    public static function payloadToToken(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        $base = base64_encode($json);
        if (!is_string($base)) {
            return '';
        }
        return rtrim(strtr($base, '+/', '-_'), '=');
    }

    public static function tokenToPayload(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || preg_match('/[^A-Za-z0-9\-_]/', $token)) {
            return null;
        }
        $base = strtr($token, '-_', '+/');
        $base .= str_repeat('=', (4 - (strlen($base) % 4)) % 4);
        $json = base64_decode($base, true);
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public static function tokenToZeroWidth(string $token): string
    {
        if ($token === '') {
            return '';
        }
        $bits = '';
        $len = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($token[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = self::ZW_START;
        $bitLen = strlen($bits);
        for ($i = 0; $i < $bitLen; $i++) {
            $out .= ($bits[$i] === '1') ? self::ZW_ONE : self::ZW_ZERO;
        }
        return $out . self::ZW_END;
    }

    public static function extractZeroWidthTokens(string $text): array
    {
        $tokens = [];
        $cursor = 0;
        $startLen = strlen(self::ZW_START);
        $endLen = strlen(self::ZW_END);
        while (($s = strpos($text, self::ZW_START, $cursor)) !== false) {
            $s += $startLen;
            $e = strpos($text, self::ZW_END, $s);
            if ($e === false) {
                break;
            }
            $token = self::zeroWidthChunkToToken(substr($text, $s, $e - $s));
            if ($token !== '') {
                $tokens[] = $token;
            }
            $cursor = $e + $endLen;
        }
        return $tokens;
    }

    public static function injectCharacterWatermark(string $html, string $packet, array $cfg): string
    {
        if ($packet === '') {
            return $html;
        }

        $segments = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($segments)) {
            return self::insertPacketIntoText($html, $packet, (string) ($cfg['distributionMode'] ?? 'paragraph'), 7);
        }

        $candidates = [];
        $fallback = [];
        $segCount = count($segments);
        for ($i = 0; $i < $segCount; $i += 2) {
            $txt = (string) ($segments[$i] ?? '');
            if (!self::isInjectableText($txt)) {
                continue;
            }
            $fallback[] = $i;

            if (($cfg['distributionMode'] ?? 'paragraph') === 'paragraph') {
                $prev = (string) ($segments[$i - 1] ?? '');
                if ($prev !== '' && preg_match('/<(p|li|h[1-6]|blockquote|dd|td)\b/i', $prev)) {
                    $candidates[] = $i;
                }
            } else {
                $candidates[] = $i;
            }
        }

        if (empty($candidates)) {
            $candidates = $fallback;
        }
        if (empty($candidates)) {
            return $html . $packet;
        }

        $copies = self::resolveCopyCount($cfg, count($candidates));
        $copies = min($copies, 12);
        $pool = array_values($candidates);
        for ($n = 0; $n < $copies; $n++) {
            if (empty($pool)) {
                $pool = array_values($candidates);
            }
            $seed = (int) sprintf('%u', crc32($packet . '#' . $n));
            $pickIndex = $seed % count($pool);
            $segIndex = (int) $pool[$pickIndex];
            array_splice($pool, $pickIndex, 1);

            $segments[$segIndex] = self::insertPacketIntoText(
                (string) $segments[$segIndex],
                $packet,
                (string) ($cfg['distributionMode'] ?? 'paragraph'),
                $seed
            );
        }

        return implode('', $segments);
    }

    public static function injectCommentWatermark(string $html, string $token, array $cfg): string
    {
        if ($token === '') {
            return $html;
        }

        $comment = '<!--SM:' . $token . '-->';
        $copies = min(self::resolveCopyCount($cfg, 12), 6);

        $matched = preg_match_all('/<\/p>/i', $html, $m, PREG_OFFSET_CAPTURE);
        if ($matched !== false && $matched > 0) {
            $offsets = [];
            foreach ((array) ($m[0] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $offsets[] = (int) ($row[1] ?? 0) + strlen((string) ($row[0] ?? ''));
            }

            if (!empty($offsets)) {
                $pick = [];
                $pool = $offsets;
                for ($i = 0; $i < $copies; $i++) {
                    if (empty($pool)) {
                        break;
                    }
                    $seed = (int) sprintf('%u', crc32($token . ':c:' . $i));
                    $idx = $seed % count($pool);
                    $pick[] = (int) $pool[$idx];
                    array_splice($pool, $idx, 1);
                }
                rsort($pick);
                foreach ($pick as $pos) {
                    $html = substr($html, 0, $pos) . $comment . substr($html, $pos);
                }
                return $html;
            }
        }

        return $html . $comment;
    }

    private static function resolveCopyCount(array $cfg, int $candidateCount): int
    {
        $baseMap = ['weak' => 1, 'medium' => 2, 'strong' => 4];
        $strength = (string) ($cfg['watermarkStrength'] ?? 'medium');
        $base = (int) ($baseMap[$strength] ?? 2);
        $ratio = max(0.01, min(0.8, (float) ($cfg['insertRatio'] ?? 0.08)));
        $ratioCount = (int) ceil($candidateCount * $ratio);
        return max(1, min(max($base, $ratioCount), max(1, $candidateCount)));
    }

    private static function insertPacketIntoText(string $text, string $packet, string $distribution, int $seed): string
    {
        if (!self::isInjectableText($text)) {
            return $text;
        }
        $chars = self::splitChars($text);
        $count = count($chars);
        if ($count < 2) {
            return $text . $packet;
        }

        $positions = [];
        if ($distribution === 'sentence') {
            $punct = ['。', '！', '？', '!', '?', ';', '；', '.', '…'];
            foreach ($chars as $i => $ch) {
                if (in_array($ch, $punct, true)) {
                    $positions[] = $i + 1;
                }
            }
        } elseif ($distribution === 'paragraph') {
            $positions[] = max(1, (int) floor($count / 3));
            $positions[] = max(1, (int) floor($count * 0.66));
        }

        if (empty($positions)) {
            foreach ($chars as $i => $ch) {
                if (trim($ch) !== '') {
                    $positions[] = $i + 1;
                }
            }
        }
        if (empty($positions)) {
            $positions[] = max(1, (int) floor($count / 2));
        }

        $pick = $positions[$seed % count($positions)];
        array_splice($chars, max(0, min($count, $pick)), 0, [$packet]);
        return implode('', $chars);
    }

    private static function zeroWidthChunkToToken(string $chunk): string
    {
        $chars = self::splitChars($chunk);
        if (empty($chars)) {
            return '';
        }

        $bits = '';
        foreach ($chars as $ch) {
            if ($ch === self::ZW_ZERO) {
                $bits .= '0';
            } elseif ($ch === self::ZW_ONE) {
                $bits .= '1';
            }
        }

        $usable = (int) floor(strlen($bits) / 8) * 8;
        if ($usable <= 0) {
            return '';
        }

        $raw = '';
        for ($i = 0; $i < $usable; $i += 8) {
            $raw .= chr(bindec(substr($bits, $i, 8)));
        }

        if ($raw === '' || preg_match('/[^A-Za-z0-9\-_]/', $raw)) {
            return '';
        }
        return $raw;
    }

    private static function normalizeDecodedPayload(array $payload, string $source): array
    {
        return [
            'source' => $source,
            'articleId' => (int) ($payload['cid'] ?? 0),
            'watermarkId' => (string) ($payload['wid'] ?? ''),
            'siteFingerprint' => (string) ($payload['sid'] ?? ''),
            'generatedAt' => (int) ($payload['ts'] ?? 0),
            'watermarkCreatedAt' => (int) ($payload['wct'] ?? 0),
            'visitorId' => (string) ($payload['vi'] ?? ''),
            'visitorBucket' => (int) ($payload['vb'] ?? 0),
            'userId' => (int) ($payload['uid'] ?? 0),
            'username' => (string) ($payload['un'] ?? ''),
            'payload' => $payload,
        ];
    }

    private static function isInjectableText(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }
        return preg_match('/[\p{L}\p{N}\p{Han}]/u', $text) === 1;
    }

    private static function splitChars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars)) {
            return $chars;
        }
        return str_split($text);
    }

    private static function shouldBypassForCrawler(array $cfg): bool
    {
        if (empty($cfg['crawlerNoWatermark'])) {
            return false;
        }
        $ua = strtolower(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($ua === '') {
            return false;
        }
        $whitelist = preg_split('/[\r\n,]+/', (string) ($cfg['crawlerWhitelist'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($whitelist)) {
            return false;
        }
        foreach ($whitelist as $token) {
            $token = strtolower(trim((string) $token));
            if ($token !== '' && strpos($ua, $token) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function shouldBypassForApiFeed(array $cfg): bool
    {
        if (empty($cfg['apiFeedNoWatermark'])) {
            return false;
        }
        $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if ($uri === '') {
            return false;
        }
        return strpos($uri, '/feed') !== false
            || strpos($uri, 'feed=') !== false
            || strpos($uri, '/api/') !== false
            || strpos($uri, 'rest_route=') !== false;
    }

    private static function isAdminRequest(): bool
    {
        return defined('__TYPECHO_ADMIN__') && __TYPECHO_ADMIN__;
    }

    private static function checkboxEnabled($value): bool
    {
        if (is_array($value)) {
            return !empty($value);
        }
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private static function cssStringLiteral(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $text = self::cut($text, 1000);
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('"', '\\"', $text);
        // Prevent breaking out of <style> via a literal "</style>" sequence in raw-text mode.
        $text = str_replace('<', '\\3C ', $text);
        $text = str_replace('>', '\\3E ', $text);
        $text = str_replace("\n", '\\A ', $text);
        return '"' . $text . '"';
    }

    private static function cut(string $text, int $len): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $len);
        }
        return substr($text, 0, $len);
    }
}
