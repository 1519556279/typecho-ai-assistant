<?php
/**
 * StellarAI 大屏（独立页面，不依赖任何后台美化模板）
 */
define('__TYPECHO_ADMIN__', true);
require dirname(__DIR__, 3) . '/config.inc.php';
require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/StellarAI/Plugin.php';

$options = \Typecho\Widget::widget('Widget_Options');
/* 管理员鉴权（含手动比对兜底，兼容 http/https 混合与 cookie 前缀不一致） */
if (!\TypechoPlugin\StellarAI\Plugin::requireAdmin()) {
    header('Location: ' . rtrim($options->siteUrl, '/') . '/admin/login.php');
    exit;
}
$aiCfg = $options->plugin('StellarAI');
if (($aiCfg->sa_enable_ai ?? '1') === '0') {
    exit('AI 助手已停用（可在插件设置中重新启用）');
}
$assets = rtrim($options->siteUrl, '/') . '/usr/plugins/StellarAI/assets/';

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI 助手 - <?php $options->title(); ?></title>
<script>
    try { var t = localStorage.getItem('stellar-admin-theme'); if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.setAttribute('data-theme', 'dark'); } catch (e) {}
</script>
<link rel="stylesheet" href="<?php echo $assets; ?>ai.css?v=20260829">
</head>
<body class="sai-ai-page">
<div class="sai-shell">
    <header class="sai-topbar">
        <div class="sai-topbar-left">
            <span class="sai-logo">✦</span>
            <h1>AI 助手</h1>
            <span class="sai-ai-status" id="sai-ai-status"></span>
        </div>
        <div class="sai-topbar-right">
            <button type="button" class="sai-btn sai-btn-s" id="sai-guide-toggle" title="显示 / 隐藏左右引导">❓ 引导</button>
            <button type="button" class="sai-btn sai-btn-s" id="sai-ai-test">🔌 测试连接</button>
            <a class="sai-btn sai-btn-s" href="ai-replies.php">💬 评论审核</a>
            <a class="sai-btn sai-btn-s" href="ai-batch.php">📚 批量生成</a>
            <a class="sai-btn sai-btn-s" href="https://github.com/1519556279" target="_blank" rel="noopener" title="作者咔咔的 GitHub 主页">
                <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
                咔咔
            </a>
            <a class="sai-btn sai-btn-s sai-btn-main" href="<?php echo rtrim($options->siteUrl, '/') . '/admin/'; ?>">← 返回后台</a>
        </div>
    </header>

    <div class="sai-body">
        <aside class="sai-guide" id="sai-guide-left">
            <button type="button" class="sai-guide-close" data-close="sai-guide-left" title="关闭引导">×</button>
            <div class="sai-guide-inner">
                <h3>✨ 我能做什么</h3>
                <ul>
                    <li>📝 生成文章（给主题或要点即可）</li>
                    <li>✨ 润色 / 改写 / 翻译</li>
                    <li>📄 提取摘要、推荐标签</li>
                    <li>📊 查看站点统计</li>
                    <li>📰 发布 / 修改 / 删除文章</li>
                </ul>
            </div>
        </aside>
        <main class="sai-chat-wrap">
            <div class="sai-welcome" id="sai-welcome">
                <div class="sai-welcome-logo">✦</div>
                <h2>你好，我是 AI 助手</h2>
                <p class="sai-welcome-desc">帮你写作、润色、翻译，还能直接操作后台——点下面的示例，或直接输入你的需求。</p>
                <div class="sai-welcome-grid">
                    <button type="button" class="sai-welcome-item" data-prompt="写一篇关于 Typecho 的文章，包含标题、小标题和要点">
                        <b>📝 生成文章</b><span>给主题或要点，生成完整 Markdown</span>
                    </button>
                    <button type="button" class="sai-welcome-item" data-prompt="请帮我润色下面这段文字，保持原意：">
                        <b>✨ 润色改写</b><span>优化表达，保持 Markdown 结构</span>
                    </button>
                    <button type="button" class="sai-welcome-item" data-prompt="请为下面这篇文章提取一段 50 字以内的摘要：">
                        <b>📄 提取摘要</b><span>一键生成文章摘要</span>
                    </button>
                    <button type="button" class="sai-welcome-item" data-prompt="请为下面这篇文章推荐 5 个中文标签（逗号分隔）：">
                        <b>🏷️ 标签建议</b><span>推荐标签，可直接使用</span>
                    </button>
                    <button type="button" class="sai-welcome-item" data-prompt="请把下面这篇文章翻译成英文：">
                        <b>🌐 翻译</b><span>中英文互译，保留格式</span>
                    </button>
                    <button type="button" class="sai-welcome-item" data-prompt="查看站点统计">
                        <b>📊 站点统计</b><span>用自然语言查看后台数据</span>
                    </button>
                </div>
                <p class="sai-welcome-tip">💡 也可以直接说「发布一篇标题为…的文章」让我操作后台 · 对话有上下文记忆，可连续追问</p>
            </div>
            <div class="sai-chat" id="sai-chat"></div>
        </main>
        <aside class="sai-guide" id="sai-guide-right">
            <button type="button" class="sai-guide-close" data-close="sai-guide-right" title="关闭引导">×</button>
            <div class="sai-guide-inner">
                <h3>💡 使用技巧</h3>
                <ul>
                    <li>⌨️ Ctrl+Enter 快速发送</li>
                    <li>🧠 对话有上下文记忆，可连续追问</li>
                    <li>⚡ 说「发布 / 修改 / 统计」即可操作后台</li>
                    <li>💬 评论自动回复在「评论审核」页确认</li>
                    <li>📚 批量摘要 / 标签在「批量生成」页</li>
                </ul>
            </div>
        </aside>
    </div>

    <footer class="sai-composer">
        <div class="sai-chips">
            <button type="button" class="sai-cmd-chip" data-prompt="请为「搭建个人博客」写一篇完整的 Markdown 文章，包含标题、小标题和要点。">📝 生成文章</button>
            <button type="button" class="sai-cmd-chip" data-prompt="请帮我润色下面这段文字，保持原意：">✨ 润色</button>
            <button type="button" class="sai-cmd-chip" data-prompt="请为下面这篇文章提取一段 50 字以内的摘要：">📄 提取摘要</button>
            <button type="button" class="sai-cmd-chip" data-prompt="请为下面这篇文章推荐 5 个中文标签（逗号分隔）：">🏷️ 标签建议</button>
            <button type="button" class="sai-cmd-chip" data-prompt="请把下面这篇文章翻译成英文：">🌐 翻译英文</button>
        </div>
        <div class="sai-input-row">
            <textarea id="sai-chat-text" rows="2" placeholder="输入你的需求，Ctrl+Enter 发送…"></textarea>
            <button type="button" class="sai-btn sai-btn-primary" id="sai-chat-send">发送</button>
        </div>
        <p class="sai-footnote">对话有上下文记忆，可连续追问 · 也可以直接让我发布 / 修改 / 删除文章、查看统计</p>
    </footer>
</div>
<script src="<?php echo $assets; ?>ai.js?v=20260829"></script>
</body>
</html>
