<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Store.php';

/**
 * 为 Typecho 提供隐形水印、访客指纹与泄露溯源的内容版权保护插件
 * @package StegoMark
 * @author 寒士杰克
 * @version 1.1.1
 * @link https://www.hansjack.com
 */
class StegoMark_Plugin implements Typecho_Plugin_Interface
{
    private const VERSION = '1.1.1';

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

        $antiReverseMode = new Typecho_Widget_Helper_Form_Element_Select(
            'antiReverseMode',
            [
                'off' => _t('关闭（兼容可读载荷）'),
                'sign' => _t('签名防伪（防篡改/防伪造）'),
                'seal' => _t('封装延迟逆向（载荷不可读）'),
            ],
            'seal',
            _t('防逆向/防伪'),
            _t('签名/封装模式会对水印载荷做“防伪校验”，并延迟外部逆向与伪造；解码需在后台进行。')
        );
        $form->addInput($antiReverseMode);

        $actionSignedRequests = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'actionSignedRequests',
            ['1' => _t('启用接口签名（防刷/防 CSRF 伪造日志）')],
            ['1'],
            _t('接口保护'),
            _t('为前端 ping/copy_log 请求增加签名校验，降低恶意刷日志与资源消耗风险。')
        );
        $form->addInput($actionSignedRequests);

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

        $extraInjectEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'extraInjectEnabled',
            ['1' => _t('启用：额外容器隐藏水印注入')],
            [],
            _t('额外容器注入'),
            _t('可将隐藏水印注入到你指定的前台其他容器（如页眉、侧边栏、页脚等），用于扩大取证覆盖面。需启用“字符水印”算法。')
        );
        $form->addInput($extraInjectEnabled);

        $extraInjectScope = new Typecho_Widget_Helper_Form_Element_Select(
            'extraInjectScope',
            ['single' => _t('仅文章/页面单页'), 'all' => _t('全站前台页面')],
            'single',
            _t('额外容器作用范围'),
            _t('仅影响“额外容器注入”功能；不会改变文章正文水印逻辑。')
        );
        $form->addInput($extraInjectScope);

        $extraInjectSelectors = new Typecho_Widget_Helper_Form_Element_Textarea(
            'extraInjectSelectors',
            null,
            '',
            _t('额外容器选择器'),
            _t("一行一个 CSS 选择器。示例：\n.site-title\n.site-description\n.sidebar\nfooter")
        );
        $form->addInput($extraInjectSelectors);

        $extraInjectMinLength = new Typecho_Widget_Helper_Form_Element_Text(
            'extraInjectMinLength',
            null,
            '24',
            _t('额外容器最小文本长度'),
            _t('仅对文本长度达到该值的文本节点注入，避免短文本被“肉眼可感知”。建议 12-60。')
        );
        $form->addInput($extraInjectMinLength);

        $extraInjectMaxInserts = new Typecho_Widget_Helper_Form_Element_Text(
            'extraInjectMaxInserts',
            null,
            '18',
            _t('额外容器每页最多注入次数'),
            _t('用于限制全站模式下的注入规模，避免影响性能。建议 8-60。')
        );
        $form->addInput($extraInjectMaxInserts);

        $inlineFrontendJs = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'inlineFrontendJs',
            ['1' => _t('内联前端 JS（避免外链被拦截）')],
            [],
            _t('前端脚本'),
            _t('启用后将把 StegoMark 前端脚本直接内联输出到页面，减少被浏览器插件/规则拦截外链 JS 导致功能失效的情况。若用户完全禁用 JS，则所有前端功能仍不可用。')
        );
        $form->addInput($inlineFrontendJs);

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
            'antiReverseMode' => 'seal',
            'actionSignedRequests' => true,
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
            'extraInjectEnabled' => false,
            'extraInjectScope' => 'single',
            'extraInjectSelectors' => '',
            'extraInjectMinLength' => 24,
            'extraInjectMaxInserts' => 18,
            'inlineFrontendJs' => false,
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
        $cfg['actionSignedRequests'] = self::checkboxEnabled($cfg['actionSignedRequests'] ?? null);
        $cfg['crawlerNoWatermark'] = self::checkboxEnabled($cfg['crawlerNoWatermark'] ?? null);
        $cfg['apiFeedNoWatermark'] = self::checkboxEnabled($cfg['apiFeedNoWatermark'] ?? null);
        $cfg['visualBgEnabled'] = self::checkboxEnabled($cfg['visualBgEnabled'] ?? null);
        $cfg['visualNoiseEnabled'] = self::checkboxEnabled($cfg['visualNoiseEnabled'] ?? null);
        $cfg['visualCanvasEnabled'] = self::checkboxEnabled($cfg['visualCanvasEnabled'] ?? null);
        $cfg['blockIfNoFingerprint'] = self::checkboxEnabled($cfg['blockIfNoFingerprint'] ?? null);
        $cfg['extraInjectEnabled'] = self::checkboxEnabled($cfg['extraInjectEnabled'] ?? null);
        $cfg['inlineFrontendJs'] = self::checkboxEnabled($cfg['inlineFrontendJs'] ?? null);

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

        $mode = strtolower(trim((string) ($cfg['antiReverseMode'] ?? 'seal')));
        $cfg['antiReverseMode'] = in_array($mode, ['off', 'sign', 'seal'], true) ? $mode : 'seal';

        $extraScope = strtolower(trim((string) ($cfg['extraInjectScope'] ?? 'single')));
        $cfg['extraInjectScope'] = in_array($extraScope, ['single', 'all'], true) ? $extraScope : 'single';

        $cfg['watermarkTokenLength'] = max(6, min(40, (int) ($cfg['watermarkTokenLength'] ?? 12)));
        $cfg['insertRatio'] = max(0.01, min(0.8, (float) ($cfg['insertRatio'] ?? 0.08)));
        $cfg['copyAnomalyThreshold'] = max(2, min(1000, (int) ($cfg['copyAnomalyThreshold'] ?? 5)));
        $cfg['copyAnomalyWindow'] = max(30, min(86400, (int) ($cfg['copyAnomalyWindow'] ?? 300)));
        $cfg['visitorBucketSeconds'] = max(60, min(86400, (int) ($cfg['visitorBucketSeconds'] ?? 1800)));
        $cfg['visualBgOpacity'] = max(0.0, min(0.2, (float) ($cfg['visualBgOpacity'] ?? 0.020)));
        $cfg['visualNoiseOpacity'] = max(0.0, min(0.2, (float) ($cfg['visualNoiseOpacity'] ?? 0.015)));
        $cfg['visualCanvasOpacity'] = max(0.0, min(0.2, (float) ($cfg['visualCanvasOpacity'] ?? 0.018)));
        $cfg['extraInjectMinLength'] = max(0, min(500, (int) ($cfg['extraInjectMinLength'] ?? 24)));
        $cfg['extraInjectMaxInserts'] = max(1, min(500, (int) ($cfg['extraInjectMaxInserts'] ?? 18)));
        $cfg['copyAppendTemplate'] = self::cut((string) ($cfg['copyAppendTemplate'] ?? ''), 3000);
        $cfg['blockMaskText'] = self::cut((string) ($cfg['blockMaskText'] ?? ''), 2000);
        $cfg['crawlerWhitelist'] = self::cut((string) ($cfg['crawlerWhitelist'] ?? ''), 3000);
        $cfg['contentSelector'] = self::cut((string) ($cfg['contentSelector'] ?? ''), 500);
        $cfg['extraInjectSelectors'] = self::cut((string) ($cfg['extraInjectSelectors'] ?? ''), 4000);
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
        $token = self::payloadToToken($payload, $cfg);
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
        $extraEnabled = !empty($cfg['extraInjectEnabled']) && trim((string) ($cfg['extraInjectSelectors'] ?? '')) !== '';
        $extraAll = $extraEnabled && ((string) ($cfg['extraInjectScope'] ?? 'single') === 'all');
        if (!$ctx && !$extraAll) {
            return;
        }
        if (!$ctx) {
            $ctx = self::resolveGlobalContext($archive, $cfg);
        }

        $cid = (int) ($ctx['cid'] ?? 0);
        $runtime = $cid > 0 && is_array(self::$runtimeContextByCid[$cid] ?? null) ? self::$runtimeContextByCid[$cid] : [];
        $payload = is_array($runtime['payload'] ?? null) ? $runtime['payload'] : self::buildPayload($ctx);
        $token = is_string($runtime['token'] ?? null) ? $runtime['token'] : self::payloadToToken($payload, $cfg);
        $packet = is_string($runtime['packet'] ?? null) ? $runtime['packet'] : self::tokenToZeroWidth($token);

        $security = Typecho_Widget::widget('Widget_Security');
        $actionUrl = (string) $security->getIndex('/action/stegomark');
        $pluginUrl = rtrim((string) Helper::options()->pluginUrl, '/') . '/StegoMark';
        $cssLayerEnabled = in_array('css', (array) ($cfg['algorithms'] ?? []), true);

        $actionSig = ['enabled' => !empty($cfg['actionSignedRequests']) ? 1 : 0, 'ts' => 0, 'sig' => '', 'ttl' => 0];
        if (!empty($cfg['actionSignedRequests'])) {
            $sigTs = time();
            $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $ipHash = $ip === '' ? '' : substr(hash('sha256', $ip), 0, 24);
            $uaHash = $ua === '' ? '' : substr(hash('sha256', $ua), 0, 24);
            $wmid = (string) ($ctx['watermark']['wmid'] ?? '');
            $secret = StegoMark_Store::getSiteSecret();
            $base = $sigTs . '|' . $cid . '|' . $wmid . '|' . $ipHash . '|' . $uaHash;
            $sig = substr(hash_hmac('sha256', $base, $secret), 0, 32);
            $actionSig = ['enabled' => 1, 'ts' => $sigTs, 'sig' => $sig, 'ttl' => 7200];
        }

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
            'actionSig' => $actionSig,
            'page' => [
                'isSingle' => $cid > 0 ? 1 : 0,
                'type' => (string) ($ctx['type'] ?? ''),
            ],
            'integrity' => [
                'mode' => (string) ($cfg['antiReverseMode'] ?? 'off'),
            ],
            'extra' => [
                'enabled' => $extraEnabled ? 1 : 0,
                'scope' => (string) ($cfg['extraInjectScope'] ?? 'single'),
                'selectors' => (string) ($cfg['extraInjectSelectors'] ?? ''),
                'minLength' => (int) ($cfg['extraInjectMinLength'] ?? 24),
                'maxInserts' => (int) ($cfg['extraInjectMaxInserts'] ?? 18),
            ],
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
        echo '<div id="stegomark-root" class="stegomark-root" aria-hidden="true" data-sm-token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"></div>';
        echo '<script type="application/json" id="stegomark-bootstrap">' . $json . '</script>';

        if (!empty($cfg['inlineFrontendJs'])) {
            $js = @file_get_contents(__DIR__ . '/assets/stegomark.js');
            if (is_string($js) && trim($js) !== '') {
                // Avoid accidental termination if user content contains "</script>".
                $js = str_replace('</script', '</scr"+"ipt', $js);
                echo '<script id="stegomark-inline-js">' . $js . '</script>';
            } else {
                echo '<script src="' . htmlspecialchars($pluginUrl . '/assets/stegomark.js?v=' . self::VERSION, ENT_QUOTES, 'UTF-8') . '"></script>';
            }
        } else {
            echo '<script src="' . htmlspecialchars($pluginUrl . '/assets/stegomark.js?v=' . self::VERSION, ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        if ($cid > 0 && (!empty($cfg['copyLogEnabled']) || !empty($ctx['visitor']['enabled']))) {
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
            $decoded = self::tokenToPayload($token);
            if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
                continue;
            }
            $row = self::normalizeDecodedPayload((array) $decoded['payload'], 'char', (array) ($decoded['meta'] ?? []));
            $sig = md5(json_encode($row));
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = 1;
            $items[] = $row;
        }

        if (preg_match_all('/<!--\s*SM:([A-Za-z0-9\-_]+)\s*-->/i', $text, $m)) {
            foreach ((array) ($m[1] ?? []) as $token) {
                $decoded = self::tokenToPayload((string) $token);
                if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
                    continue;
                }
                $row = self::normalizeDecodedPayload((array) $decoded['payload'], 'comment', (array) ($decoded['meta'] ?? []));
                $sig = md5(json_encode($row));
                if (!isset($seen[$sig])) {
                    $seen[$sig] = 1;
                    $items[] = $row;
                }
            }
        }

        if (preg_match_all('/data-sm-token=["\']([A-Za-z0-9\-_]+)["\']/i', $text, $m2)) {
            foreach ((array) ($m2[1] ?? []) as $token) {
                $decoded = self::tokenToPayload((string) $token);
                if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
                    continue;
                }
                $row = self::normalizeDecodedPayload((array) $decoded['payload'], 'css', (array) ($decoded['meta'] ?? []));
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

    private static function resolveGlobalContext($widget, array $cfg): array
    {
        $site = StegoMark_Store::ensureSiteIdentity();
        $visitor = self::buildVisitorFingerprint($cfg);

        $user = Typecho_Widget::widget('Widget_User');
        $userLogged = is_object($user) && method_exists($user, 'hasLogin') && $user->hasLogin();
        $userCtx = [
            'logged' => $userLogged ? 1 : 0,
            'uid' => $userLogged ? (int) ($user->uid ?? 0) : 0,
            'name' => $userLogged ? (string) ($user->screenName ?? '') : '',
        ];

        $title = is_object($widget) ? (string) ($widget->title ?? '') : '';
        $permalink = is_object($widget) ? (string) ($widget->permalink ?? '') : '';
        if ($permalink === '') {
            $siteUrl = rtrim((string) (Helper::options()->siteUrl ?? ''), '/');
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $permalink = $siteUrl !== '' && $uri !== '' ? ($siteUrl . $uri) : '';
        }

        return [
            'cid' => 0,
            'type' => 'global',
            'title' => $title,
            'permalink' => $permalink,
            'siteFingerprint' => (string) ($site['siteFingerprint'] ?? ''),
            'watermark' => ['wmid' => '', 'createdAt' => 0, 'updatedAt' => 0],
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

    public static function payloadToToken(array $payload, array $cfg = []): string
    {
        $mode = strtolower(trim((string) ($cfg['antiReverseMode'] ?? 'off')));
        if (!in_array($mode, ['off', 'sign', 'seal'], true)) {
            $mode = 'seal';
        }
        if ($mode === 'off') {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return '';
            }
            return self::base64UrlEncode($json);
        }

        $secret = StegoMark_Store::getSiteSecret();
        if ($secret === '') {
            // Fallback: keep working even if secret is missing for any reason.
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json === false ? '' : self::base64UrlEncode($json);
        }

        $flags = 0;

        $sidBin = self::siteFingerprintToBin((string) ($payload['sid'] ?? ''));
        $cid = (int) ($payload['cid'] ?? 0);
        $wct = (int) ($payload['wct'] ?? 0);
        $ts = (int) ($payload['ts'] ?? time());

        $wid = self::cut((string) ($payload['wid'] ?? ''), 80);
        $widRaw = (string) $wid;
        $widLen = strlen($widRaw);
        if ($widLen > 255) {
            $widRaw = substr($widRaw, 0, 255);
            $widLen = strlen($widRaw);
        }

        $plain = $sidBin
            . self::packU32($cid)
            . self::packU32($wct)
            . self::packU32($ts)
            . chr($widLen)
            . $widRaw;

        $vi = '';
        $vb = 0;
        if (!empty($payload['vi']) && is_string($payload['vi']) && preg_match('/^[A-Fa-f0-9]{24}$/', (string) $payload['vi'])) {
            $bin = self::hexToBinSafe((string) $payload['vi']);
            if ($bin !== '' && strlen($bin) === 12) {
                $vi = $bin;
                $vb = (int) ($payload['vb'] ?? 0);
                $flags |= 1; // has visitor
                $plain .= $vi . self::packU32($vb);
            }
        }

        $uid = (int) ($payload['uid'] ?? 0);
        $un = trim((string) ($payload['un'] ?? ''));
        if ($uid > 0) {
            $flags |= 2; // has user
            $plain .= self::packU32($uid);
        }
        if (($flags & 2) === 2 && $un !== '') {
            $unBytes = self::cutBytes($un, 240);
            $unLen = strlen($unBytes);
            if ($unLen > 0) {
                $flags |= 4; // has username
                $plain .= chr(min(255, $unLen)) . $unBytes;
            }
        }

        if ($mode === 'seal') {
            $nonce = self::randomBytesCompat(8);
            if (strlen($nonce) !== 8) {
                $nonce = substr(hash('sha256', microtime(true) . '|' . mt_rand(), true), 0, 8);
            }
            $cipher = self::xorSeal($plain, $secret, $nonce);
            $head = chr(3) . chr($flags) . $nonce . $cipher;
            $sig = substr(hash_hmac('sha256', $head, $secret, true), 0, 16);
            return self::base64UrlEncode($head . $sig);
        }

        $head = chr(2) . chr($flags) . $plain;
        $sig = substr(hash_hmac('sha256', $head, $secret, true), 0, 16);
        return self::base64UrlEncode($head . $sig);
    }

    public static function tokenToPayload(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || preg_match('/[^A-Za-z0-9\-_]/', $token)) {
            return null;
        }

        $raw = self::base64UrlDecode($token);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $verByte = ord($raw[0]);
        if ($verByte === 2 || $verByte === 3) {
            $secret = StegoMark_Store::getSiteSecret();
            if ($secret === '') {
                return null;
            }

            $minLen = $verByte === 3 ? (1 + 1 + 8 + 16 + 10) : (1 + 1 + 16 + 10);
            if (strlen($raw) < $minLen) {
                return null;
            }

            $sig = substr($raw, -16);
            $head = substr($raw, 0, -16);
            $expect = substr(hash_hmac('sha256', $head, $secret, true), 0, 16);
            if (!hash_equals($expect, $sig)) {
                return null;
            }

            $flags = ord($raw[1]);
            $offset = 2;
            if ($verByte === 3) {
                $nonce = substr($raw, $offset, 8);
                $offset += 8;
                $cipher = substr($raw, $offset, strlen($raw) - $offset - 16);
                $plain = self::xorSeal($cipher, $secret, $nonce);
            } else {
                $plain = substr($raw, $offset, strlen($raw) - $offset - 16);
            }

            $payload = self::parseCompactPlain($plain, $flags);
            if (!is_array($payload)) {
                return null;
            }
            return [
                'payload' => $payload,
                'meta' => [
                    'tokenVersion' => $verByte,
                    'signatureOk' => 1,
                    'sealed' => $verByte === 3 ? 1 : 0,
                ],
            ];
        }

        // Legacy JSON payload: base64url(JSON)
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return [
            'payload' => $data,
            'meta' => [
                'tokenVersion' => 1,
                'signatureOk' => 0,
                'sealed' => 0,
            ],
        ];
    }

    private static function parseCompactPlain(string $plain, int $flags): ?array
    {
        $off = 0;
        if (strlen($plain) < (4 + 4 + 4 + 4 + 1)) {
            return null;
        }

        $sidBin = substr($plain, $off, 4);
        $off += 4;
        $cid = self::unpackU32($plain, $off);
        $wct = self::unpackU32($plain, $off);
        $ts = self::unpackU32($plain, $off);
        if ($cid === null || $wct === null || $ts === null) {
            return null;
        }

        $widLen = ord($plain[$off] ?? "\x00");
        $off += 1;
        if ($widLen < 0 || strlen($plain) < ($off + $widLen)) {
            return null;
        }
        $wid = substr($plain, $off, $widLen);
        $off += $widLen;

        $sidHex = strtoupper(bin2hex($sidBin));
        $payload = [
            'v' => 1,
            'sid' => $sidHex !== '' ? ('SM-' . $sidHex) : '',
            'cid' => (int) $cid,
            'wid' => (string) $wid,
            'wct' => (int) $wct,
            'ts' => (int) $ts,
        ];

        if (($flags & 1) === 1) {
            if (strlen($plain) < ($off + 12 + 4)) {
                return null;
            }
            $viBin = substr($plain, $off, 12);
            $off += 12;
            $vb = self::unpackU32($plain, $off);
            if ($vb === null) {
                return null;
            }
            $payload['vi'] = strtoupper(bin2hex($viBin));
            $payload['vb'] = (int) $vb;
        }

        if (($flags & 2) === 2) {
            $uid = self::unpackU32($plain, $off);
            if ($uid === null) {
                return null;
            }
            $payload['uid'] = (int) $uid;
        }

        if (($flags & 4) === 4) {
            if (strlen($plain) < ($off + 1)) {
                return null;
            }
            $unLen = ord($plain[$off] ?? "\x00");
            $off += 1;
            if ($unLen > 0) {
                if (strlen($plain) < ($off + $unLen)) {
                    return null;
                }
                $un = substr($plain, $off, $unLen);
                $payload['un'] = $un;
                $off += $unLen;
            }
        }

        return $payload;
    }

    private static function base64UrlEncode(string $raw): string
    {
        $base = base64_encode($raw);
        return is_string($base) ? rtrim(strtr($base, '+/', '-_'), '=') : '';
    }

    private static function base64UrlDecode(string $token): ?string
    {
        $base = strtr($token, '-_', '+/');
        $base .= str_repeat('=', (4 - (strlen($base) % 4)) % 4);
        $raw = base64_decode($base, true);
        return is_string($raw) ? $raw : null;
    }

    private static function packU32(int $v): string
    {
        $v = max(0, min(4294967295, $v));
        return pack('N', $v);
    }

    private static function unpackU32(string $raw, int &$offset): ?int
    {
        if (strlen($raw) < ($offset + 4)) {
            return null;
        }
        $chunk = substr($raw, $offset, 4);
        $offset += 4;
        $u = unpack('Nn', $chunk);
        if (!is_array($u) || !isset($u['n'])) {
            return null;
        }
        return (int) $u['n'];
    }

    private static function siteFingerprintToBin(string $sid): string
    {
        $sid = strtoupper(trim($sid));
        if (preg_match('/^SM-([A-F0-9]{8})$/', $sid, $m)) {
            $bin = self::hexToBinSafe((string) ($m[1] ?? ''));
            if ($bin !== '' && strlen($bin) === 4) {
                return $bin;
            }
        }
        return "\x00\x00\x00\x00";
    }

    private static function hexToBinSafe(string $hex): string
    {
        $hex = trim($hex);
        if ($hex === '' || (strlen($hex) % 2) !== 0 || preg_match('/[^A-Fa-f0-9]/', $hex)) {
            return '';
        }
        $bin = hex2bin($hex);
        return is_string($bin) ? $bin : '';
    }

    private static function cutBytes(string $text, int $maxBytes): string
    {
        $maxBytes = max(0, min(2048, $maxBytes));
        $text = (string) $text;
        if ($maxBytes <= 0 || $text === '') {
            return '';
        }
        if (function_exists('mb_strcut')) {
            return (string) mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }
        return substr($text, 0, $maxBytes);
    }

    private static function randomBytesCompat(int $len): string
    {
        $len = max(1, min(64, $len));
        try {
            return random_bytes($len);
        } catch (Throwable $e) {
            return substr(hash('sha256', uniqid((string) mt_rand(), true), true), 0, $len);
        }
    }

    private static function xorSeal(string $data, string $secret, string $nonce): string
    {
        if ($data === '') {
            return '';
        }
        $out = '';
        $pos = 0;
        $counter = 0;
        $len = strlen($data);
        while ($pos < $len) {
            $block = hash_hmac('sha256', $nonce . '|' . $counter, $secret, true);
            $take = min(strlen($block), $len - $pos);
            $chunk = substr($data, $pos, $take);
            $out .= ($chunk ^ substr($block, 0, $take));
            $pos += $take;
            $counter++;
        }
        return $out;
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

    private static function normalizeDecodedPayload(array $payload, string $source, array $meta = []): array
    {
        return [
            'source' => $source,
            'tokenVersion' => (int) ($meta['tokenVersion'] ?? 1),
            'signatureOk' => (int) ($meta['signatureOk'] ?? 0),
            'sealed' => (int) ($meta['sealed'] ?? 0),
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
