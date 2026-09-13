<?php

namespace TypechoPlugin\StellarAI;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * StellarAI —— AI 写作助手（独立插件，不依赖任何后台美化）
 *
 * @package StellarAI
 * @author 咔咔
 * @version 2.0.0
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Typecho\Plugin::factory('admin/header.php')->header = __CLASS__ . '::header';
        \Typecho\Plugin::factory('admin/footer.php')->end = __CLASS__ . '::footer';
        \Typecho\Plugin::factory('Widget_Feedback')->finishComment = __CLASS__ . '::onComment';
        self::installTable();
        return 'StellarAI 已启用：后台右下角 AI 助手入口；评论 AI 可在设置中开启';
    }

    public static function deactivate()
    {
    }

    /* 创建评论 AI 队列表（幂等） */
    public static function installTable()
    {
        try {
            $db = \Typecho\Db::get();
            $table = $db->getPrefix() . 'sai_replies';
            if ($db->getAdapterName() === 'Pdo_SQLite') {
                $db->query("CREATE TABLE IF NOT EXISTS {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, coid INTEGER, cid INTEGER,
                    author TEXT, text TEXT, reply TEXT, status TEXT DEFAULT 'pending',
                    ip_hash TEXT, error TEXT, created INTEGER)");
                $db->query("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_coid ON {$table} (coid)");
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS {$table} (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, coid INT UNSIGNED,
                    cid INT UNSIGNED, author VARCHAR(200), text TEXT, reply TEXT,
                    status VARCHAR(20) DEFAULT 'pending', ip_hash VARCHAR(64), error TEXT, created INT)");
                try {
                    $db->query("CREATE UNIQUE INDEX {$table}_coid ON {$table} (coid)");
                } catch (\Throwable $e) {
                    /* 索引已存在 */
                }
            }
        } catch (\Throwable $e) {
            /* 建表失败不阻塞启用 */
        }
    }

    public static function config(Form $form)
    {
        $ai = new \Typecho\Widget\Helper\Form\Element\Select(
            'sa_enable_ai',
            ['1' => '启用', '0' => '停用'],
            '1',
            _t('AI 助手'),
            _t('停用后 AI 对话 / 命令面板 / 润色 / 评论 AI 全部关闭')
        );
        $provider = new \Typecho\Widget\Helper\Form\Element\Select(
            'ai_provider',
            [
                'zhipu' => '智谱 AI（默认免费 glm-4.7-flash）',
                'deepseek' => 'DeepSeek',
                'qwen' => '通义千问（DashScope）',
                'kimi' => 'Kimi（月之暗面）',
                'openai' => 'OpenAI 兼容（自定义）',
            ],
            'zhipu',
            _t('AI 服务商'),
            _t('用于「AI 润色」「AI 大屏」「命令面板」与「评论 AI」')
        );
        $key = new \Typecho\Widget\Helper\Form\Element\Text(
            'ai_key', null, '', _t('API Key'),
            _t('对应服务商的 API Key。智谱可到 open.bigmodel.cn 申请（glm-4.7-flash 免费）')
        );
        $base = new \Typecho\Widget\Helper\Form\Element\Text(
            'ai_base_url', null, '', _t('Base URL（可选）'),
            _t('留空使用服务商默认地址；OpenAI 兼容服务需填写，如 https://api.xxx.com/v1')
        );
        $model = new \Typecho\Widget\Helper\Form\Element\Text(
            'ai_model', null, '', _t('模型名（可选）'),
            _t('留空使用服务商默认模型；也可点「检测可用模型」列出该服务商全部可用模型，点击即可填入')
            . '<br><button type="button" class="btn btn-s" id="sai-detect-models">检测可用模型</button>'
            . '<div id="sai-model-list" style="margin-top:10px"></div>'
        );
        $form->addInput($ai);
        $form->addInput($provider);
        $form->addInput($key);
        $form->addInput($base);
        $form->addInput($model);

        /* ---------- 评论 AI ---------- */
        $replyEnable = new \Typecho\Widget\Helper\Form\Element\Select(
            'sa_enable_reply',
            ['0' => '停用', '1' => '启用'],
            '0',
            _t('评论 AI（自动回复）'),
            _t('开启后访客评论由 AI 自动回复；需「AI 助手」总开关处于启用')
        );
        $replyMode = new \Typecho\Widget\Helper\Form\Element\Select(
            'sa_reply_mode',
            ['manual' => '人工审核', 'auto' => '自动发布'],
            'manual',
            _t('回复模式'),
            _t('人工审核：AI 生成后进审核队列，在「评论审核」页确认发布；自动发布：生成后直接以「回复名称」发布')
        );
        $replyName = new \Typecho\Widget\Helper\Form\Element\Text(
            'sa_reply_name', null, 'AI 助手', _t('回复名称'),
            _t('AI 发布评论时显示的作者名')
        );
        $replyKeywords = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'sa_reply_keywords', null, '', _t('触发关键词（每行一个）'),
            _t('留空=回复所有评论；填写后仅评论包含任一关键词才触发')
        );
        $replyBan = new \Typecho\Widget\Helper\Form\Element\Textarea(
            'sa_reply_ban', null, '', _t('敏感词（每行一个）'),
            _t('命中这些词的评论不回复')
        );
        $replyHour = new \Typecho\Widget\Helper\Form\Element\Text(
            'sa_reply_hour', null, '20', _t('每小时回复上限'),
            _t('0=不限')
        );
        $replyDay = new \Typecho\Widget\Helper\Form\Element\Text(
            'sa_reply_day', null, '100', _t('每天回复上限'),
            _t('0=不限')
        );
        $form->addInput($replyEnable);
        $form->addInput($replyMode);
        $form->addInput($replyName);
        $form->addInput($replyKeywords);
        $form->addInput($replyBan);
        $form->addInput($replyHour);
        $form->addInput($replyDay);

        /* 作者信息 */
        $author = new \Typecho\Widget\Helper\Form\Element\Text(
            'sa_author', null, '', _t('作者'),
            _t('本插件由 <a href="https://github.com/1519556279" target="_blank" rel="noopener">1519556279</a> 独立开发 · 博客 <a href="https://lioip.cn" target="_blank" rel="noopener">lioip.cn</a>')
        );
        $form->addInput($author);
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function header(string $header): string
    {
        $cfg = \Typecho\Widget::widget('Widget_Options')->plugin('StellarAI');
        if (($cfg->sa_enable_ai ?? '1') === '0') {
            return $header;
        }
        $assets = \Typecho\Common::url('usr/plugins/StellarAI/assets',
            \Typecho\Widget::widget('Widget_Options')->siteUrl);
        return $header . '<link rel="stylesheet" href="' . $assets . '/ai.css">' . "\n";
    }

    public static function footer(): void
    {
        $cfg = \Typecho\Widget::widget('Widget_Options')->plugin('StellarAI');
        if (($cfg->sa_enable_ai ?? '1') === '0') {
            return;
        }
        $assets = \Typecho\Common::url('usr/plugins/StellarAI/assets',
            \Typecho\Widget::widget('Widget_Options')->siteUrl);
        $jsV = filemtime(__TYPECHO_ROOT_DIR__ . '/usr/plugins/StellarAI/assets/ai.js');
        echo '<script src="' . $assets . '/ai.js?v=' . $jsV . '" defer></script>' . "\n";
    }

    /* ================= 评论 AI ================= */

    /* finishComment 钩子：审核过滤 + 入队 + 异步消费 */
    public static function onComment($fb)
    {
        try {
            $cfg = \Typecho\Widget::widget('Widget_Options')->plugin('StellarAI');
            if (($cfg->sa_enable_ai ?? '1') === '0' || ($cfg->sa_enable_reply ?? '0') !== '1') {
                return;
            }
            if (($fb->type ?? '') !== 'comment' || ($fb->status ?? '') !== 'approved') {
                return;
            }
            $text = trim((string) $fb->text);
            if ($text === '') {
                return;
            }
            /* 文章作者本人评论不回复（避免自问自答） */
            $authorId = (int) ($fb->authorId ?? 0);
            $ownerId = (int) ($fb->ownerId ?? 0);
            if ($authorId > 0 && $authorId === $ownerId) {
                return;
            }
            /* 敏感词：命中直接跳过 */
            foreach (self::lines((string) ($cfg->sa_reply_ban ?? '')) as $w) {
                if ($w !== '' && mb_strpos($text, $w) !== false) {
                    return;
                }
            }
            /* 触发关键词：留空=回复所有；非空=包含任一关键词才触发 */
            $kws = self::lines((string) ($cfg->sa_reply_keywords ?? ''));
            if ($kws) {
                $hit = false;
                foreach ($kws as $w) {
                    if ($w !== '' && mb_strpos($text, $w) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return;
                }
            }
            $db = \Typecho\Db::get();
            /* 幂等：同一评论只处理一次 */
            if ($db->fetchRow($db->select('id')->from('table.sai_replies')->where('coid = ?', (int) $fb->coid))) {
                return;
            }
            /* 限流（近1小时/近24小时已入队数，0=不限） */
            $now = time();
            $hour = (int) ($cfg->sa_reply_hour ?? 20);
            $day = (int) ($cfg->sa_reply_day ?? 100);
            if (($hour > 0 && self::countSince($now - 3600) >= $hour)
                || ($day > 0 && self::countSince($now - 86400) >= $day)) {
                return;
            }
            $db->query($db->insert('table.sai_replies')->rows([
                'coid' => (int) $fb->coid,
                'cid' => (int) $fb->cid,
                'author' => (string) $fb->author,
                'text' => $text,
                'status' => 'pending',
                'ip_hash' => sha1('sai:' . (string) $fb->ip),
                'created' => $now,
            ]));
            $row = $db->fetchRow($db->select('id')->from('table.sai_replies')->where('coid = ?', (int) $fb->coid));
            if (!$row || !isset($row['id'])) {
                return;
            }
            /* 低价值评论：固定回复直接发布，不消耗 API */
            $low = self::lowValueReply($text);
            if ($low !== null) {
                $ok = self::publishReply((int) $fb->cid, (int) $fb->coid, $low);
                $db->query($db->update('table.sai_replies')->rows([
                    'reply' => $low,
                    'status' => $ok ? 'approved' : 'failed',
                    'error' => $ok ? null : '评论发布失败',
                ])->where('id = ?', (int) $row['id']));
                return;
            }
            /* 普通评论：后台异步消费（不阻塞访客提交） */
            self::fireConsume((int) $row['id']);
        } catch (\Throwable $e) {
            self::log('onComment: ' . $e->getMessage());
        }
    }

    public static function countSince(int $since): int
    {
        try {
            $db = \Typecho\Db::get();
            return (int) $db->fetchObject($db->select('COUNT(*) AS n')
                ->from('table.sai_replies')->where('created > ?', $since))->n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* 低价值评论固定回复（不消耗 API） */
    public static function lowValueReply(string $text): ?string
    {
        foreach (['谢谢', '感谢', '666', '不错', '沙发', '路过', '赞', '顶', '学习了', '收藏', 'mark'] as $w) {
            if (mb_strpos($text, $w) !== false) {
                return '感谢你的留言支持～（StellarAI 自动回复）';
            }
        }
        return null;
    }

    /* 以 AI 身份发布评论（approved），返回是否成功 */
    public static function publishReply(int $cid, int $parent, string $reply): bool
    {
        try {
            $cfg = \Typecho\Widget::widget('Widget_Options')->plugin('StellarAI');
            $name = trim((string) ($cfg->sa_reply_name ?? '')) ?: 'AI 助手';
            $db = \Typecho\Db::get();
            $db->query($db->insert('table.comments')->rows([
                'cid' => $cid,
                'created' => time(),
                'author' => $name,
                'mail' => '',
                'url' => '',
                'ip' => sha1('sai:' . ($_SERVER['REMOTE_ADDR'] ?? '')),
                'agent' => 'StellarAI',
                'text' => $reply,
                'type' => 'comment',
                'status' => 'approved',
                'parent' => $parent,
            ]));
            $db->query($db->update('table.contents')
                ->expression('commentsNum', 'commentsNum + 1')
                ->where('cid = ?', $cid));
            self::log('已发布 AI 评论：cid=' . $cid . ' parent=' . $parent);
            return true;
        } catch (\Throwable $e) {
            self::log('publishReply: ' . $e->getMessage());
            return false;
        }
    }

    /* 评论消费共享密钥：fire-and-forget 本机请求的鉴权凭据（防 nginx+php-fpm 下匿名访客 REMOTE_ADDR=127.0.0.1 盗刷） */
    public static function consumeSecret(): string
    {
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'sai_consume_secret'));
            if ($row && $row['value'] !== '') {
                return (string) $row['value'];
            }
            $secret = bin2hex(random_bytes(24));
            $db->query($db->insert('table.options')->rows(['name' => 'sai_consume_secret', 'user' => 0, 'value' => $secret]));
            return $secret;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /* fire-and-forget 请求 ai.php 消费队列（不等待响应） */
    public static function fireConsume(int $id): void
    {
        try {
            $siteUrl = \Typecho\Widget::widget('Widget_Options')->siteUrl;
            $parts = parse_url(rtrim($siteUrl, '/') . '/usr/plugins/StellarAI/ai.php');
            $host = $parts['host'] ?? '127.0.0.1';
            /* 回环一律走 HTTP：HTTPS 站 443 只监听 TLS，明文连不上；显式非 443 端口（本地测试 8080）保留 */
            $port = (int) ($parts['port'] ?? 0);
            if ($port === 443 || $port === 0) {
                $port = 80;
            }
            $path = $parts['path'] ?? '/';
            $body = json_encode(['action' => 'reply_consume', 'id' => $id], JSON_UNESCAPED_UNICODE);
            $secret = self::consumeSecret();
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
            if (!$fp) {
                self::log('fireConsume 连接失败: ' . $errstr . ' (' . $errno . ')');
            } else {
                fwrite($fp, "POST {$path} HTTP/1.1\r\n"
                    . "Host: {$host}\r\n"
                    . "Content-Type: application/json\r\n"
                    . "X-Sai-Secret: {$secret}\r\n"
                    . "Content-Length: " . strlen($body) . "\r\n"
                    . "Connection: close\r\n\r\n{$body}");
                fclose($fp);
            }
        } catch (\Throwable $e) {
            self::log('fireConsume: ' . $e->getMessage());
        }
    }

    /* 探测并设置 Typecho Cookie 前缀：线上 siteUrl 协议/尾斜杠可能与 rootUrl 不一致，
       登录 cookie 前缀（md5(rootUrl)）无法由 siteUrl 推导，直接从浏览器 cookie 探测最可靠 */
    public static function detectCookiePrefix(): void
    {
        try {
            foreach ($_COOKIE as $k => $v) {
                if (substr($k, -13) === '__typecho_uid') {
                    $prefix = substr($k, 0, -13);
                    $ref = new \ReflectionProperty(\Typecho\Cookie::class, 'prefix');
                    $ref->setAccessible(true);
                    $ref->setValue(null, $prefix);
                    return;
                }
            }
        } catch (\Throwable $e) {
            /* 反射失败走回退 */
        }
        \Typecho\Cookie::setPrefix(rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/'));
    }

    /* 手动鉴权兜底：直接比对 cookie 的 uid/authCode 与用户记录（绕开 Typecho\Cookie 前缀机制，
       兼容 http/https 混合、siteUrl/rootUrl 不一致、反射不可用等场景） */
    public static function manualLogin(): bool
    {
        $uid = null;
        $auth = null;
        foreach ($_COOKIE as $k => $v) {
            if (substr($k, -13) === '__typecho_uid') {
                $uid = $v;
            } elseif (substr($k, -19) === '__typecho_authCode') {
                $auth = $v;
            }
        }
        if ($uid === null || $auth === null) {
            return false;
        }
        try {
            $db = \Typecho\Db::get();
            $user = $db->fetchRow($db->select('uid, authCode, group')
                ->from('table.users')->where('uid = ?', (int) $uid));
            if ($user && hash_equals((string) $user['authCode'], (string) $auth)
                && $user['group'] === 'administrator') {
                return true;
            }
        } catch (\Throwable $e) {
            /* 查询失败视为未登录 */
        }
        return false;
    }

    /* 统一管理员鉴权：Typecho 登录校验 + 手动比对兜底 */
    public static function requireAdmin(): bool
    {
        self::detectCookiePrefix();
        $user = \Typecho\Widget::widget('Widget_User');
        if ($user->hasLogin() && $user->pass('administrator', true)) {
            return true;
        }
        return self::manualLogin();
    }

    /* 按行拆分配置文本（过滤空行：留空配置 = 未设置） */
    public static function lines(string $s): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $s) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /* 生成一条评论回复（供队列消费与重新生成复用），失败抛异常由调用方处理 */
    public static function replyText(string $commentText, string $postTitle): string
    {
        $siteTitle = \Typecho\Widget::widget('Widget_Options')->title ?? '';
        $messages = [
            ['role' => 'system', 'content' => '你是博客「' . $siteTitle . '」的 AI 评论助手。'
                . '用简体中文写一条 20~80 字的友好评论回复，语气自然、有针对性，不要客套话堆砌，不要使用 Markdown 符号。'],
            ['role' => 'user', 'content' => "文章标题：{$postTitle}\n访客评论：{$commentText}\n\n请写回复："],
        ];
        return trim(ai_chat($messages, 400));
    }

    public static function log(string $msg): void
    {
        try {
            $dir = __TYPECHO_ROOT_DIR__ . '/usr/plugins/StellarAI/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($dir . '/sai-' . date('Y-m') . '.log',
                '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
        }
    }
}
