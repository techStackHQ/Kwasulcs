<?php
require_once __DIR__ . '/config.php';
require_login();
$user = current_user();
$__chatActiveType = 'ai';
$__chatActiveId   = 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $pageTitle = 'AI Assistant'; $extraCss = ['assets/chat.css']; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <div class="chat-thread-header">
        <a class="chat-icon-btn" href="chat.php" aria-label="Back to Chat" title="Back to Chat"><i class="bi bi-arrow-left"></i></a>
        <div class="chat-avatar chat-thread-avatar chat-avatar--ai"><i class="bi bi-robot"></i></div>
        <div class="chat-thread-info">
            <strong>AI Assistant</strong>
            <p class="muted">General study help — not scoped to a single course.</p>
        </div>
    </div>

    <main class="page chat-page">
        <?php include __DIR__ . '/partials/chat_sidebar.php'; ?>

        <div class="panel chat-thread-panel">
            <div id="aiMessages" class="chat-messages"></div>
            <form id="aiForm" class="chat-input-row" onsubmit="return false;">
                <textarea id="aiInput" rows="1" placeholder="Ask me anything…"></textarea>
                <button type="button" class="btn primary" id="aiSendBtn">Send</button>
            </form>
        </div>
    </main>

    <script>
        (function () {
            let sessionId = null;
            const messagesEl = document.getElementById('aiMessages');
            const input = document.getElementById('aiInput');
            const sendBtn = document.getElementById('aiSendBtn');

            function escHtml(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function addMessage(text, cls) {
                const div = document.createElement('div');
                div.className = 'chat-msg ' + cls;
                div.innerHTML = escHtml(text).replace(/\n/g, '<br>');
                messagesEl.appendChild(div);
                messagesEl.scrollTop = messagesEl.scrollHeight;
                return div;
            }

            async function loadOrCreateSession() {
                const res = await fetch('ai_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_sessions', course_id: 0 })
                });
                const data = await res.json();
                if (data.sessions && data.sessions.length) {
                    sessionId = data.sessions[0].id;
                    const msgRes = await fetch('ai_chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'get_messages', session_id: sessionId })
                    });
                    const msgData = await msgRes.json();
                    (msgData.messages || []).forEach(m => addMessage(m.content, m.role === 'user' ? 'me' : 'bot'));
                }
                if (!messagesEl.children.length) {
                    addMessage("Hi! I'm your general AI study assistant. Ask me anything.", 'bot');
                }
            }

            async function send() {
                const text = input.value.trim();
                if (!text) return;
                addMessage(text, 'me');
                input.value = '';
                sendBtn.disabled = true;
                const thinking = addMessage('Thinking…', 'bot thinking');
                try {
                    const res = await fetch('ai_chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send', message: text, course_id: 0, session_id: sessionId })
                    });
                    const data = await res.json();
                    thinking.remove();
                    if (data.reply) {
                        if (data.session_id) sessionId = data.session_id;
                        addMessage(data.reply, 'bot');
                    } else {
                        addMessage('Sorry, something went wrong: ' + (data.error || 'Unknown error'), 'bot');
                    }
                } catch (e) {
                    thinking.remove();
                    addMessage('Could not reach the AI assistant. Please try again.', 'bot');
                } finally {
                    sendBtn.disabled = false;
                    input.focus();
                }
            }

            sendBtn.addEventListener('click', send);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
            });

            loadOrCreateSession();
        })();
    </script>
</body>

</html>
