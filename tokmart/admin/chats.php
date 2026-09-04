<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$adminId = getSafeAdminId();

$pageTitle = 'الدردشة';
$activeNav = 'chats';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="display:flex;height:70vh;min-height:480px;">
        <div style="width:270px;flex-shrink:0;border-left:1px solid var(--border);display:flex;flex-direction:column;">
            <div style="padding:14px;border-bottom:1px solid var(--border);font-weight:800;font-size:14px;">
                <i class="fa-solid fa-users"></i> المحادثات
            </div>
            <div id="chatUserList" style="flex:1;overflow-y:auto;">
                <div style="text-align:center;padding:30px;color:var(--text3);font-size:13px;">جاري التحميل...</div>
            </div>
        </div>
        <div style="flex:1;display:flex;flex-direction:column;background:var(--bg);">
            <div id="chatWithHeader" style="padding:14px 18px;border-bottom:1px solid var(--border);background:var(--surface);font-weight:700;font-size:14px;">اختر مستخدماً للدردشة</div>
            <div id="chatMessages" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;"></div>
            <div style="display:flex;gap:8px;padding:12px;border-top:1px solid var(--border);background:var(--surface);">
                <input type="text" id="chatInput" placeholder="اكتب ردك..." disabled style="flex:1;padding:9px 12px;border:2px solid var(--border);border-radius:8px;background:var(--bg2);outline:none;">
                <button class="btn btn-primary" id="chatSendBtn" disabled onclick="sendAdminChatMessage()"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
</div>

<script>
const ADMIN_ID = <?php echo (int)$adminId; ?>;
let activeChatUserId = null;
let chatPoll = null;

function loadChatUsers() {
    adminPostAction('getAdminChats', {}).then(function(res) {
        const list = document.getElementById('chatUserList');
        if (!res.success || !res.data || !res.data.length) {
            list.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text3);font-size:13px;">لا توجد محادثات بعد</div>';
            return;
        }
        list.innerHTML = res.data.map(function(u) {
            const unread = u.unreadCount > 0 ? '<span class="badge" style="position:static;">' + u.unreadCount + '</span>' : '';
            return '<div onclick="openChatWith(' + u.id + ', \'' + (u.name || '').replace(/'/g, "\\'") + '\')" style="padding:12px 14px;border-bottom:1px solid var(--border);cursor:pointer;display:flex;justify-content:space-between;align-items:center;' +
                (activeChatUserId === u.id ? 'background:var(--bg2);' : '') + '">' +
                '<div><div style="font-weight:700;font-size:13px;">' + (u.name || 'مستخدم') + '</div>' +
                '<div style="font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;">' + (u.lastMessage || 'لا توجد رسائل') + '</div></div>' + unread + '</div>';
        }).join('');
    });
}

function openChatWith(userId, name) {
    activeChatUserId = userId;
    document.getElementById('chatWithHeader').textContent = name || ('مستخدم #' + userId);
    document.getElementById('chatInput').disabled = false;
    document.getElementById('chatSendBtn').disabled = false;
    loadChatMessages();
    loadChatUsers();
}

function loadChatMessages() {
    if (!activeChatUserId) return;
    adminPostAction('getChatMessages', { userId: activeChatUserId, adminId: ADMIN_ID }).then(function(res) {
        const box = document.getElementById('chatMessages');
        if (!res.success) return;
        box.innerHTML = (res.data || []).map(function(m) {
            const mine = m.sender === 'admin';
            return '<div style="max-width:75%;align-self:' + (mine ? 'flex-end' : 'flex-start') + ';background:' + (mine ? 'var(--primary)' : '#fff') + ';color:' + (mine ? '#fff' : 'var(--text)') + ';padding:9px 13px;border-radius:14px;font-size:13px;box-shadow:0 1px 4px rgba(0,0,0,.06);">' +
                m.message + '</div>';
        }).join('');
        box.scrollTop = box.scrollHeight;
    });
}

function sendAdminChatMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message || !activeChatUserId) return;
    input.value = '';
    adminPostAction('sendChatMessage', {
        userId: activeChatUserId, adminId: ADMIN_ID, message: message, sender: 'admin', messageType: 'text',
    }).then(function(res) {
        if (res.success) loadChatMessages();
        else showAdminToast(res.message || 'فشل الإرسال');
    });
}

document.getElementById('chatInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendAdminChatMessage();
});

loadChatUsers();
chatPoll = setInterval(function() {
    loadChatUsers();
    if (activeChatUserId) loadChatMessages();
}, 8000);
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
