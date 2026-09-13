/* StellarAI —— AI 助手（独立插件）：AI 大屏 / 写文章润色 / 模型检测 */
(function () {
    'use strict';
    var doc = document;

    function toast(msg, type) {
        var t = doc.createElement('div');
        t.className = 'sai-toast ' + (type || 'notice');
        /* textContent 防 LLM 内容注入 HTML */
        t.innerHTML = '<ul></ul>';
        t.querySelector('ul').textContent = msg;
        doc.body.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity .3s';
            setTimeout(function () { t.remove(); }, 320);
        }, 2600);
    }

    /* ---------- 工具 ---------- */
    function aiUrl() {
        /* AI 大屏与 ai.php 同目录；后台页面则从 /admin/ 上跳两级到站点根 */
        if (/ai-console\.php/.test(location.pathname)) {
            return new URL('./ai.php', location.href).href;
        }
        return new URL('../../usr/plugins/StellarAI/ai.php', location.href).href;
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function aiFetch(body, timeoutMs) {
        var ctrl = new AbortController();
        var timer = setTimeout(function () { ctrl.abort(); }, timeoutMs || 90000);
        return fetch(aiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: ctrl.signal
        }).then(function (r) { clearTimeout(timer); return r.json(); })
          .catch(function (e) {
              clearTimeout(timer);
              if (e && e.name === 'AbortError') throw new Error('请求超时，请重试');
              throw e;
          });
    }

    /* ---------- AI 大屏（ai-console.php） ---------- */
    function bindAiConsole() {
        if (!/ai-console\.php/.test(location.pathname)) return;

        var status = doc.getElementById('sai-ai-status');
        function ping() {
            if (status) { status.textContent = '检查连接中…'; status.className = 'sai-ai-status sai-st-busy'; }
            aiFetch({ action: 'ping' }, 15000).then(function (d) {
                if (!status) return;
                if (d.ok) {
                    status.textContent = '已连接：' + (d.provider || '') + ' / ' + (d.model || '');
                    status.className = 'sai-ai-status sai-st-ok';
                } else {
                    status.textContent = '未配置：' + (d.error || '');
                    status.className = 'sai-ai-status sai-st-err';
                }
            }).catch(function () {
                if (status) { status.textContent = '连接失败'; status.className = 'sai-ai-status sai-st-err'; }
            });
        }
        var testBtn = doc.getElementById('sai-ai-test');
        if (testBtn) testBtn.addEventListener('click', ping);
        ping();

        /* 对话 */
        var chat = doc.getElementById('sai-chat');
        var chatText = doc.getElementById('sai-chat-text');
        var chatSend = doc.getElementById('sai-chat-send');
        /* 新版布局滚动容器是 .sai-chat-wrap（.sai-chat 不滚动） */
        var scrollBox = doc.querySelector('.sai-chat-wrap');
        function scrollBottom() {
            var box = scrollBox || chat;
            box.scrollTop = box.scrollHeight;
        }
        function mdRender(src) {
            var s = escapeHtml(String(src || ''));
            var blocks = [];
            s = s.replace(/```([\s\S]*?)```/g, function (_, c) {
                blocks.push('<pre><code>' + c + '</code></pre>');
                return '\u0000' + (blocks.length - 1) + '\u0000';
            });
            s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
            s = s.replace(/^### (.*)$/gm, '<h3>$1</h3>');
            s = s.replace(/^## (.*)$/gm, '<h2>$1</h2>');
            s = s.replace(/^# (.*)$/gm, '<h1>$1</h1>');
            s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
            s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
            s = s.replace(/^&gt; (.*)$/gm, '<blockquote>$1</blockquote>');
            s = s.replace(/((?:^[-*] .*(?:\n|$))+)/gm, function (m) {
                return '<ul>' + m.trim().split('\n').map(function (l) {
                    return '<li>' + l.replace(/^[-*] /, '') + '</li>';
                }).join('') + '</ul>';
            });
            s = s.replace(/^---+$/gm, '<hr>');
            /* 先替换换行（代码块占位符不含 \n，不受影响），最后还原代码块（保留 <pre> 内换行语义） */
            s = s.replace(/\n/g, '<br>');
            s = s.replace(/\u0000(\d+)\u0000/g, function (_, i) { return blocks[+i]; });
            return '<div class="sai-md">' + s + '</div>';
        }
        /* 对话历史：localStorage 持久化，超上限自动删最旧 */
        var HISTORY_KEY = 'sa-ai-history';
        var HISTORY_MAX = 40;
        function loadHistory() {
            try {
                var h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
                return Array.isArray(h) ? h : [];
            } catch (e) { return []; }
        }
        function saveHistory() {
            try {
                localStorage.setItem(HISTORY_KEY, JSON.stringify(messages.slice(-HISTORY_MAX)));
            } catch (e) {}
        }
        var messages = loadHistory();
        var HISTORY_SHOW = 10;
        var showAll = false;
        function renderHistory() {
            chat.innerHTML = '';
            var welcome = doc.getElementById('sai-welcome');
            if (welcome) welcome.style.display = messages.length ? 'none' : '';
            if (messages.length > HISTORY_SHOW && !showAll) {
                var moreBtn = doc.createElement('button');
                moreBtn.type = 'button';
                moreBtn.className = 'sai-btn sai-btn-s sai-copy-btn';
                moreBtn.style.alignSelf = 'center';
                moreBtn.textContent = '↑ 查看更早的对话（' + (messages.length - HISTORY_SHOW) + ' 条）';
                moreBtn.addEventListener('click', function () {
                    showAll = true;
                    renderHistory();
                    scrollBottom();
                });
                chat.appendChild(moreBtn);
            }
            var list = showAll ? messages : messages.slice(-HISTORY_SHOW);
            list.forEach(function (m) {
                if (m && m.role === 'user') {
                    addMsg('user', String(m.content));
                } else if (m && m.role === 'assistant') {
                    addMsg('bot', String(m.content));
                }
            });
            if (chat) scrollBottom();
        }
        renderHistory();
        function addMsg(role, text, raw) {
            var div = doc.createElement('div');
            div.className = 'sai-chat-msg ' + (role === 'user' ? 'me' : 'bot');
            div.innerHTML = role === 'user'
                ? '<div class="sai-chat-bubble me">' + escapeHtml(text) + '</div>'
                : '<span class="sai-chat-avatar">✦</span><div class="sai-chat-bubble">' + (raw ? text : mdRender(text)) + '</div>';
            chat.appendChild(div);
            scrollBottom();
            return div;
        }
        function sendChat() {
            var text = chatText.value.trim();
            if (!text) return;
            if (chatSend) chatSend.disabled = true;
            addMsg('user', text);
            messages.push({ role: 'user', content: text });
            saveHistory();
            chatText.value = '';
            doChat(messages[messages.length - 1].content, null);
        }
        /* 执行类指令（需解析 JSON 动作）走非流式；普通对话/写作走 SSE 流式 */
        var EXEC_RE = /发布|创建|删除|修改|改成|列出|统计|更新|改为|重命名|恢复/;
        function doChat(userText, retryWait) {
            if (retryWait) retryWait.remove();
            var wait = addMsg('bot', '<span class="sai-dots"><span></span><span></span><span></span></span>', true);
            if (!EXEC_RE.test(userText)) {
                streamChat(wait, userText);
                return;
            }
            var rateLimitRetries = 0;
            function doChatInner() {
                aiFetch({ action: 'chat', messages: messages }).then(function (d) {
                    if (!d.ok) {
                        if (d.error && /限流|繁忙/.test(d.error) && rateLimitRetries < 2) {
                            rateLimitRetries++;
                            var sec = 10, bubble = wait.querySelector('.sai-chat-bubble');
                            bubble.innerHTML = '⏳ AI 有点忙（限流），<b>' + sec + '</b> 秒后自动重试…';
                            var iv = setInterval(function () {
                                sec--;
                                bubble.innerHTML = '⏳ AI 有点忙（限流），<b>' + sec + '</b> 秒后自动重试…';
                                if (sec <= 0) { clearInterval(iv); doChatInner(); }
                            }, 1000);
                            return;
                        }
                        wait.querySelector('.sai-chat-bubble').innerHTML = '⚠️ ' + escapeHtml(d.error || '出错了');
                        addRetry(wait, userText);
                        if (chatSend) chatSend.disabled = false;
                        return;
                    }
                var results = d && d.results;
                if (results && results.length) {
                    var html = results.map(function (r) {
                        var icon = r.ok ? '✅' : '⚠️';
                        var extra = r.list ? '<ul class="sai-cmd-list">' + r.list.map(function (p) {
                            return '<li><b>#' + escapeHtml(p.cid) + '</b> ' + escapeHtml(p.title) + ' <span class="sai-cmd-st">' + escapeHtml(p.status) + '</span></li>';
                        }).join('') + '</ul>' : '';
                        if (r.content) {
                            extra += '<div class="sai-cmd-content">' + escapeHtml(String(r.content).slice(0, 300)) + '</div>';
                        }
                        return '<div class="sai-cmd-item">' + icon + ' ' + escapeHtml(r.action || '') + '：' + escapeHtml(r.detail || '') + '</div>' + extra;
                    }).join('');
                    wait.querySelector('.sai-chat-bubble').innerHTML = html;
                    scrollBottom(); /* 结果填充后自动滚到底部 */
                    messages.push({ role: 'assistant', content: results.map(function (r) { return r.detail || ''; }).join('\n') });
                    saveHistory();
                    var okCount = results.filter(function (r) { return r.ok; }).length;
                    var failCount = results.length - okCount;
                    if (failCount === 0) {
                        toast('✅ 已完成：' + (results[0] && results[0].detail || '操作成功'), 'success');
                    } else if (okCount > 0) {
                        toast('✅ ' + okCount + ' 项成功，⚠️ ' + failCount + ' 项失败', 'error');
                    } else {
                        toast('⚠️ 操作失败：' + (results[0] && results[0].detail || '未知原因'), 'error');
                    }
                    return;
                }
                /* 正常分析/聊天回复 */
                var replyHtml = '';
                if (d.web) {
                    replyHtml = '🔗 已联网查看：<a href="' + escapeHtml(d.web.url) + '" target="_blank" rel="noopener">' + escapeHtml(d.web.title) + '</a><br>';
                }
                var bubble = wait.querySelector('.sai-chat-bubble');
                bubble.innerHTML = replyHtml + mdRender(d.reply || '');
                bubble.classList.add('sa-typing');
                setTimeout(function () { bubble.classList.remove('sa-typing'); }, 600);
                scrollBottom();
                messages.push({ role: 'assistant', content: d.reply || '' });
                saveHistory();
                if (d.reply && /^# /.test(d.reply.trim())) {
                    var copyBtn = doc.createElement('button');
                    copyBtn.type = 'button';
                    copyBtn.className = 'sai-btn sai-btn-s sai-copy-btn';
                    copyBtn.textContent = '复制全文';
                    copyBtn.addEventListener('click', function () {
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(d.reply).then(function () { toast('已复制 ✓', 'success'); });
                        } else {
                            var ta = doc.createElement('textarea');
                            ta.value = d.reply;
                            doc.body.appendChild(ta);
                            ta.select();
                            doc.execCommand('copy');
                            ta.remove();
                            toast('已复制 ✓', 'success');
                        }
                    });
                    wait.querySelector('.sai-chat-bubble').appendChild(copyBtn);
                }
                if (chatSend) chatSend.disabled = false;
            }).catch(function (e) {
                wait.querySelector('.sai-chat-bubble').innerHTML = '⚠️ ' + escapeHtml(e && e.message ? e.message : '网络请求失败');
                addRetry(wait, userText);
                if (chatSend) chatSend.disabled = false;
            });
            }
            doChatInner();
        }
        /* SSE 流式对话：打字机效果，支持停止，结束后渲染 Markdown */
        function streamChat(wait, userText) {
            var bubble = wait.querySelector('.sai-chat-bubble');
            var full = '';
            var started = false;
            var ctrl = new AbortController();
            /* 停止生成按钮（等待期间与生成期间都可点） */
            var stopBtn = doc.createElement('button');
            stopBtn.type = 'button';
            stopBtn.className = 'sai-btn sai-btn-s sai-stop-btn';
            stopBtn.textContent = '⏹ 停止';
            stopBtn.addEventListener('click', function () { ctrl.abort(); });
            wait.appendChild(stopBtn);
            function releaseSend() { if (chatSend) chatSend.disabled = false; }
            function finish(reply) {
                if (stopBtn.parentNode) stopBtn.remove();
                var text = typeof reply === 'string' ? reply : full;
                if (text) {
                    bubble.classList.remove('sa-stream');
                    bubble.innerHTML = mdRender(text);
                    messages.push({ role: 'assistant', content: text });
                    saveHistory();
                } else {
                    bubble.innerHTML = '⚠️ 没有生成内容，请重试';
                    addRetry(wait, userText);
                }
                scrollBottom();
                releaseSend();
            }
            fetch(aiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'chat', messages: messages, stream: 1 }),
                signal: ctrl.signal
            }).then(function (r) {
                if (!r.ok || !r.body) throw new Error('HTTP ' + r.status);
                var reader = r.body.getReader();
                var decoder = new TextDecoder();
                var buf = '';
                var finished = false;
                function pump() {
                    return reader.read().then(function (res) {
                        if (res.done) { if (!finished) { finished = true; finish(); } return; }
                        buf += decoder.decode(res.value, { stream: true });
                        var idx;
                        while ((idx = buf.indexOf('\n')) >= 0) {
                            var line = buf.slice(0, idx).trim();
                            buf = buf.slice(idx + 1);
                            if (line.indexOf('data:') !== 0) continue;
                            var ev = null;
                            try { ev = JSON.parse(line.slice(5).trim()); } catch (e) { continue; }
                            if (!ev) continue;
                            if (ev.error) throw new Error(ev.error);
                            if (ev.d) {
                                if (!started) {
                                    started = true;
                                    bubble.classList.add('sa-stream');
                                    bubble.textContent = '';
                                }
                                full += ev.d;
                                bubble.textContent = full;
                                scrollBottom();
                            }
                            if (ev.done) { if (!finished) { finished = true; finish(ev.reply); } return; }
                        }
                        return pump();
                    });
                }
                return pump();
            }).catch(function (e) {
                if (stopBtn.parentNode) stopBtn.remove();
                if (e && e.name === 'AbortError') {
                    /* 用户主动停止：保留已生成部分 */
                    if (full) {
                        bubble.classList.remove('sa-stream');
                        bubble.innerHTML = mdRender(full);
                        messages.push({ role: 'assistant', content: full });
                        saveHistory();
                        scrollBottom();
                    } else {
                        bubble.innerHTML = '⏹ 已停止生成';
                    }
                } else {
                    bubble.innerHTML = '⚠️ ' + escapeHtml(e && e.message ? e.message : '网络请求失败');
                    addRetry(wait, userText);
                }
                releaseSend();
            });
        }
        function addRetry(wait, userText) {
            var retry = doc.createElement('button');
            retry.type = 'button';
            retry.className = 'sai-btn sai-btn-s sai-copy-btn';
            retry.textContent = '重试';
            retry.addEventListener('click', function () { doChat(userText, wait); });
            wait.querySelector('.sai-chat-bubble').appendChild(retry);
        }
        if (chatSend && chatText) {
            chatSend.addEventListener('click', sendChat);
            chatText.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) sendChat();
            });
            doc.querySelectorAll('.sai-chips .sai-cmd-chip, .sai-chat-tools .sai-cmd-chip').forEach(function (c) {
                c.addEventListener('click', function () {
                    chatText.value = c.getAttribute('data-prompt') + '\n\n';
                    chatText.focus();
                });
            });
        }

        /* 欢迎引导卡片：点击示例填入输入框 */
        var welcome = doc.getElementById('sai-welcome');
        if (welcome) {
            welcome.querySelectorAll('[data-prompt]').forEach(function (c) {
                c.addEventListener('click', function () {
                    chatText.value = c.getAttribute('data-prompt') + '\n\n';
                    chatText.focus();
                });
            });
        }

        /* 左右引导栏：顶栏开关 + × 关闭，localStorage 记忆，可随时重新打开 */
        var guideToggle = doc.getElementById('sai-guide-toggle');
        function applyGuides() {
            var hidden = false;
            try { hidden = localStorage.getItem('sai-guide-hidden') === '1'; } catch (e) {}
            doc.querySelectorAll('.sai-guide').forEach(function (g) {
                g.style.display = hidden ? 'none' : '';
            });
            if (guideToggle) {
                /* 文字固定不切换（避免按钮宽度跳动），状态用高亮色表示 */
                guideToggle.classList.toggle('sai-guide-on', !hidden);
                guideToggle.setAttribute('data-state', hidden ? 'off' : 'on');
            }
        }
        applyGuides();
        if (guideToggle) {
            guideToggle.addEventListener('click', function () {
                var hidden = false;
                try { hidden = localStorage.getItem('sai-guide-hidden') === '1'; } catch (e) {}
                try { localStorage.setItem('sai-guide-hidden', hidden ? '0' : '1'); } catch (e) {}
                applyGuides();
            });
        }
        doc.querySelectorAll('.sai-guide-close').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try { localStorage.setItem('sai-guide-hidden', '1'); } catch (e) {}
                applyGuides();
            });
        });
    }

    /* ---------- 模型检测（插件设置页） ---------- */
    function bindModelDetect() {
        var btn = doc.getElementById('sai-detect-models');
        var box = doc.getElementById('sai-model-list');
        if (!btn || !box || btn.dataset.saBound) return;
        btn.dataset.saBound = '1';
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = '检测中…';
            box.innerHTML = '<p class="sai-cmd-wait">正在获取可用模型…</p>';
            aiFetch({ action: 'models' }, 30000).then(function (d) {
                btn.disabled = false;
                btn.textContent = '重新检测';
                if (!d.ok) { box.innerHTML = '<span class="sai-cmd-err">⚠️ ' + escapeHtml(d.error || '检测失败') + '</span>'; return; }
                var list = d.models || [];
                if (!list.length) { box.innerHTML = '<span class="sai-cmd-err">未返回任何模型</span>'; return; }
                box.innerHTML = list.map(function (m) {
                    return '<button type="button" class="sai-model-chip" data-model="' + escapeHtml(m) + '">' + escapeHtml(m) + '</button>';
                }).join('');
                box.querySelectorAll('.sai-model-chip').forEach(function (c) {
                    c.addEventListener('click', function () {
                        var input = doc.getElementById('ai_model');
                        if (input) { input.value = c.getAttribute('data-model'); input.dispatchEvent(new Event('input')); }
                        box.querySelectorAll('.sai-model-chip').forEach(function (x) { x.classList.remove('sa-active'); });
                        c.classList.add('sa-active');
                        toast('已填入模型名，记得保存设置', 'success');
                    });
                });
            }).catch(function (e) {
                btn.disabled = false;
                btn.textContent = '重新检测';
                box.innerHTML = '<span class="sai-cmd-err">⚠️ ' + escapeHtml(e && e.message ? e.message : '检测失败') + '</span>';
            });
        });
    }

    /* ---------- 浮动 AI 按钮（所有后台页右下角） ---------- */
    function bindFloating() {
        if (doc.getElementById('sai-ai-fab')) return;
        var fab = doc.createElement('button');
        fab.id = 'sai-ai-fab';
        fab.type = 'button';
        fab.title = 'AI 助手';
        fab.setAttribute('aria-label', '打开 AI 助手');
        fab.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-1.9a2 2 0 0 0 1.3-1.3Z"/></svg>';
        fab.addEventListener('click', function () {
            location.href = new URL('../../usr/plugins/StellarAI/ai-console.php', location.href).href;
        });
        doc.body.appendChild(fab);
    }

    /* ---------- 写文章页润色按钮（Typecho 官方编辑器） ---------- */
    function bindEditorPolish() {
        if (!/write-(post|page)\.php/.test(location.pathname)) return;
        var ta = doc.getElementById('text');
        if (!ta || ta.dataset.saPolish) return;
        ta.dataset.saPolish = '1';
        /* 在编辑器上方插入按钮条 */
        var bar = doc.createElement('div');
        bar.className = 'sai-polish-bar';
        bar.innerHTML =
            '<button type="button" class="sai-btn sai-btn-s" data-mode="通用">✨ AI 润色</button>' +
            '<button type="button" class="sai-btn sai-btn-s" data-mode="auto">🪄 智能优化</button>' +
            '<span class="sai-polish-hint">选中正文片段或直接点击，AI 优化表达并保持 Markdown 结构</span>';
        var insertTarget = ta.parentElement;
        /* 尽量插到编辑区顶部（Typecho 编辑器容器 #text 的父级前） */
        if (insertTarget) insertTarget.insertBefore(bar, insertTarget.firstChild);
        var undo = null;
        function polish(mode, label) {
            var sel = ta.value.substring(ta.selectionStart, ta.selectionEnd);
            var text = sel.trim() || ta.value.trim();
            if (!text) { toast('编辑器里还没有内容', 'error'); return; }
            undo = { v: ta.value, s: ta.selectionStart, e: ta.selectionEnd };
            aiFetch({ action: 'polish', text: text, mode: mode }).then(function (d) {
                if (!d.ok) { toast(d.error || '处理失败', 'error'); return; }
                if (!confirm(label + '完成，是否替换当前内容？')) return;
                if (sel.trim()) {
                    var start = ta.selectionStart, end = ta.selectionEnd;
                    ta.value = ta.value.substring(0, start) + d.text + ta.value.substring(end);
                    ta.setSelectionRange(start, start + d.text.length);
                } else {
                    ta.value = d.text;
                }
                ta.dispatchEvent(new Event('input'));
                toast(label + '完成 ✓', 'success');
            }).catch(function (e) {
                toast(e && e.message ? e.message : '网络请求失败', 'error');
            });
        }
        bar.querySelectorAll('button').forEach(function (b) {
            b.addEventListener('click', function () { polish(b.getAttribute('data-mode'), b.textContent.trim()); });
        });
        /* Ctrl+Z 回滚润色前的原文 */
        ta.addEventListener('keydown', function (e) {
            if (undo && (e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                ta.value = undo.v;
                ta.setSelectionRange(undo.s, undo.e);
                ta.dispatchEvent(new Event('input'));
                undo = null;
            }
        });
    }

    /* ---------- 启动 ---------- */
    function init() {
        if (/ai-console\.php/.test(location.pathname)) { bindAiConsole(); return; }
        bindModelDetect();
        bindFloating();
        bindEditorPolish();
    }
    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
