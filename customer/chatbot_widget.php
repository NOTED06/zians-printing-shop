
<div id="chatbot">
    <button id="chatbot-toggle" onclick="toggleChatbot()" title="Chat with Zian's Assistant">
        <svg id="chatbot-icon-open" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <svg id="chatbot-icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        <span id="chatbot-badge" style="display:none;">1</span>
    </button>

    <div id="chatbot-window">
        <div id="chatbot-header">
            <div class="chatbot-header-info">
                <div class="chatbot-avatar">Z</div>
                <div>
                    <div class="chatbot-name">Zian's Assistant</div>
                    <div class="chatbot-status"><span class="status-dot"></span> Online</div>
                </div>
            </div>
            <button class="chatbot-close-btn" onclick="toggleChatbot()" title="Close chat">&times;</button>
        </div>
        <div id="chatbot-messages"></div>
        <div id="chatbot-quick-replies"></div>
        <form id="chatbot-input-form" onsubmit="sendChatMessage(event)">
            <input type="text" id="chatbot-input" placeholder="Type your message..." autocomplete="off">
            <button type="submit" id="chatbot-send" title="Send">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<style>
#chatbot { position: fixed; bottom: 24px; right: 24px; z-index: 9999; font-family: 'Inter', system-ui, -apple-system, sans-serif; }

#chatbot-toggle {
    width: 60px; height: 60px; border-radius: 50%; border: none;
    background: #ff6b35;
    color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 20px rgba(255,107,53,0.4);
    transition: all 0.3s ease; position: relative;
}
#chatbot-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(255,107,53,0.55); }
#chatbot-badge {
    position: absolute; top: -2px; right: -2px; background: #ef4444; color: #fff;
    font-size: 11px; font-weight: 700; width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; border: 2px solid #0a0a0a;
}

#chatbot-window {
    display: none; position: absolute; bottom: 72px; right: 0;
    width: 380px; max-height: 520px; border-radius: 16px; overflow: hidden;
    background: #ffffff; border: 1px solid #e2e8f0;
    box-shadow: 0 12px 48px rgba(0,0,0,0.12);
    flex-direction: column; animation: chatSlideUp 0.3s ease;
}
#chatbot-window.open { display: flex; }

@keyframes chatSlideUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

#chatbot-header {
    background: #ff6b35;
    padding: 14px 16px; display: flex; align-items: center; justify-content: space-between;
}
.chatbot-header-info { display: flex; align-items: center; gap: 10px; }
.chatbot-avatar {
    width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.2);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px; color: #fff;
}
.chatbot-name { font-weight: 700; font-size: 15px; color: #fff; }
.chatbot-status { font-size: 12px; color: rgba(255,255,255,0.85); display: flex; align-items: center; gap: 5px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; }
.chatbot-close-btn {
    background: rgba(255,255,255,0.15); border: none; color: #fff; width: 30px; height: 30px;
    border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center;
    justify-content: center; transition: background 0.2s;
}
.chatbot-close-btn:hover { background: rgba(255,255,255,0.3); }

#chatbot-messages {
    flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px;
    min-height: 260px; max-height: 320px; scroll-behavior: smooth;
    background: #ffffff;
}
#chatbot-messages::-webkit-scrollbar { width: 5px; }
#chatbot-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.chat-msg { max-width: 85%; padding: 10px 14px; border-radius: 14px; font-size: 13.5px; line-height: 1.55; word-wrap: break-word; }
.chat-msg.bot {
    align-self: flex-start; background: #f1f5f9; color: #1e293b;
    border-bottom-left-radius: 4px;
}
.chat-msg.user {
    align-self: flex-end; background: #ff6b35; color: #fff;
    border-bottom-right-radius: 4px;
}
.chat-msg.bot strong, .chat-msg.bot b { color: #ff6b35; }

.chat-typing { align-self: flex-start; display: flex; gap: 4px; padding: 12px 16px; background: #f1f5f9; border-radius: 14px; border-bottom-left-radius: 4px; }
.chat-typing span {
    width: 7px; height: 7px; background: #94a3b8; border-radius: 50%;
    animation: typingBounce 1.2s infinite;
}
.chat-typing span:nth-child(2) { animation-delay: 0.2s; }
.chat-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typingBounce {
    0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
    40% { transform: translateY(-6px); opacity: 1; }
}

#chatbot-quick-replies {
    padding: 0 16px 8px; display: flex; flex-wrap: wrap; gap: 6px;
    background: #ffffff;
}
.quick-reply-btn {
    background: #ffffff; border: 1.5px solid #ff6b35; color: #ff6b35; padding: 6px 14px;
    border-radius: 20px; font-size: 12px; cursor: pointer; transition: all 0.2s; font-family: inherit;
    font-weight: 500;
}
.quick-reply-btn:hover { background: #ff6b35; color: #fff; }

#chatbot-input-form {
    display: flex; align-items: center; gap: 8px; padding: 10px 12px;
    border-top: 1px solid #f1f5f9; background: #ffffff;
}
#chatbot-input {
    flex: 1; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 20px;
    padding: 9px 16px; color: #1e293b; font-size: 13.5px; outline: none;
    font-family: inherit; transition: border-color 0.2s;
}
#chatbot-input::placeholder { color: #94a3b8; }
#chatbot-input:focus { border-color: #ff6b35; background: #ffffff; }
#chatbot-send {
    width: 38px; height: 38px; border-radius: 50%; border: none;
    background: #ff6b35; color: #fff; cursor: pointer; display: flex;
    align-items: center; justify-content: center; transition: background 0.2s; flex-shrink: 0;
}
#chatbot-send:hover { background: #e55a2b; }

