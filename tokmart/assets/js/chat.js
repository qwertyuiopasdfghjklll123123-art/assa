/* ============================================================
   Tokmart - Customer support chat
   ============================================================ */

function openOrderSupportChat(orderId) {
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }

    const order = state.orders.find(function(o) {
        return o.id == orderId || o.orderId == orderId;
    });

    if (!order) {
        showToast('⚠️ الطلب غير موجود');
        return;
    }

    try { localStorage.setItem('last_order_id', orderId); } catch (e) {}

    const detailPage = document.getElementById('orderDetailPage');
    if (detailPage) detailPage.classList.remove('open');

    const chatPage = document.getElementById('chatPageUser');
    if (!chatPage) {
        showToast('⚠️ صفحة الدردشة غير متوفرة');
        return;
    }

    chatPage.classList.add('open');
    document.body.style.overflow = 'hidden';
    state.isUserChatOpen = true;
    updateBottomNavVisibility();

    resetUserChat();

    const orderNumber = order.orderId || order.id;
    const orderTotal = parseFloat(order.total || 0).toFixed(2);
    const orderDate = order.date || new Date(order.createdAt).toLocaleDateString('ar-EG');

    setTimeout(function() {
        const container = document.getElementById('userMessagesContainer');
        if (!container) return;

        const emptyMsg = document.getElementById('emptyChatMessage');
        if (emptyMsg) emptyMsg.remove();
        const notification = document.getElementById('newMsgNotification');
        if (notification) notification.remove();

        const systemMsg = document.createElement('div');
        systemMsg.className = 'message received';
        systemMsg.style.cssText = 'align-self:center;max-width:92%;margin:8px 0;';
        systemMsg.innerHTML = `
            <div class="message-content" style="background:var(--bg2);border-radius:16px;padding:14px 18px;border:1px solid var(--border);text-align:center;">
                <div class="msg-text" style="font-size:13px;color:var(--text2);">
                    <div style="font-size:28px;margin-bottom:8px;">💬</div>
                    <div style="font-weight:700;font-size:15px;color:var(--text);">الدعم الفني للطلب</div>
                    <div style="margin:6px 0;padding:8px;background:var(--surface);border-radius:8px;border:1px solid var(--border);">
                        <div style="font-weight:700;color:var(--primary);">#${orderNumber}</div>
                        <div style="font-size:12px;color:var(--text3);">💰 ${orderTotal} د.ع | 📅 ${orderDate}</div>
                        <div style="font-size:12px;color:var(--text3);">📌 الحالة: ${getOrderStatusText(order.status)}</div>
                    </div>
                    <div style="font-size:12px;color:var(--text3);margin-top:6px;">
                        <i class="fa-regular fa-clock"></i> فريق الدعم متصل الآن، اكتب رسالتك
                    </div>
                </div>
            </div>
        `;
        container.appendChild(systemMsg);

        const input = document.getElementById('userChatInput');
        if (input) {
            setTimeout(function() {
                input.focus();
                input.placeholder = 'اكتب رسالتك بخصوص الطلب #' + orderNumber + '...';
            }, 400);
        }

        container.scrollTop = container.scrollHeight;
    }, 400);

    if (state.chatIntervals.user) {
        clearInterval(state.chatIntervals.user);
    }
    state.chatIntervals.user = setInterval(loadUserChatMessages, 5000);

    loadUserChatMessages();

    showToast('💬 تم فتح الدردشة مع الدعم');
}

function getOrderStatusText(status) {
    const statusMap = {
        'pending': '⏳ قيد المعالجة',
        'shipped': '🚚 قيد التوصيل',
        'completed': '✅ مكتمل',
        'cancelled': '❌ ملغي',
        'approved': '✅ تم الموافقة',
        'rejected': '❌ مرفوض'
    };
    return statusMap[status] || status || 'غير معروف';
}

function sendOrderSupportMessage(orderId, message) {
    if (!orderId || !message) return;

    callAPI('sendOrderSupportMessage', {
        orderId: orderId,
        message: message
    }, 'POST').then(function(response) {
        if (!response.success) {
            console.warn('⚠️ فشل إرسال إشعار الدعم:', response.message);
        }
    });
}

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
        const text = msg.message || '';
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

function clearAllIntervals() {
    Object.keys(state.chatIntervals).forEach(function(key) {
        if (state.chatIntervals[key]) {
            clearInterval(state.chatIntervals[key]);
            state.chatIntervals[key] = null;
        }
    });
}
