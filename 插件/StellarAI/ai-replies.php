<?php
/**
 * StellarAI 评论审核页（独立布局）
 */
define('__TYPECHO_ADMIN__', true);
require dirname(__DIR__, 3) . '/config.inc.php';

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/StellarAI/Plugin.php';

$options = \Typecho\Widget::widget('Widget_Options');
/* 管理员鉴权（含手动比对兜底，兼容 http/https 混合与 cookie 前缀不一致） */
if (!\TypechoPlugin\StellarAI\Plugin::requireAdmin()) {
    \Typecho\Response::getInstance()->setStatus(302);
    header('Location: ' . rtrim($options->siteUrl, '/') . '/admin/login.php');
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$nonce = empty($_SESSION['sai_nonce']) ? ($_SESSION['sai_nonce'] = bin2hex(random_bytes(16))) : $_SESSION['sai_nonce'];

$db = \Typecho\Db::get();
$rows = [];
$today = 0;
$pendingCount = 0;
try {
    /* pendingCount 全表统计（不限于最近 50 行） */
    $pendingCount = (int) $db->fetchObject($db->select('COUNT(*) AS n')->from('table.sai_replies')
        ->where('reply IS NULL AND status = ?', 'pending'))->n;
    $rows = $db->fetchAll($db->select()->from('table.sai_replies')
        ->order('id', \Typecho\Db::SORT_DESC)->limit(50));
    foreach ($rows as $r) {
        if ((int) $r['created'] > strtotime('today')) {
            $today++;
        }
    }
} catch (\Throwable $e) {
    /* 表不存在（插件未激活建表） */
}
$cfg = $options->plugin('StellarAI');
$assets = rtrim($options->siteUrl, '/') . '/usr/plugins/StellarAI/assets/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>评论审核 - AI 助手</title>
<link rel="stylesheet" href="<?php echo $assets; ?>ai.css?v=20260829">
</head>
<body class="sai-ai-page">
<div class="sai-page">
    <h1>💬 评论 AI 审核</h1>
    <div class="sai-intro">
        <div>
            <b>这个页面有什么用？</b> 管理「评论 AI」生成的回复：<br>
            · 评论 AI 开启后，访客评论会由 AI 生成回复——<b>人工审核模式</b>下回复先进这里，你确认后才发布<br>
            · 可以 <b>通过 / 拒绝 / 重新生成 / 删除</b>；「处理待生成队列」会立即生成排队中的回复<br>
            · <b>自动发布模式</b>下不经过这里，直接以「回复名称」发布（可在插件设置中切换）
        </div>
    </div>
    <div class="sai-bar">
        <a class="sai-btn sai-btn-s sai-btn-main" href="ai-console.php">✦ 返回 AI 大屏</a>
        <a class="sai-btn sai-btn-s" href="ai-batch.php">📚 批量生成</a>
        <button type="button" class="sai-btn sai-btn-s" id="sai-consume-btn">⚡ 处理待生成队列（<?php echo $pendingCount; ?>）</button>
        <span class="sai-muted">今日已处理 <?php echo $today; ?> 条 · 模式：<?php echo ($cfg->sa_reply_mode ?? 'manual') === 'auto' ? '自动发布' : '人工审核'; ?></span>
    </div>
    <?php if (!$rows): ?>
        <div class="sai-empty">暂无记录。开启「评论 AI」后，访客评论会自动进入这里。</div>
    <?php else: ?>
    <div class="sai-table-wrap">
    <table class="sai-table">
        <tr><th>ID</th><th>文章</th><th>访客评论</th><th>AI 回复</th><th>状态</th><th>操作</th></tr>
        <?php foreach ($rows as $r):
            $st = (string) $r['status'];
            $hasReply = $r['reply'] !== null && $r['reply'] !== '';
            $cls = $st === 'approved' ? 'sai-tag-approved'
                : (in_array($st, ['rejected', 'failed'], true) ? 'sai-tag-rejected'
                : ($st === 'pending' ? ($hasReply ? 'sai-tag-pending' : 'sai-tag-muted') : 'sai-tag-muted'));
            $stLabel = $st === 'pending' ? ($hasReply ? '待审核' : '生成中')
                : ($st === 'approved' ? '已发布' : ($st === 'rejected' ? '已拒绝' : ($st === 'failed' ? '失败' : $st)));
            $postTitle = '';
            try {
                $p = $db->fetchRow($db->select('title')->from('table.contents')->where('cid = ?', (int) $r['cid']));
                $postTitle = $p['title'] ?? ('#' . $r['cid']);
            } catch (\Throwable $e) {
            }
        ?>
        <tr data-id="<?php echo (int) $r['id']; ?>">
            <td>#<?php echo (int) $r['id']; ?></td>
            <td><?php echo htmlspecialchars($postTitle); ?><br><span class="sai-muted"><?php echo date('m-d H:i', (int) $r['created']); ?></span></td>
            <td><b><?php echo htmlspecialchars((string) $r['author']); ?></b><br><?php echo nl2br(htmlspecialchars(mb_substr((string) $r['text'], 0, 120))); ?></td>
            <td class="sai-reply-cell"><?php
                if ($hasReply) {
                    echo nl2br(htmlspecialchars(mb_substr((string) $r['reply'], 0, 200)));
                } else {
                    echo '<span class="sai-muted">' . ($r['error'] !== null && $r['error'] !== '' ? htmlspecialchars((string) $r['error']) : '生成中…') . '</span>';
                }
            ?></td>
            <td><span class="sai-tag <?php echo $cls; ?>"><?php echo $stLabel; ?></span></td>
            <td>
                <div class="sai-actions">
                    <?php if ($st === 'pending' && $hasReply): ?>
                        <button type="button" class="sai-btn sai-btn-s" data-op="approve">通过</button>
                        <button type="button" class="sai-btn sai-btn-s" data-op="reject">拒绝</button>
                    <?php endif; ?>
                    <?php if ($st !== 'approved'): ?>
                        <button type="button" class="sai-btn sai-btn-s" data-op="regenerate">重新生成</button>
                    <?php endif; ?>
                    <button type="button" class="sai-btn sai-btn-s" data-op="delete">删除</button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
<script>
var SAI_NONCE = <?php echo json_encode($nonce); ?>;
function saiPost(body, cb) {
    fetch('./ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(function (r) { return r.json(); }).then(cb);
}
document.querySelectorAll('[data-op]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var tr = btn.closest('tr');
        if (!tr) return;
        var id = tr.getAttribute('data-id');
        var op = btn.getAttribute('data-op');
        btn.disabled = true;
        saiPost({ action: 'reply_review', id: id, op: op, nonce: SAI_NONCE }, function (d) {
            if (d.ok) { location.reload(); }
            else { alert(d.error || '操作失败'); btn.disabled = false; }
        });
    });
});
var consumeBtn = document.getElementById('sai-consume-btn');
if (consumeBtn) {
    consumeBtn.addEventListener('click', function () {
        consumeBtn.disabled = true;
        consumeBtn.textContent = '处理中…';
        saiPost({ action: 'reply_consume' }, function (d) {
            if (d.ok) { location.reload(); }
            else { alert(d.error || '处理失败'); consumeBtn.disabled = false; consumeBtn.textContent = '重试'; }
        });
    });
}
</script>
</body>
</html>
