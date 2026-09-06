/* ============================================================
   Tokmart - App bootstrap: restore session, wire up listeners, start app
   ============================================================ */

function init() {
    // ===== استعادة حالة المستخدم =====
    try {
        const savedUser = localStorage.getItem('Tokmart_user');
        if (savedUser) {
            const user = JSON.parse(savedUser);
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;
        }
    } catch (e) {
        state.isLoggedIn = false;
        state.user = null;
    }

    document.documentElement.dir = currentLang === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = currentLang;
    updateAllTexts();
    renderLanguageOptions();

    updateBalanceDisplay();
    updateAccountUI();

    loadFavorites();
    loadSiteData();
    loadAllData(false);
    setupSearch();

    setTimeout(initGoogleLogin, 300);
    setTimeout(initGoogleLogin, 1000);
    setTimeout(initGoogleLogin, 2000);

    $$('.nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchPage(this.dataset.page); });
    });

    $$('.back-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const page = this.closest('.settings-page, .product-detail-page, .all-products-page, .category-products-page, .favorites-page, .orders-page, .order-detail-page, .recharge-page, .auth-page, .chat-app-user');
            if (page) {
                if (page.classList.contains('chat-app-user')) {
                    closeUserChat();
                } else {
                    page.classList.remove('open');
                    document.body.style.overflow = '';
                    updateBottomNavVisibility();
                }
            }
        });
    });

    const loginBtn = $('loginBtn');
    if (loginBtn) {
        loginBtn.addEventListener('click', function() {
            if (state.isLoggedIn) {
                openLogoutSheet();
                return;
            }
            $$('.auth-buttons .auth-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            openLoginPage();
        });
    }

    const registerBtn = $('registerBtn');
    if (registerBtn) {
        registerBtn.addEventListener('click', function() {
            if (state.isLoggedIn) { openLogoutSheet(); return; }
            $$('.auth-buttons .auth-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            openRegisterPage();
        });
    }

    const switchToRegister = $('switchToRegister');
    if (switchToRegister) {
        switchToRegister.addEventListener('click', function(e) {
            e.preventDefault();
            closeLoginPage();
            setTimeout(openRegisterPage, 300);
        });
    }

    const switchToLogin = $('switchToLogin');
    if (switchToLogin) {
        switchToLogin.addEventListener('click', function(e) {
            e.preventDefault();
            closeRegisterPage();
            setTimeout(openLoginPage, 300);
        });
    }

    const notifBtn = $('notifBtn');
    if (notifBtn) {
        notifBtn.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-notifications');
            renderNotifications();
        });
    }

    const chatSupport = $('chatSupportMenuItem');
    if (chatSupport) {
        chatSupport.addEventListener('click', function() {
            if (!requireLogin()) return;
            openUserChat();
        });
    }

    const favoritesMenuItem = $('favoritesMenuItem');
    if (favoritesMenuItem) {
        favoritesMenuItem.addEventListener('click', function() {
            openFavorites();
        });
    }

    const ordersMenuItem = $('ordersMenuItem');
    if (ordersMenuItem) {
        ordersMenuItem.addEventListener('click', function() {
            openOrders();
        });
    }

    const rechargeMenuItem = $('rechargeMenuItem');
    if (rechargeMenuItem) {
        rechargeMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openRechargePage();
        });
    }

    const settingsMenuItem = $('accountSettingsMenuItem');
    if (settingsMenuItem) {
        settingsMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-account-settings');
        });
    }

    const addBalanceBtn = $('addBalanceBtn');
    if (addBalanceBtn) {
        addBalanceBtn.addEventListener('click', function() {
            if (!requireLogin()) return;
            openRechargePage();
        });
    }

    const adminPanelMenuItem = $('adminPanelMenuItem');
    if (adminPanelMenuItem) {
        adminPanelMenuItem.addEventListener('click', function() {
            if (!state.isAdmin || !state.isLoggedIn) { showToast('غير مصرح لك بالدخول'); return; }
            window.location.href = window.APP_CONFIG.adminUrl;
        });
    }

    const languageMenuItem = $('languageMenuItem');
    if (languageMenuItem) {
        languageMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-language-settings');
        });
    }

    const userInput = $('userChatInput');
    if (userInput) {
        userInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendUserMessage();
            }
        });
    }

    // ===== إغلاق بطاقة تفاصيل الشحن بالضغط على الخلفية =====
    document.addEventListener('click', function(e) {
        const overlay = document.querySelector('.recharge-detail-overlay');
        if (overlay && e.target === overlay) {
            closeRechargeDetail();
        }
    });

    updateAccountUI();
    updateBalanceDisplay();
    updateNotifBadge();
    updateBottomNavVisibility();

    console.log('🚀 Tokmart Ultra-Smooth Native App Ready!');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
document.addEventListener('touchmove', function(e) {
    const scroll = document.getElementById('scrollArea');

    if (scroll) {
        scroll.style.overflowY = 'auto';
    }
}, { passive: true });
