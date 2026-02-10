<?php
$token = '2|kKmXaxyuvzAtqySfit9koS5r2qWLNkLoL3BZNAD5b936b15f';
$apiBase = '/api/v1';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vanda Chat Test</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e;
            color: #eee;
            height: 100vh;
            display: flex;
        }
        .sidebar {
            width: 280px;
            background: #16213e;
            border-right: 1px solid #0f3460;
            display: flex;
            flex-direction: column;
        }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #0f3460;
        }
        .sidebar-header h2 { color: #e94560; }
        .new-chat-btn {
            width: 100%;
            padding: 12px;
            background: #e94560;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        .new-chat-btn:hover { background: #d63050; }
        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }
        .chat-item {
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 5px;
            background: #1a1a2e;
        }
        .chat-item:hover, .chat-item.active { background: #0f3460; }
        .chat-item .title { font-weight: 500; }
        .chat-item .date { font-size: 12px; color: #888; margin-top: 4px; }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .main-header {
            padding: 20px;
            border-bottom: 1px solid #0f3460;
            background: #16213e;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        .message {
            max-width: 80%;
            margin-bottom: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            line-height: 1.5;
        }
        .message.user {
            background: #0f3460;
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }
        .message.assistant {
            background: #16213e;
            border-bottom-left-radius: 4px;
        }
        .message .role {
            font-size: 11px;
            color: #888;
            margin-bottom: 4px;
        }
        .message .content { white-space: pre-wrap; }
        .message .followup-data {
            margin-top: 12px;
            padding: 12px;
            background: #1a1a2e;
            border-radius: 8px;
            border: 1px solid #0f3460;
        }
        .message .followup-data h4 { color: #e94560; margin-bottom: 8px; }

        .input-area {
            padding: 20px;
            border-top: 1px solid #0f3460;
            background: #16213e;
        }
        .input-wrapper {
            display: flex;
            gap: 10px;
        }
        .input-wrapper input {
            flex: 1;
            padding: 14px;
            border: 1px solid #0f3460;
            border-radius: 8px;
            background: #1a1a2e;
            color: #eee;
            font-size: 14px;
        }
        .input-wrapper input:focus { outline: none; border-color: #e94560; }
        .input-wrapper button {
            padding: 14px 24px;
            background: #e94560;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
        }
        .input-wrapper button:hover { background: #d63050; }
        .input-wrapper button:disabled { background: #555; cursor: not-allowed; }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #333;
            border-top-color: #e94560;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .no-chat {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }

        .followup-html { all: initial; font-family: inherit; color: #eee; }
        .followup-html * { color: inherit; }
        .followup-html table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        .followup-html th, .followup-html td { border: 1px solid #0f3460; padding: 8px; text-align: left; }
        .followup-html th { background: #0f3460; }
        .followup-html h3, .followup-html h4 { color: #e94560; margin: 10px 0 5px; }
        .followup-html ul { padding-left: 20px; }

        .followup-html .followup-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; }
        .followup-html .followup-title { flex: 1; }
        .followup-html .followup-actions { display: flex; gap: 8px; }
        .followup-html .export-btn {
            display: inline-block;
            padding: 6px 12px;
            background: #0f3460;
            color: #eee;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            transition: background 0.2s;
        }
        .followup-html .export-btn:hover { background: #e94560; }

        .followup-html svg { max-width: 100%; height: auto; }
        .followup-html .chart-table table { margin: 15px 0; }
        .followup-html .metric-card {
            background: #0f3460;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .followup-html .metric-value { font-size: 2em; font-weight: bold; color: #e94560; }
        .followup-html .metric-label { color: #888; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>Wanda</h2>
            <button class="new-chat-btn" onclick="createChat()">+ Новый чат</button>
        </div>
        <div class="chat-list" id="chatList"></div>
    </div>

    <div class="main">
        <div class="main-header">
            <h3 id="chatTitle">Выберите чат</h3>
        </div>
        <div class="messages" id="messages">
            <div class="no-chat">Выберите чат или создайте новый</div>
        </div>
        <div class="input-area">
            <div class="input-wrapper">
                <input type="text" id="messageInput" placeholder="Введите сообщение..." onkeypress="if(event.key==='Enter')sendMessage()" disabled>
                <button onclick="sendMessage()" id="sendBtn" disabled>Отправить</button>
            </div>
        </div>
    </div>

    <script>
        const TOKEN = '<?= $token ?>';
        const API = '<?= $apiBase ?>';
        let currentChatId = null;

        async function api(method, endpoint, data = null) {
            const options = {
                method,
                headers: {
                    'Authorization': `Bearer ${TOKEN}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            };
            if (data) options.body = JSON.stringify(data);

            const res = await fetch(API + endpoint, options);
            return res.json();
        }

        async function loadChats() {
            const res = await api('GET', '/chats');
            const list = document.getElementById('chatList');
            list.innerHTML = '';

            if (res.data && res.data.length) {
                res.data.forEach(chat => {
                    const div = document.createElement('div');
                    div.className = 'chat-item' + (chat.id === currentChatId ? ' active' : '');
                    div.innerHTML = `
                        <div class="title">${chat.title || 'Чат #' + chat.id}</div>
                        <div class="date">${new Date(chat.created_at).toLocaleString('ru')}</div>
                    `;
                    div.onclick = () => selectChat(chat.id, chat.title);
                    list.appendChild(div);
                });
            }
        }

        async function createChat() {
            const title = prompt('Название чата:', 'Новый чат');
            if (!title) return;

            const res = await api('POST', '/chats', { title });
            if (res.data) {
                await loadChats();
                selectChat(res.data.id, res.data.title);
            }
        }

        async function selectChat(chatId, title) {
            currentChatId = chatId;
            document.getElementById('chatTitle').textContent = title || 'Чат #' + chatId;
            document.getElementById('messageInput').disabled = false;
            document.getElementById('sendBtn').disabled = false;

            document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
            event?.target?.closest?.('.chat-item')?.classList.add('active');

            await loadMessages();
        }

        async function loadMessages() {
            const res = await api('GET', `/chats/${currentChatId}/messages`);
            const container = document.getElementById('messages');
            container.innerHTML = '';

            if (res.data && res.data.length) {
                res.data.forEach(msg => addMessageToUI(msg));
            } else {
                container.innerHTML = '<div class="no-chat">Начните диалог</div>';
            }
            container.scrollTop = container.scrollHeight;
        }

        function addMessageToUI(msg) {
            const container = document.getElementById('messages');
            if (container.querySelector('.no-chat')) container.innerHTML = '';

            const div = document.createElement('div');
            div.className = `message ${msg.role}`;

            let html = `<div class="role">${msg.role === 'user' ? 'Вы' : 'Wanda'}</div>`;
            html += `<div class="content">${escapeHtml(msg.content)}</div>`;

            if (msg.followup_data && msg.followup_data.html) {
                html += `<div class="followup-data">
                    <h4>Followup данные:</h4>
                    <div class="followup-html">${msg.followup_data.html}</div>
                </div>`;
            }

            div.innerHTML = html;
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
        }

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const content = input.value.trim();
            if (!content || !currentChatId) return;

            input.value = '';
            input.disabled = true;
            document.getElementById('sendBtn').disabled = true;

            // Показать сообщение пользователя сразу
            addMessageToUI({ role: 'user', content });

            // Показать индикатор загрузки
            const container = document.getElementById('messages');
            const loading = document.createElement('div');
            loading.className = 'message assistant';
            loading.innerHTML = '<div class="loading"></div>';
            container.appendChild(loading);
            container.scrollTop = container.scrollHeight;

            try {
                const res = await api('POST', `/chats/${currentChatId}/messages`, { content });
                loading.remove();

                if (res.data) {
                    addMessageToUI(res.data);
                } else if (res.message) {
                    addMessageToUI({ role: 'assistant', content: 'Ошибка: ' + res.message });
                }
            } catch (e) {
                loading.remove();
                addMessageToUI({ role: 'assistant', content: 'Ошибка: ' + e.message });
            }

            input.disabled = false;
            document.getElementById('sendBtn').disabled = false;
            input.focus();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Загрузить чаты при старте
        loadChats();
    </script>
</body>
</html>
