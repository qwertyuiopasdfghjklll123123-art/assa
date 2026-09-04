/* ============================================================
   Tokmart Admin Panel - shared helpers
   ============================================================ */
const ADMIN_API_URL = '../api/index.php';

function showAdminToast(msg) {
    const el = document.getElementById('adminToast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(showAdminToast._t);
    showAdminToast._t = setTimeout(() => el.classList.remove('show'), 3000);
}

function adminPostAction(action, data) {
    const options = { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (data instanceof FormData) {
        options.body = data;
    } else {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data || {});
    }
    return fetch(ADMIN_API_URL + '?action=' + encodeURIComponent(action), options)
        .then(function(res) { return res.json(); })
        .catch(function() { return { success: false, message: 'تعذر الاتصال بالخادم' }; });
}

function adminConfirm(message) {
    return window.confirm(message);
}

function adminDeleteRow(action, id, confirmMsg, onDone) {
    if (!adminConfirm(confirmMsg || 'هل أنت متأكد من الحذف؟')) return;
    adminPostAction(action, { id: id }).then(function(res) {
        showAdminToast(res.message || (res.success ? 'تم بنجاح' : 'حدث خطأ'));
        if (res.success && typeof onDone === 'function') onDone();
        else if (res.success) setTimeout(() => window.location.reload(), 500);
    });
}

function adminLogout() {
    adminPostAction('logout', {}).then(function() {
        window.location.href = '../app.php';
    });
}

function previewImageInto(input, previewId) {
    const el = document.getElementById(previewId);
    if (!el || !input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        el.innerHTML = '<img src="' + e.target.result + '">';
    };
    reader.readAsDataURL(input.files[0]);
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}