.chat-order-card {
    align-self: flex-start; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 12px; margin: 4px 0; width: 92%; font-size: 12.5px; color: #475569;
}
.order-card-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px; }
.order-card-title { font-weight: 700; font-size: 13.5px; color: #0f172a; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-card-badge { font-size: 10.5px; font-weight: 700; padding: 3px 8px; border-radius: 20px; white-space: nowrap; }
.order-card-price { font-size: 16px; font-weight: 800; color: #ff6b35; margin-bottom: 4px; }
.order-card-variant { color: #64748b; font-size: 11.5px; margin-bottom: 2px; }
.order-card-pay { color: #64748b; font-size: 11px; margin-bottom: 2px; }
.order-card-date { color: #94a3b8; font-size: 11px; margin-bottom: 10px; }

.order-steps { display: flex; align-items: flex-start; justify-content: space-between; gap: 0; padding: 4px 0 2px; }
.order-step { display: flex; flex-direction: column; align-items: center; flex: 0 0 auto; width: 56px; }
.order-step-dot {
    width: 22px; height: 22px; border-radius: 50%; border: 2px solid #cbd5e1;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; color: #94a3b8; background: #ffffff; font-weight: 700; transition: all 0.3s;
}
.order-step-dot.done { color: #fff; border-color: transparent; }
.order-step-dot.pending { opacity: 0.35; filter: blur(0.5px); color: #94a3b8; border-color: #e2e8f0; background: #f1f5f9; }
.order-step.active .order-step-dot { box-shadow: 0 0 0 3px rgba(255,107,53,0.25); transform: scale(1.1); }
.order-step-label { font-size: 8.5px; color: #64748b; text-align: center; margin-top: 4px; line-height: 1.2; }
.order-step-line { flex: 1; height: 2px; background: #e2e8f0; margin-top: 11px; min-width: 8px; border-radius: 2px; }

@media (max-width: 480px) {
    #chatbot-window { width: calc(100vw - 32px); right: -8px; bottom: 68px; max-height: 70vh; }
    #chatbot-toggle { width: 52px; height: 52px; }
    .order-step { width: 48px; }
    .order-step-label { font-size: 7.5px; }
}
</style>

<script>
let chatbotOpen = false;
let chatbotReady = false;
let chatHistory = [];

function toggleChatbot() {
    const win = document.getElementById('chatbot-window');
    const iconOpen = document.getElementById('chatbot-icon-open');
    const iconClose = document.getElementById('chatbot-icon-close');
    const badge = document.getElementById('chatbot-badge');

    chatbotOpen = !chatbotOpen;
    if (chatbotOpen) {
        win.classList.add('open');
        iconOpen.style.display = 'none';
        iconClose.style.display = 'block';
        badge.style.display = 'none';
        if (!chatbotReady) {
            chatbotReady = true;
            setTimeout(() => {
                appendBotMessage("Hello! 👋 I'm Zian's AI Assistant.\n\nI can help you with:\n• 📦 Your **order status**\n• 🖨️ Our **products & pricing**\n• 🎨 **Customization** process\n• 📋 **How to order**\n\nWhat would you like to know?",
                    ['My Orders', 'Products', 'How to Order', 'Customize']);
            }, 400);
        }
        document.getElementById('chatbot-input').focus();
    } else {
        win.classList.remove('open');
        iconOpen.style.display = 'block';
        iconClose.style.display = 'none';
    }
}

function sendChatMessage(e) {
    e.preventDefault();
    const input = document.getElementById('chatbot-input');
    const msg = input.value.trim();
    if (!msg) return;

    appendUserMessage(msg);
    chatHistory.push({ role: 'user', text: msg });
    input.value = '';
    clearQuickReplies();
    showTyping();

    fetch('../chatbot_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ message: msg, history: chatHistory.slice(-10) })
    })
    .then(r => r.json())
    .then(data => {
        removeTyping();
        chatHistory.push({ role: 'assistant', text: data.reply });
        appendBotMessage(data.reply, data.quickReplies || []);
        if (data.orderCards && data.orderCards.length > 0) renderOrderCards(data.orderCards);
    })
    .catch(() => {
        removeTyping();
        appendBotMessage("Sorry, something went wrong. Please try again!", ['My Orders', 'Products', 'How to Order']);
    });
}

function appendUserMessage(text) {
    const container = document.getElementById('chatbot-messages');
    const div = document.createElement('div');
    div.className = 'chat-msg user';
    div.textContent = text;
    container.appendChild(div);
    scrollChat();
}

function appendBotMessage(text, quickReplies) {
    const container = document.getElementById('chatbot-messages');
    const div = document.createElement('div');
    div.className = 'chat-msg bot';
    div.innerHTML = formatBotText(text);
    container.appendChild(div);
    scrollChat();
    if (quickReplies && quickReplies.length > 0) showQuickReplies(quickReplies);
    if (!chatbotOpen) document.getElementById('chatbot-badge').style.display = 'flex';
}

function formatBotText(text) {
    text = escHtml(text || '');
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\n/g, '<br>');
    return text;
}

function escHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function renderOrderCards(cards) {
    const container = document.getElementById('chatbot-messages');
    const allSteps = ['Processing','Production','Ready for Pickup','Completed'];
    const statusColors = {Processing:'#3b82f6',Production:'#f59e0b','Ready for Pickup':'#8b5cf6',Completed:'#22c55e',Cancelled:'#ef4444'};
    const statusIcons = {Processing:'⏳',Production:'🔧','Ready for Pickup':'📦',Completed:'✅',Cancelled:'❌'};
    const stepIcons = ['⚙️','🏭','📦','✅'];

    cards.forEach(c => {
        const card = document.createElement('div');
        card.className = 'chat-order-card';
        const color = statusColors[c.status] || '#6b7280';
        const icon = statusIcons[c.status] || '📋';
        const stepIdx = allSteps.indexOf(c.status);
        const isCancelled = c.status === 'Cancelled';

        let stepsHtml = '<div class="order-steps">';
        allSteps.forEach((s, i) => {
            const done = !isCancelled && i <= stepIdx;
            const active = !isCancelled && i === stepIdx;
            const pending = !isCancelled && i > stepIdx;
            const dotCls = done ? 'done' : (pending ? 'pending' : '');
            const activeCls = active ? ' active' : '';
            const lineClr = (i > 0 && !isCancelled && i <= stepIdx) ? color : '#333';
            if (i > 0) stepsHtml += `<div class="order-step-line" style="background:${lineClr}"></div>`;
            stepsHtml += `<div class="order-step${activeCls}"><div class="order-step-dot ${dotCls}" style="${done?'background:'+color+';border-color:'+color:''}">${stepIcons[i]}</div><div class="order-step-label" style="${active?'color:'+color+';font-weight:700':(pending?'opacity:0.4':'')}">${s}</div></div>`;
        });
        stepsHtml += '</div>';

        let details = `<div class="order-card-variant">`;
        if (c.variant) details += `${escHtml(c.variant)}`;
        if (c.size) details += ` · ${escHtml(c.size)}`;
        details += ` · Qty: ${escHtml(c.qty)}</div>`;
        if (c.payment) details += `<div class="order-card-pay">💳 ${escHtml(c.payment)}${c.down ? ' · Down: ₱'+escHtml(c.down) : ''}</div>`;

        card.innerHTML = `
            <div class="order-card-head">
                <div class="order-card-title">${escHtml(c.product)}</div>
                <div class="order-card-badge" style="background:${color}20;color:${color};border:1px solid ${color}40">${icon} ${escHtml(c.status)}</div>
            </div>
            <div class="order-card-price">₱${escHtml(c.price)}</div>
            ${details}
            <div class="order-card-date">📅 ${escHtml(c.date)}</div>
            ${!isCancelled ? stepsHtml : '<div style="text-align:center;padding:8px;color:#ef4444;font-weight:600;">❌ Order Cancelled</div>'}
        `;
        container.appendChild(card);
    });
    scrollChat();
}

function showQuickReplies(replies) {
    const container = document.getElementById('chatbot-quick-replies');
    container.innerHTML = '';
    replies.forEach(r => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'quick-reply-btn';
        btn.textContent = r;
        btn.onclick = () => {
            document.getElementById('chatbot-input').value = r;
            sendChatMessage(new Event('submit'));
        };
        container.appendChild(btn);
    });
}

function clearQuickReplies() {
    document.getElementById('chatbot-quick-replies').innerHTML = '';
}

function showTyping() {
    const container = document.getElementById('chatbot-messages');
    const typing = document.createElement('div');
    typing.className = 'chat-typing';
    typing.id = 'typing-indicator';
    typing.innerHTML = '<span></span><span></span><span></span>';
    container.appendChild(typing);
    scrollChat();
}

function removeTyping() {
    const el = document.getElementById('typing-indicator');
    if (el) el.remove();
}

function scrollChat() {
    const container = document.getElementById('chatbot-messages');
    setTimeout(() => container.scrollTop = container.scrollHeight, 50);
}

setTimeout(() => {
    if (!chatbotOpen) document.getElementById('chatbot-badge').style.display = 'flex';
}, 3000);
</script>
