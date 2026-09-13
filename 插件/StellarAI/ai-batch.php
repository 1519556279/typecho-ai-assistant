<?php
/**
 * StellarAI 批量生成页（摘要 / 标签，独立布局）
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
$posts = [];
$fields = [];
try {
    $posts = $db->fetchAll($db->select('cid, title, status')->from('table.contents')
        ->where('type = ?', 'post')->order('cid', \Typecho\Db::SORT_ASC));
    foreach ($db->fetchAll($db->select('cid, name')->from('table.fields')
        ->where('name IN (?, ?)', 'sai_summary', 'sai_tags')) as $f) {
        $fields[$f['cid']][$f['name']] = true;
    }
} catch (\Throwable $e) {
}
$assets = rtrim($options->siteUrl, '/') . '/usr/plugins/StellarAI/assets/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>批量生成 - AI 助手</title>
<link rel="stylesheet" href="<?php echo $assets; ?>ai.css?v=20260829">
</head>
<body class="sai-ai-page">
<div class="sai-page">
    <h1>📚 批量生成</h1>
    <div class="sai-intro">
        <div>
            <b>这个页面有什么用？</b> 给选中的文章<b>批量生成摘要或标签</b>：<br>
            · <b>生成摘要</b> → 写入 <code>sai_summary</code> 字段（主题里用 <code>$this-&gt;fields-&gt;sai_summary</code> 显示）<br>
            · <b>生成标签</b> → 写入 <code>sai_tags</code> 字段（逗号分隔，主题里用 <code>$this-&gt;fields-&gt;sai_tags</code> 读取）<br>
            适合给旧文章统一补摘要 / 补标签，逐篇调用 AI 生成，不会一次性占用太多额度。
        </div>
    </div>
    <div class="sai-bar">
        <a class="sai-btn sai-btn-s sai-btn-main" href="ai-console.php">✦ 返回 AI 大屏</a>
        <a class="sai-btn sai-btn-s" href="ai-replies.php">💬 评论审核</a>
        <label><input type="radio" name="sai-batch-op" value="summary" checked> 生成摘要（写入 sai_summary 字段）</label>
        <label><input type="radio" name="sai-batch-op" value="tags"> 生成标签（写入 sai_tags 字段）</label>
        <button type="button" class="sai-btn sai-btn-primary" id="sai-batch-run">开始批量生成</button>
        <button type="button" class="sai-btn sai-btn-s" id="sai-batch-all">全选</button>
        <span class="sai-muted" id="sai-batch-progress"></span>
    </div>
    <?php if (!$posts): ?>
        <div class="sai-empty">没有文章可批量生成。</div>
    <?php else: ?>
    <div class="sai-table-wrap">
    <table class="sai-table">
        <tr><th>勾选</th><th>ID</th><th>标题</th><th>状态</th><th>摘要</th><th>标签</th><th>结果</th></tr>
        <?php foreach ($posts as $p): ?>
        <tr data-cid="<?php echo (int) $p['cid']; ?>">
            <td><input type="checkbox" class="sai-batch-cid" value="<?php echo (int) $p['cid']; ?>"></td>
            <td>#<?php echo (int) $p['cid']; ?></td>
            <td><?php echo htmlspecialchars((string) $p['title']); ?></td>
            <td><?php echo htmlspecialchars((string) $p['status']); ?></td>
            <td><?php echo isset($fields[$p['cid']]['sai_summary']) ? '<span class="sai-tag sai-tag-approved">已有</span>' : '<span class="sai-tag sai-tag-muted">无</span>'; ?></td>
            <td><?php echo isset($fields[$p['cid']]['sai_tags']) ? '<span class="sai-tag sai-tag-approved">已有</span>' : '<span class="sai-tag sai-tag-muted">无</span>'; ?></td>
            <td class="sai-cell-val sai-muted">—</td>
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
var allBtn = document.getElementById('sai-batch-all');
if (allBtn) {
    allBtn.addEventListener('click', function () {
        var boxes = document.querySelectorAll('.sai-batch-cid');
        var allChecked = Array.prototype.every.call(boxes, function (b) { return b.checked; });
        boxes.forEach(function (b) { b.checked = !allChecked; });
    });
}
var runBtn = document.getElementById('sai-batch-run');
if (runBtn) {
    runBtn.addEventListener('click', function () {
        var cids = [];
        document.querySelectorAll('.sai-batch-cid:checked').forEach(function (c) { cids.push(c.value); });
        if (!cids.length) { alert('请先勾选要处理的文章'); return; }
        var op = document.querySelector('input[name=sai-batch-op]:checked').value;
        runBtn.disabled = true;
        var progress = document.getElementById('sai-batch-progress');
        var i = 0;
        function next() {
            if (i >= cids.length) {
                progress.textContent = '全部完成（' + cids.length + ' 篇）';
                runBtn.disabled = false;
                return;
            }
            var cid = cids[i];
            i++;
            var tr = document.querySelector('tr[data-cid="' + cid + '"]');
            var cell = tr.querySelector('.sai-cell-val');
            progress.textContent = '处理中 ' + i + '/' + cids.length + '…';
            cell.textContent = '生成中…';
            cell.className = 'sai-cell-val sai-muted';
            saiPost({ action: 'batch_one', op: op, cid: cid, nonce: SAI_NONCE }, function (d) {
                if (d.ok) {
                    cell.textContent = d.value.slice(0, 80);
                    cell.className = 'sai-cell-val';
                } else {
                    cell.textContent = '⚠️ ' + (d.error || '失败');
                    cell.className = 'sai-cell-val';
                }
                next();
            });
        }
        next();
    });
}
</script>
</body>
</html>
