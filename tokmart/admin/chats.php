<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'الدردشة';
$activeNav = 'chats';
$currentAdminId = getSafeAdminId();

require __DIR__ . '/includes/header.php';
?>

<div class="admin-panel-section" style="margin-bottom:0;">
    <div class="admin-chat-wrap">
        <div class="admin-chat-sidebar">
            <div class="sidebar-search"><input type="text" id="adminChatSearch" placeholder="🔍 بحث عن مستخدم..."></div>
            <div class="chat-user-list" id="adminUserList">
                <div style="text-align:center;padding:20px;color:var(--text3);font-size:13px;">جاري التحميل...</div>
            </div>
        </div>
        <div class="admin-messages-area">
            <div class="admin-chat-with" id="adminChatWith">اختر مستخدماً من القائمة لبدء المحادثة</div>
            <div class="admin-messages-container" id="adminMessagesContainer">
                <div style="text-align:center;font-size:13px;color:var(--text3);padding:40px 0;">
                    <i class="fa-solid fa-comments" style="font-size:40px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    اختر مستخدماً من القائمة الجانبية
                </div>
            </div>
            <div class="admin-input-wrapper">
                <input type="text" id="adminChatInput" placeholder="اكتب ردك هنا..." disabled>
                <button class="btn btn-md btn-primary" id="adminSendBtn" onclick="sendAdminMessage()" disabled><i class="fa-solid fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
</div>

<script>
const ADMIN_ID = <?php echo (int)$currentAdminId; ?>;
let currentChatUserId = null;
let chatUsers = [];
let chatPollTimer = null;

function loadAdminChats() {
    adminApi('getAdminChats', {}, 'GET').then(function(res) {
        if (res.success) {
            chatUsers = res.data || [];
            renderChatUserList(chatUsers);
        }
    });
}

function renderChatUserList(users, filter) {
    const list = $('adminUserList');
    const filtered = filter ? users.filter(function(u) { return (u.name || '').toLowerCase().includes(filter); }) : users;

    if (filtered.length === 0) {
        list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3);font-size:13px;">لا يوجد مستخدمين</div>';
        return;
    }

    list.innerHTML = filtered.map(function(u) {
        const avatar = u.avatar_url ? '<img src="' + u.avatar_url + '">' : '<i class="fa-solid fa-user"></i>';
        return '<div class="admin-user-item ' + (currentChatUserId === u.id ? 'active' : '') + '" onclick="selectChatUser(' + u.id + ', \'' + (u.name || '').replace(/'/g, "\\'") + '\')">' +
            '<div class="avatar">' + avatar + '</div>' +
            '<div class="meta"><div class="name">' + (u.name || '') + '</div><div class="last-msg">' + (u.lastMessage || 'لا توجد رسائل') + '</div></div>' +
            (u.unreadCount > 0 ? '<span class="unread-badge">' + u.unreadCount + '</span>' : '') +
            '</div>';
    }).join('');
}

function selectChatUser(userId, name) {
    currentChatUserId = userId;
    $('adminChatWith').textContent = '💬 ' + name;
    $('adminChatInput').disabled = false;
    $('adminSendBtn').disabled = false;
    renderChatUserList(chatUsers, $('adminChatSearch').value.trim().toLowerCase());
    loadChatMessages();
    if (chatPollTimer) clearInterval(chatPollTimer);
    chatPollTimer = setInterval(loadChatMessages, 5000);
}

function loadChatMessages() {
    if (!currentChatUserId) return;
    adminApi('getChatMessages', { userId: currentChatUserId, adminId: ADMIN_ID }, 'GET').then(function(res) {
        if (res.success) renderAdminMessages(res.data || []);
    });
}

function renderAdminMessages(messages) {
    const container = $('adminMessagesContainer');
    if (messages.length === 0) {
        container.innerHTML = '<div style="text-align:center;font-size:13px;color:var(--text3);padding:40px 0;">لا توجد رسائل بعد</div>';
        return;
    }
    container.innerHTML = messages.map(function(m) {
        const isSent = m.sender === 'admin';
        const div = document.createElement('div');
        div.textContent = m.message || '';
        return '<div class="message ' + (isSent ? 'sent' : 'received') + '"><div class="message-content">' + div.innerHTML + '</div></div>';
    }).join('');
    container.scrollTop = container.scrollHeight;
}

function sendAdminMessage() {
    const input = $('adminChatInput');
    const msg = input.value.trim();
    if (!msg || !currentChatUserId) return;

    input.value = '';
    adminApi('sendChatMessage', {
        userId: currentChatUserId, adminId: ADMIN_ID, message: msg, messageType: 'text', sender: 'admin'
    }, 'POST').then(function(res) {
        if (res.success) {
            loadChatMessages();
        } else {
            adminToast('❌ ' + (res.message || 'فشل إرسال الرسالة'));
            input.value = msg;
        }
    });
}

$('adminChatSearch').addEventListener('input', function() {
    renderChatUserList(chatUsers, this.value.trim().toLowerCase());
});

$('adminChatInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); sendAdminMessage(); }
});

loadAdminChats();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
