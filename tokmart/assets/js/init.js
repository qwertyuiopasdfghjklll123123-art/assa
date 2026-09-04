/* ============================================================
   Tokmart - App bootstrap
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
    
    updateBalanceDisplay();
    updateAccountUI();
    
    loadFavorites();
    loadSiteData();
    loadAllData(false);
    setupSearch();
    updateCountdown();
    setInterval(updateCountdown, 1000);

    setTimeout(initGoogleLogin, 300);
    setTimeout(initGoogleLogin, 1000);
    setTimeout(initGoogleLogin, 2000);

    $$('.nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchPage(this.dataset.page); });
    });

    $$('.back-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const page = this.closest('.settings-page, .product-detail-page, .all-products-page, .category-products-page, .favorites-page, .orders-page, .order-detail-page, .recharge-page, .admin-page, .auth-page, .chat-app-user');
            if (page) {
                if (page.classList.contains('chat-app-user')) {
                    closeUserChat();
                } else if (page.classList.contains('admin-page')) {
                    closeAdminPage();
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

    const adminBtn = $('adminBtn');
    if (adminBtn) {
        adminBtn.addEventListener('click', function() {
            if (!state.isAdmin || !state.isLoggedIn) { showToast('غير مصرح لك بالدخول'); return; }
            window.location.href = window.APP_CONFIG.adminUrl;
        });
    }

    const adminPanelMenuItem = $('adminPanelMenuItem');
    if (adminPanelMenuItem) {
        adminPanelMenuItem.addEventListener('click', function() {
            if (!state.isAdmin || !state.isLoggedIn) { showToast('غير مصرح لك بالدخول'); return; }
            window.location.href = window.APP_CONFIG.adminUrl;
        });
    }

    const closeAdminBtn = $('closeAdminBtn');
    if (closeAdminBtn) {
        closeAdminBtn.addEventListener('click', function() { closeAdminPage(); });
    }

    $$('.admin-tabs button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            $$('.admin-tabs button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            $$('.admin-tab-content').forEach(function(tc) { tc.classList.remove('active'); });
            const target = $(this.dataset.tab);
            if (target) target.classList.add('active');
            if (this.dataset.tab === 'tab-chats') loadAdminChats();
            if (this.dataset.tab === 'tab-recharges') renderAdminRecharges();
        });
    });

    const addProductBtn = $('addProductBtn');
    if (addProductBtn) {
        addProductBtn.addEventListener('click', function() { openProductForm(); });
    }

    const cancelProductForm = $('cancelProductForm');
    if (cancelProductForm) {
        cancelProductForm.addEventListener('click', closeProductForm);
    }

    const addCategoryBtn = $('addCategoryBtn');
    if (addCategoryBtn) {
        addCategoryBtn.addEventListener('click', function() { openCategoryForm(); });
    }

    const cancelCategoryForm = $('cancelCategoryForm');
    if (cancelCategoryForm) {
        cancelCategoryForm.addEventListener('click', closeCategoryForm);
    }

    const addPaymentBtn = $('addPaymentBtn');
    if (addPaymentBtn) {
        addPaymentBtn.addEventListener('click', function() { openPaymentForm(); });
    }

    const closeSuccessBtn = $('closeSuccessBtn');
    if (closeSuccessBtn) {
        closeSuccessBtn.addEventListener('click', function() {
            closePage('orderSuccessOverlay');
        });
    }

    document.addEventListener('click', function(e) {
        const card = e.target.closest('.product-card');
        if (card && !e.target.closest('.add-btn') && !e.target.closest('.favorite-icon')) {
            const id = parseInt(card.dataset.id);
            const product = state.products.find(function(p) { return p.id === id; });
            if (product) openProductDetail(product.id);
        }
    });

    const adminSearch = $('adminChatSearch');
    if (adminSearch) {
        adminSearch.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.admin-user-item').forEach(function(el) {
                const name = el.querySelector('.name') ? el.querySelector('.name').textContent : '';
                el.style.display = name.toLowerCase().includes(q) ? '' : 'none';
            });
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

    const iconInput = $('categoryIcon');
    if (iconInput) {
        iconInput.addEventListener('input', function() {
            const preview = $('categoryIconPreview');
            if (preview) {
                const iconClass = this.value.trim() || 'fa-solid fa-tag';
                preview.innerHTML = '<i class="' + iconClass + '"></i>';
            }
        });
    }

    const iconImageInput = $('categoryIconImage');
    if (iconImageInput) {
        iconImageInput.addEventListener('change', function() {
            const preview = $('categoryIconPreviewImg');
            if (this.files && this.files[0] && preview) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    const bannerImageInput = $('categoryBannerImage');
    if (bannerImageInput) {
        bannerImageInput.addEventListener('change', function() {
            const preview = $('categoryBannerPreview');
            if (this.files && this.files[0] && preview) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    const languageMenuItem = $('languageMenuItem');
    if (languageMenuItem) {
        languageMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-language-settings');
        });
    }

    // ===== معاينة الصور المتعددة =====
    document.addEventListener('change', function(e) {
        if (e.target.id === 'productImages') {
            const preview = $('productImagesPreview');
            if (!preview) return;
            preview.innerHTML = '';
            const files = e.target.files;
            for (let i = 0; i < files.length; i++) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const div = document.createElement('div');
                    div.style.cssText = 'width:80px;height:80px;border-radius:8px;overflow:hidden;border:2px solid var(--border);position:relative;';
                    div.innerHTML = `
                        <img src="${event.target.result}" style="width:100%;height:100%;object-fit:cover;">
                        <span style="position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;cursor:pointer;" onclick="this.parentElement.remove()">×</span>
                    `;
                    preview.appendChild(div);
                };
                reader.readAsDataURL(files[i]);
            }
        }
    });

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

