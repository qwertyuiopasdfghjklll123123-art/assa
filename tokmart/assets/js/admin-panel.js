/* ============================================================
   Tokmart Admin - shared helpers: API calls, toast, sidebar, modals
   ============================================================ */
const $ = function(id) { return document.getElementById(id); };
const $$ = function(sel) { return document.querySelectorAll(sel); };

let adminToastTimer = null;
function adminToast(msg, duration) {
    duration = duration || 2500;
    const el = $('adminToast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(adminToastTimer);
    adminToastTimer = setTimeout(function() { el.classList.remove('show'); }, duration);
}

function adminApi(action, data, method) {
    method = method || 'POST';
    return new Promise(function(resolve) {
        let url = window.APP_CONFIG.apiUrl + '?action=' + action;
        const options = {
            method: method,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        };

        if (data instanceof FormData) {
            options.body = data;
        } else if (data && method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        } else if (data && method === 'GET') {
            url += '&' + new URLSearchParams(data).toString();
        }

        fetch(url, options)
            .then(function(response) { return response.json(); })
            .then(function(result) { resolve(result); })
            .catch(function(err) {
                console.error('Admin API error:', err);
                resolve({ success: false, message: 'خطأ في الاتصال بالخادم' });
            });
    });
}

function confirmAndRun(message, fn) {
    if (window.confirm(message)) fn();
}

function openModal(id) {
    const modal = $(id);
    if (modal) modal.classList.add('open');
}
function closeModal(id) {
    const modal = $(id);
    if (modal) modal.classList.remove('open');
}

function previewImageInput(input, previewId, placeholderIcon) {
    const preview = $(previewId);
    if (!preview) return;
    input.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<img src="' + e.target.result + '">';
            };
            reader.readAsDataURL(this.files[0]);
        } else {
            preview.innerHTML = '<i class="fa-solid ' + (placeholderIcon || 'fa-image') + '"></i>';
        }
    });
}

function showFormError(id, message) {
    const el = $(id);
    if (!el) return;
    el.textContent = message;
    el.style.display = 'block';
}
function hideFormError(id) {
    const el = $(id);
    if (!el) return;
    el.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const toggle = $('sidebarToggle');
    const sidebar = $('adminSidebar');
    const overlay = $('sidebarOverlay');
    if (toggle && sidebar && overlay) {
        toggle.addEventListener('click', function() {
            sidebar.classList.add('open');
            overlay.classList.add('open');
        });
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
        });
    }

    $$('.admin-modal-overlay').forEach(function(overlayEl) {
        overlayEl.addEventListener('click', function(e) {
            if (e.target === overlayEl) overlayEl.classList.remove('open');
        });
    });
});
