<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include 'common.php';
include 'header.php';
include 'menu.php';

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Plugin.php';

$user->pass('administrator');

$cfg = StegoMark_Plugin::getConfig();
$certificate = StegoMark_Store::buildCertificate();
$stats = StegoMark_Store::logStats();

$notice = '';
$noticeOk = true;
$decodeInput = '';
$decodeRows = [];
$rebuildResult = null;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $do = trim((string) $request->get('do', ''));

    if ($do === 'decode') {
        $decodeInput = (string) $request->get('decode_text', '');
        $decodeRows = StegoMark_Plugin::decodeWatermarksFromText($decodeInput);
        StegoMark_Store::appendLog('decode', [
            'count' => count($decodeRows),
            'createdAt' => time(),
            'source' => 'manage',
        ]);
        $notice = count($decodeRows) > 0 ? '已解析到 ' . count($decodeRows) . ' 组水印。' : '未检测到可识别水印。';
        $noticeOk = true;
    } elseif ($do === 'rebuild') {
        $force = (int) $request->get('force', 0) === 1;
        $rebuildResult = StegoMark_Store::rebuildAllPostWatermarks($force, (int) ($cfg['watermarkTokenLength'] ?? 12));
        if (!empty($rebuildResult['error'])) {
            $notice = '批量重建失败：' . (string) $rebuildResult['error'];
            $noticeOk = false;
        } else {
            $notice = '批量重建完成：总计 ' . (int) ($rebuildResult['total'] ?? 0) . '，更新 '
                . (int) ($rebuildResult['changed'] ?? 0) . '，保留 ' . (int) ($rebuildResult['kept'] ?? 0) . '。';
            $noticeOk = true;
        }
    } elseif ($do === 'clear_logs') {
        $type = trim((string) $request->get('log_type', ''));
        StegoMark_Store::clearLogs($type);
        $notice = $type === '' ? '已清空全部日志。' : ('已清空 ' . $type . ' 日志。');
        $noticeOk = true;
    }
}

$logType = strtolower(trim((string) $request->get('logType', '')));
$logs = StegoMark_Store::listLogs($logType, 120);
$wmMap = StegoMark_Store::getPostWatermarkMap(80);

$cids = [];
foreach ($logs as $row) {
    $cid = (int) ($row['cid'] ?? 0);
    if ($cid > 0) {
        $cids[$cid] = $cid;
    }
}
foreach ($decodeRows as $row) {
    $cid = (int) ($row['articleId'] ?? 0);
    if ($cid > 0) {
        $cids[$cid] = $cid;
    }
}
foreach ($wmMap as $row) {
    $cid = (int) ($row['cid'] ?? 0);
    if ($cid > 0) {
        $cids[$cid] = $cid;
    }
}

$postMap = [];
if (!empty($cids)) {
    try {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll(
            $db->select('cid', 'title')
                ->from('table.contents')
                ->where('cid IN ?', array_values($cids))
        );
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $postMap[(int) ($row['cid'] ?? 0)] = (string) ($row['title'] ?? '');
        }
    } catch (Throwable $e) {
    }
}

