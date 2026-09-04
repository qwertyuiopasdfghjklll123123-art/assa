/* ============================================================
   Tokmart - User support chat window
   ============================================================ */
function openUserChat() {
    if (!requireLogin()) return;
    
    document.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open')
        .forEach(function(page) {
            page.classList.remove('open');
        });
    
    const chatPage = $('chatPageUser');
    if (chatPage) {
        chatPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        state.isUserChatOpen = true;
        updateBottomNavVisibility();
        resetUserChat();
        loadUserChatMessages();
        clearAllIntervals();
        state.chatIntervals.user = setInterval(loadUserChatMessages, 5000);
        setTimeout(function() {
            const input = $('userChatInput');
            if (input) input.focus();
        }, 300);
    }
}

function closeUserChat() {
    const chatPage = $('chatPageUser');
    if (chatPage) {
        chatPage.classList.remove('open');
        document.body.style.overflow = '';
        state.isUserChatOpen = false;
        updateBottomNavVisibility();
        if (state.chatIntervals.user) {
            clearInterval(state.chatIntervals.user);
            state.chatIntervals.user = null;
        }
    }
}

function resetUserChat() {
    const container = $('userMessagesContainer');
    if (!container) return;
    container.innerHTML = `
        <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
            <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
            ابدأ محادثتك مع الدعم الفني
        </div>
        <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
            <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
            رسائل جديدة <span id="newMsgCount">0</span>
        </div>
    `;
    state.userMessages = [];
    state.lastMessageCount = 0;
}

function loadUserChatMessages() {
    if (!state.isLoggedIn || !state.user) return;
    
    callAPI('getUserChatMessages', { userId: state.user.id }, 'GET').then(function(response) {
        if (response.success) {
            const messages = response.data || [];
            const prevCount = state.userMessages.length;
            state.userMessages = messages;
            renderUserMessages(messages);
            
            if (messages.length > prevCount) {
                const newMessages = messages.slice(prevCount);
                const hasNewFromAdmin = newMessages.some(function(m) { return m.sender === 'admin'; });
                if (hasNewFromAdmin) {
                    const adminMsgs = newMessages.filter(function(m) { return m.sender === 'admin'; });
                    showNewMsgNotification(adminMsgs.length);
                    if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
                    updateUnreadBadge(adminMsgs.length);
                }
            }
        }
    });
}

function renderUserMessages(messages) {
    const container = $('userMessagesContainer');
    if (!container) return;
    
    const emptyMsg = $('emptyChatMessage');
    if (emptyMsg) emptyMsg.remove();
    const notification = $('newMsgNotification');
    if (notification) notification.remove();
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                ابدأ محادثتك مع الدعم الفني
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">سيرد فريق الدعم عليك في أقرب وقت</div>
            </div>
            <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
                <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
                رسائل جديدة <span id="newMsgCount">0</span>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isSent = msg.sender === 'user';
        const timeStr = formatMessageTime(msg.createdAt);
        const text = msg.message || '';
        const readStatus = getReadStatusHtml(msg);
        
        html += `
            <div class="message ${isSent ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                    <div class="msg-footer">
                        <span class="msg-time ${isSent ? 'sent-time' : 'received-time'}">${timeStr}</span>
                        ${isSent ? `<span class="msg-read-status">${readStatus}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
        <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
            <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
            رسائل جديدة <span id="newMsgCount">0</span>
        </div>
    `;
    
    container.innerHTML = html;
    scrollToBottomUser();
}

function scrollToBottomUser() {
    const container = $('userMessagesContainer');
    if (container) {
        container.scrollTop = container.scrollHeight;
        hideNewMsgNotification();
    }
}

function showNewMsgNotification(count) {
    const notification = $('newMsgNotification');
    if (!notification) return;
    const countEl = $('newMsgCount');
    if (countEl) countEl.textContent = count;
    notification.classList.add('show');
}

function hideNewMsgNotification() {
    const notification = $('newMsgNotification');
    if (notification) notification.classList.remove('show');
}

function updateUnreadBadge(count) {
    const badge = $('notifBadge');
    if (badge) {
        const current = parseInt(badge.textContent) || 0;
        badge.textContent = current + count;
        badge.style.display = 'flex';
    }
}

function sendUserMessage() {
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول');
        return;
    }
    
    const input = $('userChatInput');
    if (!input) return;
    
    const msg = input.value.trim();
    if (!validateMessage(msg)) {
        return;
    }
    
    input.value = '';
    input.disabled = true;
    
    callAPI('sendChatMessage', {
        userId: state.user.id,
        adminId: 1,
        message: msg,
        messageType: 'text',
        sender: 'user'
    }, 'POST').then(function(response) {
        if (response.success) {
            const newMsg = response.data || {
                id: Date.now(),
                message: msg,
                sender: 'user',
                createdAt: new Date().toISOString(),
                isRead: 0,
                readAt: null
            };
            state.userMessages.push(newMsg);
            renderUserMessages(state.userMessages);
            showToast('✅ تم إرسال رسالتك');
            if (navigator.vibrate) navigator.vibrate(10);
        } else {
            showToast('❌ ' + (response.message || 'فشل إرسال الرسالة'));
            input.value = msg;
        }
    });
    
    input.disabled = false;
    input.focus();
}

function validateMessage(message) {
    if (!message || message.trim() === '') {
        showToast('⚠️ لا يمكن إرسال رسالة فارغة');
        return false;
    }
    return true;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatMessageTime(timestamp) {
    try {
        if (!timestamp) return new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
        const date = new Date(timestamp);
        return date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
        return new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    }
}

// ============================================================
// ✅ دالة وقت القراءة (فارغة - لا تعرض أي شيء)
// ============================================================
function getReadStatusHtml(msg) {
    return '';
}

// ============================================================
// ✅ دالة عرض رسائل المستخدم (بدون وقت القراءة)
// ============================================================
function renderUserMessages(messages) {
    const container = $('userMessagesContainer');
    if (!container) return;
    
    const emptyMsg = $('emptyChatMessage');
    if (emptyMsg) emptyMsg.remove();
    const notification = $('newMsgNotification');
    if (notification) notification.remove();
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                ابدأ محادثتك مع الدعم الفني
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">سيرد فريق الدعم عليك في أقرب وقت</div>
            </div>
            <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
                <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
                رسائل جديدة <span id="newMsgCount">0</span>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isSent = msg.sender === 'user';
        const text = msg.message || '';
        
        // ✅ عرض الرسالة فقط بدون وقت وبدون وقت قراءة
        html += `
            <div class="message ${isSent ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                </div>
            </div>
        `;
    });
    
    html += `
        <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
            <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
            رسائل جديدة <span id="newMsgCount">0</span>
        </div>
    `;
    
    container.innerHTML = html;
    scrollToBottomUser();
}

// ============================================================
// ✅ دالة عرض رسائل الأدمن (بدون وقت القراءة)
// ============================================================
function renderAdminMessages(messages) {
    const container = $('adminMessagesContainer');
    if (!container) return;
    const noChat = $('adminNoChatSelected');
    if (noChat) noChat.style.display = 'none';
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                لا توجد رسائل بعد
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">قم بإرسال أول رسالة للمستخدم</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isAdmin = msg.sender === 'admin';
        const text = msg.message || '';
        
        // ✅ عرض الرسالة فقط بدون وقت وبدون وقت قراءة
        html += `
            <div class="message ${isAdmin ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
}

// ============================================================
// ✅ دالة clearAllIntervals (كما هي)
// ============================================================