function sm_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<style>
.sm-wrap,.sm-wrap *{box-sizing:border-box}
.sm-wrap{--sm-bg:#f6f9ff;--sm-card:#ffffff;--sm-line:#dfe7f5;--sm-text:#223049;--sm-muted:#5c6b84;--sm-primary:#2557d6;--sm-ok:#188f4b;--sm-bad:#d44646;color:var(--sm-text)}
.sm-stack{display:flex;flex-direction:column;gap:14px;padding:18px}
.sm-banner{padding:14px 16px;border:1px solid var(--sm-line);border-radius:12px;background:linear-gradient(130deg,#f9fbff,#eef3ff)}
.sm-banner h2{margin:0 0 4px;font-size:18px}
.sm-banner p{margin:0;color:var(--sm-muted);font-size:13px;line-height:1.7}
.sm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.sm-card{background:var(--sm-card);border:1px solid var(--sm-line);border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(35,72,164,.06)}
.sm-k{font-size:12px;color:var(--sm-muted)}
.sm-v{font-size:24px;font-weight:700;line-height:1.2;margin-top:4px}
.sm-title{font-size:15px;font-weight:700;margin:0 0 10px}
.sm-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.sm-row input[type="text"],.sm-row input[type="number"],.sm-row select,.sm-area{border:1px solid var(--sm-line);border-radius:8px;padding:8px 10px;background:#fff;color:var(--sm-text)}
.sm-row input[type="text"],.sm-row input[type="number"],.sm-row select{height:34px}
.sm-area{width:100%;min-height:140px;line-height:1.6}
.sm-btn{height:34px;padding:0 12px;border:1px solid var(--sm-line);border-radius:8px;background:#fff;color:var(--sm-text);cursor:pointer}
.sm-btn.primary{background:var(--sm-primary);border-color:var(--sm-primary);color:#fff}
.sm-btn.danger{border-color:#f3c7c7;color:var(--sm-bad)}
.sm-note{padding:10px 12px;border-radius:8px;border:1px solid var(--sm-line);font-size:13px}
.sm-note.ok{background:rgba(24,143,75,.08);border-color:rgba(24,143,75,.25)}
.sm-note.bad{background:rgba(212,70,70,.08);border-color:rgba(212,70,70,.25)}
.sm-table-wrap{overflow:auto}
.sm-table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden}
.sm-table th,.sm-table td{border-bottom:1px solid var(--sm-line);padding:10px 12px;font-size:13px;text-align:left;vertical-align:top}
.sm-table th{background:#f2f6ff;color:var(--sm-muted);font-weight:600}
.sm-table tr:last-child td{border-bottom:0}
.sm-code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;background:#eef2ff;padding:2px 6px;border-radius:6px}
.sm-cert{white-space:pre-wrap;background:#f8faff;border:1px dashed var(--sm-line);border-radius:10px;padding:12px;font-size:12px;line-height:1.7}
@media (max-width: 960px){.sm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width: 680px){.sm-grid{grid-template-columns:1fr}.sm-stack{padding:14px}}
</style>

<main class="main">
    <div class="body container sm-wrap">
        <?php include 'page-title.php'; ?>
        <div class="typecho-page-main" role="main">
            <div class="sm-stack">
                <section class="sm-banner">
                    <h2>StegoMark 管理中心</h2>
                    <p>隐形水印、访客指纹、复制溯源、日志分析与批量重建。功能清单文件：<span class="sm-code"><?php echo sm_h(__DIR__ . '/FEATURES.md'); ?></span></p>
                </section>

                <?php if ($notice !== ''): ?>
                    <div class="sm-note <?php echo $noticeOk ? 'ok' : 'bad'; ?>"><?php echo sm_h($notice); ?></div>
                <?php endif; ?>

                <section class="sm-grid">
                    <article class="sm-card"><div class="sm-k">访问日志</div><div class="sm-v"><?php echo (int) ($stats['access'] ?? 0); ?></div></article>
                    <article class="sm-card"><div class="sm-k">复制日志</div><div class="sm-v"><?php echo (int) ($stats['copy'] ?? 0); ?></div></article>
                    <article class="sm-card"><div class="sm-k">解码日志</div><div class="sm-v"><?php echo (int) ($stats['decode'] ?? 0); ?></div></article>
                    <article class="sm-card"><div class="sm-k">异常告警</div><div class="sm-v"><?php echo (int) ($stats['alert'] ?? 0); ?></div></article>
                </section>

                <section class="sm-card">
                    <h3 class="sm-title">站点版权证书</h3>
                    <div class="sm-row">
                        <span class="sm-code">Site Fingerprint: <?php echo sm_h($certificate['siteFingerprint'] ?? ''); ?></span>
                        <span class="sm-code">Certificate ID: <?php echo sm_h($certificate['certificateId'] ?? ''); ?></span>
                    </div>
                    <div class="sm-row" style="margin-top:10px">
                        <div class="sm-cert"><?php echo sm_h((string) ($certificate['text'] ?? '')); ?></div>
                    </div>
                </section>

                <section class="sm-card">
                    <h3 class="sm-title">水印解码工具</h3>
                    <form method="post">
                        <input type="hidden" name="do" value="decode">
                        <textarea class="sm-area" name="decode_text" placeholder="粘贴复制后的文本或 HTML，解析隐藏水印..."><?php echo sm_h($decodeInput); ?></textarea>
                        <div class="sm-row" style="margin-top:10px">
                            <button type="submit" class="sm-btn primary">解析水印</button>
                        </div>
                    </form>

                    <?php if (!empty($decodeRows)): ?>
                        <div class="sm-table-wrap" style="margin-top:12px">
                            <table class="sm-table">
                                <thead>
                                <tr>
                                    <th>来源层</th>
                                    <th>验真</th>
                                    <th>文章</th>
                                    <th>Watermark ID</th>
                                    <th>Site Fingerprint</th>
                                    <th>生成时间</th>
                                    <th>访客ID</th>
                                    <th>用户</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($decodeRows as $row): ?>
                                    <?php $cid = (int) ($row['articleId'] ?? 0); ?>
                                    <tr>
                                        <td><?php echo sm_h($row['source'] ?? '-'); ?></td>
                                        <td>
                                            <?php
                                            $tv = (int) ($row['tokenVersion'] ?? 1);
                                            $ok = (int) ($row['signatureOk'] ?? 0);
                                            $sealed = (int) ($row['sealed'] ?? 0);
                                            if ($tv === 1) {
                                                echo '<span class="sm-k">无签名</span>';
                                            } elseif ($ok === 1) {
                                                echo '<span class="sm-k" style="color:var(--sm-ok)">有效</span>';
                                                if ($sealed === 1) {
                                                    echo '<div class="sm-k">封装</div>';
                                                }
                                            } else {
                                                echo '<span class="sm-k" style="color:var(--sm-bad)">无效</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo $cid > 0 ? ('CID ' . $cid) : '-'; ?>
                                            <?php if ($cid > 0): ?>
                                                <div class="sm-k"><?php echo sm_h($postMap[$cid] ?? ''); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="sm-code"><?php echo sm_h($row['watermarkId'] ?? '-'); ?></span></td>
                                        <td><span class="sm-code"><?php echo sm_h($row['siteFingerprint'] ?? '-'); ?></span></td>
                                        <td><?php echo sm_h(StegoMark_Plugin::formatTime((int) ($row['generatedAt'] ?? 0))); ?></td>
                                        <td><span class="sm-code"><?php echo sm_h($row['visitorId'] ?? '-'); ?></span></td>
                                        <td>
                                            <?php
                                            $uid = (int) ($row['userId'] ?? 0);
                                            $un = trim((string) ($row['username'] ?? ''));
                                            if ($uid > 0 || $un !== '') {
                                                echo '<span class="sm-code">' . sm_h($uid > 0 ? ('UID ' . $uid) : 'UID -') . '</span>';
                                                if ($un !== '') {
                                                    echo '<div class="sm-k">' . sm_h($un) . '</div>';
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="sm-card">
                    <h3 class="sm-title">批量文章水印重建</h3>
                    <form method="post" class="sm-row">
                        <input type="hidden" name="do" value="rebuild">
                        <label><input type="checkbox" name="force" value="1"> 强制重建（覆盖已有 Watermark ID）</label>
                        <button type="submit" class="sm-btn primary">开始重建</button>
                    </form>
                    <?php if (is_array($rebuildResult)): ?>
                        <div class="sm-row" style="margin-top:10px">
                            <span class="sm-code">总计 <?php echo (int) ($rebuildResult['total'] ?? 0); ?></span>
                            <span class="sm-code">更新 <?php echo (int) ($rebuildResult['changed'] ?? 0); ?></span>
                            <span class="sm-code">保留 <?php echo (int) ($rebuildResult['kept'] ?? 0); ?></span>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="sm-card">
                    <h3 class="sm-title">日志中心</h3>
                    <form method="get" class="sm-row">
                        <input type="hidden" name="panel" value="StegoMark/manage.php">
                        <select name="logType">
                            <option value=""<?php echo $logType === '' ? ' selected' : ''; ?>>全部日志</option>
                            <option value="access"<?php echo $logType === 'access' ? ' selected' : ''; ?>>访问</option>
                            <option value="copy"<?php echo $logType === 'copy' ? ' selected' : ''; ?>>复制</option>
                            <option value="decode"<?php echo $logType === 'decode' ? ' selected' : ''; ?>>解码</option>
                            <option value="alert"<?php echo $logType === 'alert' ? ' selected' : ''; ?>>异常告警</option>
                        </select>
                        <button class="sm-btn primary" type="submit">筛选</button>
                    </form>

                    <form method="post" class="sm-row" style="margin-top:10px">
                        <input type="hidden" name="do" value="clear_logs">
                        <select name="log_type">
                            <option value="">清空全部日志</option>
                            <option value="access">仅访问日志</option>
                            <option value="copy">仅复制日志</option>
                            <option value="decode">仅解码日志</option>
                            <option value="alert">仅告警日志</option>
                        </select>
                        <button class="sm-btn danger" type="submit" onclick="return confirm('确认清空日志？');">清空</button>
                    </form>

                    <div class="sm-table-wrap" style="margin-top:12px">
                        <table class="sm-table">
                            <thead>
                            <tr>
                                <th>类型</th>
                                <th>时间</th>
                                <th>CID</th>
                                <th>文章标题</th>
                                <th>Visitor</th>
                                <th>信息</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="6">暂无日志</td></tr>
                            <?php else: ?>
                                <?php foreach ($logs as $row): ?>
                                    <?php
                                    $type = (string) ($row['_type'] ?? ($logType !== '' ? $logType : '-'));
                                    $cid = (int) ($row['cid'] ?? 0);
                                    $title = $cid > 0 ? (string) ($postMap[$cid] ?? '') : '';
                                    $msg = '';
                                    if ($type === 'copy') {
                                        $msg = '长度 ' . (int) ($row['selectionLength'] ?? 0) . '，WMID ' . (string) ($row['wmid'] ?? '');
                                    } elseif ($type === 'decode') {
                                        $msg = '解析数量 ' . (int) ($row['count'] ?? 0);
                                    } elseif ($type === 'alert') {
                                        $msg = (string) ($row['message'] ?? '异常复制') . ' / 次数 ' . (int) ($row['count'] ?? 0);
                                    } else {
                                        $msg = 'URL: ' . (string) ($row['url'] ?? '');
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo sm_h($type); ?></td>
                                        <td><?php echo sm_h(StegoMark_Plugin::formatTime((int) ($row['createdAt'] ?? 0))); ?></td>
                                        <td><?php echo $cid > 0 ? $cid : '-'; ?></td>
                                        <td><?php echo sm_h($title); ?></td>
                                        <td><span class="sm-code"><?php echo sm_h((string) ($row['visitorId'] ?? '-')); ?></span></td>
                                        <td><?php echo sm_h($msg); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="sm-card">
                    <h3 class="sm-title">文章 Watermark ID 快照</h3>
                    <div class="sm-table-wrap">
                        <table class="sm-table">
                            <thead>
                            <tr>
                                <th>CID</th>
                                <th>文章标题</th>
                                <th>Watermark ID</th>
                                <th>创建时间</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($wmMap)): ?>
                                <tr><td colspan="4">暂无数据</td></tr>
                            <?php else: ?>
                                <?php foreach ($wmMap as $row): ?>
                                    <?php $cid = (int) ($row['cid'] ?? 0); ?>
                                    <tr>
                                        <td><?php echo $cid; ?></td>
                                        <td><?php echo sm_h($postMap[$cid] ?? ''); ?></td>
                                        <td><span class="sm-code"><?php echo sm_h($row['wmid'] ?? ''); ?></span></td>
                                        <td><?php echo sm_h(StegoMark_Plugin::formatTime((int) ($row['createdAt'] ?? 0))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>
