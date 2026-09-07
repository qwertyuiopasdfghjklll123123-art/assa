/* ============================================================
   Tokmart - Google sign-in, OTP register, password reset, login/logout,
   account settings, notification list
   ============================================================ */

// ===== معاينة الصورة الشخصية =====
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const container = input.closest('div').querySelector('div:first-child');
            if (container) {
                container.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function togglePassword(btn) {
    const input = btn.closest('div').querySelector('input');
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

// ============================================================
// GOOGLE LOGIN
// ============================================================

let googleLoginInitialized = false;
let googleInitAttempts = 0;
const MAX_GOOGLE_INIT_ATTEMPTS = 10;

function initGoogleLogin() {
    const clientId = window.APP_CONFIG.googleClientId;
    const googleEnabled = window.APP_CONFIG.googleEnabled;

    if (!googleEnabled) {
        ['googleLoginContainer', 'googleRegisterContainer'].forEach(function(id) {
            const container = document.getElementById(id);
            if (container) {
                container.innerHTML = `
                    <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                        <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                        ⚠️ تسجيل الدخول عبر Google معطل حالياً
                    </div>
                `;
            }
        });
        return;
    }

    if (typeof window.google === 'undefined' || typeof window.google.accounts === 'undefined') {
        googleInitAttempts++;
        if (googleInitAttempts < MAX_GOOGLE_INIT_ATTEMPTS) {
            setTimeout(initGoogleLogin, 800);
        } else {
            showFallbackGoogleButtons();
        }
        return;
    }

    try {
        if (!googleLoginInitialized) {
            window.google.accounts.id.initialize({
                client_id: clientId,
                callback: handleGoogleLogin,
                auto_prompt: false,
                cancel_on_tap_outside: true,
                context: 'signin'
            });
            googleLoginInitialized = true;
        }

        renderGoogleButton('googleLoginContainer', 'sign_in_with');
        renderGoogleButton('googleRegisterContainer', 'sign_up_with');
    } catch (e) {
        console.warn('⚠️ Google Login init error:', e);
        showFallbackGoogleButtons();
    }
}

function renderGoogleButton(containerId, buttonText) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';
    const buttonWrapper = document.createElement('div');
    buttonWrapper.style.cssText = 'display:flex;justify-content:center;width:100%;';
    const buttonDiv = document.createElement('div');
    buttonDiv.className = 'g_id_signin';
    buttonDiv.style.cssText = 'width:100%;max-width:320px;';
    buttonWrapper.appendChild(buttonDiv);
    container.appendChild(buttonWrapper);

    try {
        window.google.accounts.id.renderButton(
            buttonDiv,
            { type: 'standard', size: 'large', theme: 'outline', text: buttonText, shape: 'rectangular', logo_alignment: 'center', width: 320 }
        );
    } catch (e) {
        buttonDiv.innerHTML = `
            <button onclick="initGoogleLogin()" style="width:100%;padding:12px;border:2px solid #EA4335;border-radius:10px;background:#fff;color:#EA4335;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;">
                <i class="fa-brands fa-google" style="font-size:20px;"></i>
                ${buttonText === 'sign_in_with' ? 'تسجيل الدخول عبر Google' : 'إنشاء حساب عبر Google'}
            </button>
        `;
    }
}

function showFallbackGoogleButtons() {
    ['googleLoginContainer', 'googleRegisterContainer'].forEach(function(id) {
        const container = document.getElementById(id);
        if (container) {
            container.innerHTML = `
                <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                    <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                    <button onclick="initGoogleLogin()" style="padding:8px 20px;border:2px solid #EA4335;border-radius:8px;background:#fff;color:#EA4335;font-weight:700;font-size:13px;cursor:pointer;margin-top:6px;">
                        <i class="fa-brands fa-google"></i> محاولة تسجيل الدخول عبر Google
                    </button>
                    <div style="font-size:11px;margin-top:6px;">يرجى استخدام طرق التسجيل التقليدية</div>
                </div>
            `;
        }
    });
}

function handleGoogleLogin(response) {
    const idToken = response.credential;
    if (!idToken) {
        showToast('⚠️ فشل تسجيل الدخول بواسطة Google - لم يتم استلام التوكن');
        return;
    }

    const googleBtns = document.querySelectorAll('.g_id_signin');
    googleBtns.forEach(function(btn) {
        btn.style.opacity = '0.5';
        btn.style.pointerEvents = 'none';
    });

    showToast('⏳ جاري التحقق من حساب Google...');

    callAPI('googleLogin', { id_token: idToken }, 'POST')
        .then(function(result) {
            googleBtns.forEach(function(btn) {
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });

            if (result.success) {
                const user = result.data;
                state.isLoggedIn = true;
                state.user = user;
                state.balance = user.balance || 0;
                state.isAdmin = user.isAdmin || false;
                state.isVerified = user.isVerified || false;

                try {
                    localStorage.setItem('Tokmart_user', JSON.stringify({
                        id: user.id, name: user.name, email: user.email, isAdmin: user.isAdmin,
                        isVerified: user.isVerified, balance: user.balance, avatar_path: user.avatar_path
                    }));
                } catch (e) {}

                updateBalanceDisplay();
                updateAccountUI();
                closeLoginPage();
                closeRegisterPage();
                renderAll();
                showToast('👋 مرحباً ' + user.name + ' (Google)');
                if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
                requestNotificationPermission();
            } else {
                showToast('❌ ' + (result.message || 'فشل تسجيل الدخول عبر Google'));
            }
        })
        .catch(function() {
            googleBtns.forEach(function(btn) {
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });
            showToast('❌ حدث خطأ في الاتصال، يرجى المحاولة مرة أخرى');
        });
}

// ============================================================
// OTP REGISTRATION
// ============================================================

let otpCode = '';
let otpResendTimer = null;
let otpResendSeconds = 60;

function handleRegister() {
    const name = $('registerName') ? $('registerName').value.trim() : '';
    const email = $('registerEmail') ? $('registerEmail').value.trim() : '';
    const phone = $('registerPhone') ? $('registerPhone').value.trim() : '';
    const password = $('registerPassword') ? $('registerPassword').value.trim() : '';
    const errorEl = $('registerError');

    if (!name || !email || !phone || !password) {
        if (errorEl) { errorEl.textContent = t('required_field'); errorEl.style.display = 'block'; }
        return;
    }

    if (!/^07[0-9]{8,10}$/.test(phone)) {
        if (errorEl) { errorEl.textContent = t('invalid_phone'); errorEl.style.display = 'block'; }
        return;
    }

    callAPI('registerWithOTP', { name: name, email: email, phone: phone, password: password }, 'POST').then(function(response) {
        if (response.success) {
            const emailDisplay = $('otpEmailDisplay');
            if (emailDisplay) emailDisplay.textContent = email;
            closeRegisterPage();
            openOTPPage();
            showToast('✅ تم إرسال رمز التحقق');
            startResendTimer();
        } else if (errorEl) {
            errorEl.textContent = response.message || t('error');
            errorEl.style.display = 'block';
        }
    });
}

function otpInputHandler(input, index) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length === 1) {
        input.classList.add('filled');
        input.style.borderColor = 'var(--primary)';
        input.style.background = 'var(--surface)';
        const next = input.nextElementSibling;
        if (next && next.classList.contains('otp-input')) next.focus();
    } else {
        input.classList.remove('filled');
        input.style.borderColor = 'var(--border)';
        input.style.background = 'var(--bg2)';
    }

    let code = '';
    document.querySelectorAll('.otp-input').forEach(function(inp) { code += inp.value; });
    otpCode = code;

    if (code.length === 6) verifyOTP();
}

function verifyOTP() {
    const errorEl = $('otpError');
    const successEl = $('otpSuccess');
    if (errorEl) errorEl.style.display = 'none';
    if (successEl) successEl.style.display = 'none';

    if (otpCode.length !== 6) {
        if (errorEl) { errorEl.textContent = 'الرجاء إدخال رمز التحقق الكامل (6 أرقام)'; errorEl.style.display = 'block'; }
        return;
    }

    const verifyBtn = document.querySelector('#otpVerificationPage .btn-submit');
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('loading');
    }

    callAPI('verifyOTP', { otp: otpCode }, 'POST').then(function(response) {
        if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.innerHTML = 'تحقق'; }

        if (response.success) {
            if (successEl) { successEl.textContent = '✅ تم التحقق بنجاح! جاري تسجيل الدخول...'; successEl.style.display = 'block'; }
            const user = response.data;
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = true;

            try {
                localStorage.setItem('Tokmart_user', JSON.stringify({
                    id: user.id, name: user.name, email: user.email, isAdmin: user.isAdmin,
                    isVerified: user.isVerified, balance: user.balance, avatar_path: user.avatar_path
                }));
            } catch (e) {}

            updateBalanceDisplay();
            updateAccountUI();
            closeOTPPage();
            renderAll();
            showToast('🎉 مرحباً ' + user.name);
            if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
            requestNotificationPermission();
        } else {
            if (errorEl) { errorEl.textContent = response.message || 'رمز التحقق غير صحيح'; errorEl.style.display = 'block'; }
            document.querySelectorAll('.otp-input').forEach(function(inp) {
                inp.value = '';
                inp.classList.remove('filled');
                inp.style.borderColor = 'var(--border)';
                inp.style.background = 'var(--bg2)';
            });
            otpCode = '';
            setTimeout(function() { document.querySelector('.otp-input').focus(); }, 300);
        }
    }).catch(function() {
        if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.innerHTML = 'تحقق'; }
        if (errorEl) { errorEl.textContent = 'حدث خطأ في الاتصال بالخادم'; errorEl.style.display = 'block'; }
    });
}

function resendOTP() {
    if (otpResendSeconds > 0) return;
    callAPI('resendOTP', {}, 'POST').then(function(response) {
        if (response.success) {
            showToast('✅ تم إرسال رمز تحقق جديد');
            startResendTimer();
        } else {
            showToast('❌ ' + (response.message || 'فشل إرسال الرمز'));
        }
    });
}

function startResendTimer() {
    otpResendSeconds = 60;
    const timerEl = $('resendTimer');
    const resendEl = $('resendOTP');
    if (timerEl) timerEl.textContent = '(60 ثانية)';
    if (resendEl) { resendEl.style.pointerEvents = 'none'; resendEl.style.opacity = '0.5'; }

    clearInterval(otpResendTimer);
    otpResendTimer = setInterval(function() {
        otpResendSeconds--;
        if (timerEl) timerEl.textContent = '(' + otpResendSeconds + ' ثانية)';
        if (otpResendSeconds <= 0) {
            clearInterval(otpResendTimer);
            if (timerEl) timerEl.textContent = '';
            if (resendEl) { resendEl.style.pointerEvents = 'auto'; resendEl.style.opacity = '1'; }
        }
    }, 1000);
}

function openOTPPage() {
    const page = $('otpVerificationPage');
    if (page) {
        page.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
    }
}

function closeOTPPage() {
    const page = $('otpVerificationPage');
    if (page) {
        page.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
        clearInterval(otpResendTimer);
    }
}

// ============================================================
// FORGOT PASSWORD
// ============================================================

let resetEmail = '';
let resetCode = '';
let resetResendTimer = null;
let resetResendSeconds = 60;

function openForgotPasswordPage() {
    const loginPage = $('loginPage');
    if (loginPage) loginPage.classList.remove('open');
    const registerPage = $('registerPage');
    if (registerPage) registerPage.classList.remove('open');

    openPage('forgotPasswordPage');
    resetStep1();
}

function closeForgotPasswordPage() {
    closePage('forgotPasswordPage');
    resetStep1();
}

function resetStep1() {
    const step1 = $('resetStep1'), step2 = $('resetStep2'), step3 = $('resetStep3');
    if (step1) step1.style.display = 'block';
    if (step2) step2.style.display = 'none';
    if (step3) step3.style.display = 'none';
    const errorEl = $('resetError');
    if (errorEl) errorEl.style.display = 'none';
}

function resetStep2() {
    const step1 = $('resetStep1'), step2 = $('resetStep2'), step3 = $('resetStep3');
    if (step1) step1.style.display = 'none';
    if (step2) step2.style.display = 'block';
    if (step3) step3.style.display = 'none';

    document.querySelectorAll('#resetOtpContainer .otp-input').forEach(function(inp) {
        inp.value = '';
        inp.classList.remove('filled');
        inp.style.borderColor = 'var(--border)';
        inp.style.background = 'var(--bg2)';
    });
    resetCode = '';
    setTimeout(function() { document.querySelector('#resetOtpContainer .otp-input').focus(); }, 300);
}

function resetStep3() {
    const step1 = $('resetStep1'), step2 = $('resetStep2'), step3 = $('resetStep3');
    if (step1) step1.style.display = 'none';
    if (step2) step2.style.display = 'none';
    if (step3) step3.style.display = 'block';
}

function requestPasswordReset() {
    const email = $('resetEmail') ? $('resetEmail').value.trim() : '';
    const errorEl = $('resetError');

    if (!email) {
        if (errorEl) { errorEl.textContent = 'الرجاء إدخال البريد الإلكتروني'; errorEl.style.display = 'block'; }
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (errorEl) { errorEl.textContent = 'البريد الإلكتروني غير صحيح'; errorEl.style.display = 'block'; }
        return;
    }

    const btn = document.querySelector('#resetStep1 .btn-submit');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...'; }
    if (errorEl) errorEl.style.display = 'none';

    callAPI('requestPasswordReset', { email: email }, 'POST')
        .then(function(response) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق'; }

            if (response.success) {
                resetEmail = email;
                const display = $('resetEmailDisplay');
                if (display) display.textContent = email;
                resetStep2();
                startResetResendTimer();
                showToast('✅ تم إرسال كود التحقق إلى بريدك');
            } else if (errorEl) {
                errorEl.textContent = response.message || 'فشل إرسال الكود';
                errorEl.style.display = 'block';
            }
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق'; }
            if (errorEl) { errorEl.textContent = 'حدث خطأ في الاتصال'; errorEl.style.display = 'block'; }
        });
}

function resetOtpInputHandler(input, index) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length === 1) {
        input.classList.add('filled');
        input.style.borderColor = 'var(--primary)';
        input.style.background = 'var(--surface)';
        const next = input.nextElementSibling;
        if (next && next.classList.contains('otp-input')) next.focus();
    } else {
        input.classList.remove('filled');
        input.style.borderColor = 'var(--border)';
        input.style.background = 'var(--bg2)';
    }

    let code = '';
    document.querySelectorAll('#resetOtpContainer .otp-input').forEach(function(inp) { code += inp.value; });
    resetCode = code;

    if (code.length === 6) verifyResetCode();
}

function verifyResetCode() {
    const errorEl = $('resetCodeError');
    if (errorEl) errorEl.style.display = 'none';

    if (resetCode.length !== 6) {
        if (errorEl) { errorEl.textContent = 'الرجاء إدخال الرمز الكامل (6 أرقام)'; errorEl.style.display = 'block'; }
        return;
    }

    const btn = document.querySelector('#resetStep2 .btn-submit');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التحقق...'; }

    callAPI('verifyResetCode', { email: resetEmail, code: resetCode }, 'POST')
        .then(function(response) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق'; }

            if (response.success) {
                resetStep3();
                showToast('✅ تم التحقق من الكود بنجاح');
            } else {
                if (errorEl) { errorEl.textContent = response.message || 'الكود غير صحيح'; errorEl.style.display = 'block'; }
                document.querySelectorAll('#resetOtpContainer .otp-input').forEach(function(inp) {
                    inp.value = '';
                    inp.classList.remove('filled');
                    inp.style.borderColor = 'var(--border)';
                    inp.style.background = 'var(--bg2)';
                });
                resetCode = '';
                document.querySelector('#resetOtpContainer .otp-input').focus();
            }
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق'; }
            if (errorEl) { errorEl.textContent = 'حدث خطأ في الاتصال'; errorEl.style.display = 'block'; }
        });
}

function resendResetCode() {
    if (resetResendSeconds > 0) return;

    callAPI('requestPasswordReset', { email: resetEmail }, 'POST')
        .then(function(response) {
            if (response.success) {
                showToast('✅ تم إرسال كود جديد');
                startResetResendTimer();
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال الكود'));
            }
        })
        .catch(function() {
            showToast('❌ حدث خطأ في الاتصال');
        });
}

function startResetResendTimer() {
    resetResendSeconds = 60;
    const timerEl = $('resetResendTimer');
    const resendEl = document.querySelector('#resetStep2 a[onclick="resendResetCode()"]');
    if (timerEl) timerEl.textContent = '(60 ثانية)';
    if (resendEl) { resendEl.style.pointerEvents = 'none'; resendEl.style.opacity = '0.5'; }

    clearInterval(resetResendTimer);
    resetResendTimer = setInterval(function() {
        resetResendSeconds--;
        if (timerEl) timerEl.textContent = '(' + resetResendSeconds + ' ثانية)';
        if (resetResendSeconds <= 0) {
            clearInterval(resetResendTimer);
            if (timerEl) timerEl.textContent = '';
            if (resendEl) { resendEl.style.pointerEvents = 'auto'; resendEl.style.opacity = '1'; }
        }
    }, 1000);
}

function resetPassword() {
    const newPassword = $('resetNewPassword') ? $('resetNewPassword').value : '';
    const confirmPassword = $('resetConfirmPassword') ? $('resetConfirmPassword').value : '';
    const errorEl = $('resetPasswordError');
    if (errorEl) errorEl.style.display = 'none';

    if (!newPassword || newPassword.length < 6) {
        if (errorEl) { errorEl.textContent = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'; errorEl.style.display = 'block'; }
        return;
    }
    if (newPassword !== confirmPassword) {
        if (errorEl) { errorEl.textContent = 'كلمات المرور غير متطابقة'; errorEl.style.display = 'block'; }
        return;
    }

    const btn = document.querySelector('#resetStep3 .btn-submit');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التغيير...'; }

    callAPI('resetPassword', { email: resetEmail, code: resetCode, new_password: newPassword }, 'POST')
        .then(function(response) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> تغيير كلمة المرور'; }

            if (response.success) {
                showToast('✅ تم تغيير كلمة المرور بنجاح');
                closeForgotPasswordPage();
                setTimeout(function() {
                    openLoginPage();
                    const identifier = $('loginIdentifier');
                    if (identifier) identifier.value = resetEmail;
                    showToast('🔑 يمكنك الآن تسجيل الدخول بكلمة المرور الجديدة');
                }, 500);
            } else if (errorEl) {
                errorEl.textContent = response.message || 'فشل تغيير كلمة المرور';
                errorEl.style.display = 'block';
            }
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> تغيير كلمة المرور'; }
            if (errorEl) { errorEl.textContent = 'حدث خطأ في الاتصال'; errorEl.style.display = 'block'; }
        });
}

// ============================================================
// LOGOUT BOTTOM SHEET
// ============================================================

function openLogoutSheet() {
    const overlay = $('logoutSheetOverlay');
    const sheet = $('logoutBottomSheet');
    if (overlay) overlay.classList.add('open');
    if (sheet) sheet.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLogoutSheet() {
    const overlay = $('logoutSheetOverlay');
    const sheet = $('logoutBottomSheet');
    if (overlay) overlay.classList.remove('open');
    if (sheet) sheet.classList.remove('open');
    document.body.style.overflow = '';
}

// ============================================================
// NOTIFICATIONS
// ============================================================

function renderNotifications() {
    const container = $('notificationsList');
    if (!container) return;

    if (state.notifications.length === 0) {
        container.innerHTML = `
            <div class="notifications-empty">
                <i class="fa-regular fa-bell-slash"></i>
                <div class="title">لا توجد إشعارات</div>
                <div class="sub">ستظهر الإشعارات هنا عند توفرها</div>
            </div>
        `;
        return;
    }

    const sortedNotifs = [...state.notifications].sort(function(a, b) {
        return new Date(b.createdAt) - new Date(a.createdAt);
    });

    const unreadCount = sortedNotifs.filter(function(n) { return !n.isRead; }).length;

    let html = `
        <div class="notif-header">
            <div class="notif-title">
                <i class="fa-regular fa-bell" style="color:var(--primary);"></i>
                الإشعارات
                <span class="count">${sortedNotifs.length}</span>
                ${unreadCount > 0 ? `<span style="font-size:11px;color:var(--red);background:rgba(231,76,60,.1);padding:2px 10px;border-radius:20px;">${unreadCount} جديد</span>` : ''}
            </div>
            <div class="notif-actions">
                ${unreadCount > 0 ? `
                    <button class="btn-mark-all" onclick="markAllNotificationsRead()">
                        <i class="fa-solid fa-check-double"></i> كمقروء
                    </button>
                ` : ''}
                <button class="btn-delete-all" onclick="deleteAllNotifications()">
                    <i class="fa-solid fa-trash"></i> حذف
                </button>
            </div>
        </div>
        <div class="notifications-container">
    `;

    sortedNotifs.forEach(function(n) {
        const icons = { offer: 'fa-tag', order: 'fa-box', wallet: 'fa-wallet', info: 'fa-bell', chat: 'fa-comment' };
        const icon = icons[n.type] || 'fa-bell';
        const time = new Date(n.createdAt);
        const now = new Date();
        const diff = Math.floor((now - time) / 60000);
        let timeStr;
        if (diff < 1) timeStr = 'الآن';
        else if (diff < 60) timeStr = 'منذ ' + diff + ' دقيقة';
        else if (diff < 1440) timeStr = 'منذ ' + Math.floor(diff / 60) + ' ساعة';
        else timeStr = 'منذ ' + Math.floor(diff / 1440) + ' يوم';
        const isRead = n.isRead || false;
        const typeClass = n.type || 'info';

        html += `
            <div class="notification-item ${isRead ? '' : 'unread'}"
                 data-id="${n.id}"
                 onclick="toggleNotificationRead(${n.id})"
                 ontouchstart="handleSwipeStart(event, this)"
                 ontouchmove="handleSwipeMove(event, this)"
                 ontouchend="handleSwipeEnd(event, this)">
                <div class="unread-dot"></div>
                <div class="notif-icon ${typeClass}">
                    <i class="fa-solid ${icon}"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">${n.title}</div>
                    <div class="notif-desc">${n.message}</div>
                    <div class="notif-time">
                        <i class="fa-regular fa-clock"></i> ${timeStr}
                    </div>
                </div>
                <div class="swipe-hint"><i class="fa-solid fa-trash"></i> اسحب للحذف</div>
            </div>
        `;
    });

    html += `</div>`;
    container.innerHTML = html;
}

function toggleNotificationRead(id) {
    const notification = state.notifications.find(function(n) { return n.id == id; });
    if (!notification) return;

    const newStatus = notification.isRead ? 0 : 1;
    notification.isRead = newStatus;
    updateNotifBadge();
    renderNotifications();

    if (newStatus === 1) {
        callAPI('markNotificationRead', { id: id }, 'POST').then(function(response) {
            if (!response.success) {
                notification.isRead = 0;
                updateNotifBadge();
                renderNotifications();
            }
        });
    }
}

function markAllNotificationsRead() {
    if (!requireLogin()) return;

    state.notifications.forEach(function(n) { n.isRead = 1; });
    updateNotifBadge();
    renderNotifications();

    callAPI('markAllNotificationsRead', {}, 'POST').then(function(response) {
        if (!response.success) loadAllData(true);
    });
}

function deleteAllNotifications() {
    if (!requireLogin()) return;

    state.notifications = [];
    updateNotifBadge();
    renderNotifications();

    callAPI('deleteAllNotifications', {}, 'POST').then(function(response) {
        if (!response.success) loadAllData(true);
    });
}

function deleteNotification(id) {
    if (!requireLogin()) return;

    const index = state.notifications.findIndex(function(n) { return n.id == id; });
    if (index !== -1) {
        state.notifications.splice(index, 1);
        updateNotifBadge();
        renderNotifications();
    }

    callAPI('deleteNotification', { id: id }, 'POST').then(function(response) {
        if (!response.success) loadAllData(true);
    });
}

function updateNotifBadge() {
    const unread = state.notifications.filter(function(n) { return !n.isRead; }).length;
    const badge = $('notifBadge');
    if (badge) {
        badge.textContent = unread;
        badge.style.display = unread > 0 ? 'flex' : 'none';
    }
}

// ===== دوال السحب للحذف =====
let swipeData = { startX: 0, currentX: 0, isSwiping: false, element: null };

function handleSwipeStart(event, element) {
    const touch = event.touches[0];
    swipeData.startX = touch.clientX;
    swipeData.currentX = touch.clientX;
    swipeData.isSwiping = false;
    swipeData.element = element;
    element.classList.remove('swiped');
}

function handleSwipeMove(event, element) {
    const touch = event.touches[0];
    const diffX = touch.clientX - swipeData.startX;

    if (diffX < -20) {
        swipeData.isSwiping = true;
        const offset = Math.max(diffX, -150);
        element.style.setProperty('--swipe-offset', offset + 'px');
        element.classList.add('swiping');
        element.style.opacity = 1 - (Math.abs(offset) / 200);
    } else {
        element.classList.remove('swiping');
        element.style.setProperty('--swipe-offset', '0px');
        element.style.opacity = '1';
    }
}

function handleSwipeEnd(event, element) {
    const diffX = event.changedTouches[0].clientX - swipeData.startX;
    element.classList.remove('swiping');

    if (diffX < -80) {
        const id = parseInt(element.dataset.id);
        element.classList.add('swiped');
        setTimeout(function() { deleteNotification(id); }, 400);
    } else {
        element.style.setProperty('--swipe-offset', '0px');
        element.style.opacity = '1';
    }
}

// ===== دعم السحب بالفأرة =====
let mouseSwipeData = { startX: 0, isDragging: false, element: null };

document.addEventListener('mousedown', function(e) {
    const element = e.target.closest('.notification-item');
    if (!element) return;
    mouseSwipeData.startX = e.clientX;
    mouseSwipeData.isDragging = false;
    mouseSwipeData.element = element;
    element.classList.remove('swiped');
});

document.addEventListener('mousemove', function(e) {
    if (!mouseSwipeData.element) return;
    const diffX = e.clientX - mouseSwipeData.startX;
    if (Math.abs(diffX) > 10) mouseSwipeData.isDragging = true;

    if (mouseSwipeData.isDragging && diffX < 0) {
        const offset = Math.max(diffX, -150);
        mouseSwipeData.element.style.setProperty('--swipe-offset', offset + 'px');
        mouseSwipeData.element.classList.add('swiping');
        mouseSwipeData.element.style.opacity = 1 - (Math.abs(offset) / 200);
    }
});

document.addEventListener('mouseup', function(e) {
    if (!mouseSwipeData.element) return;
    const diffX = e.clientX - mouseSwipeData.startX;
    mouseSwipeData.element.classList.remove('swiping');

    if (mouseSwipeData.isDragging && diffX < -80) {
        const id = parseInt(mouseSwipeData.element.dataset.id);
        mouseSwipeData.element.classList.add('swiped');
        setTimeout(function() { deleteNotification(id); }, 400);
    } else {
        mouseSwipeData.element.style.setProperty('--swipe-offset', '0px');
        mouseSwipeData.element.style.opacity = '1';
    }

    mouseSwipeData.isDragging = false;
    mouseSwipeData.element = null;
});

// ============================================================
// ACCOUNT FUNCTIONS
// ============================================================

function updateBalanceDisplay() {
    const el = $('userBalance');
    if (el) el.textContent = state.balance.toFixed(0) + ' د.ع';
}

function updateAccountUI() {
    const loginBtn = $('loginBtn');
    const registerBtn = $('registerBtn');
    const adminPanelMenuItem = $('adminPanelMenuItem');

    if (state.isLoggedIn && state.user) {
        if (loginBtn) { loginBtn.textContent = '👤 ' + state.user.name; loginBtn.classList.add('active'); }
        if (registerBtn) { registerBtn.textContent = '🚪 تسجيل الخروج'; registerBtn.classList.remove('active'); }
        if (adminPanelMenuItem) adminPanelMenuItem.style.display = state.isAdmin ? 'flex' : 'none';
    } else {
        if (loginBtn) { loginBtn.innerHTML = '🔑 <span data-i18n="login">' + t('login') + '</span>'; loginBtn.classList.add('active'); }
        if (registerBtn) { registerBtn.innerHTML = '📝 <span data-i18n="register">' + t('register') + '</span>'; registerBtn.classList.remove('active'); }
        if (adminPanelMenuItem) adminPanelMenuItem.style.display = 'none';
    }

    updateLanguageDisplay();
}

function updateLanguageDisplay() {
    const display = $('currentLangDisplay');
    if (display) {
        display.innerHTML = '<i class="fa-solid fa-check" style="font-size:var(--fs-xs);color:var(--green);margin-left:4px;"></i>'
            + (currentLang === 'ar' ? 'العربية' : 'English');
    }
}

function renderLanguageOptions() {
    const ar = document.getElementById('langOptionAr');
    const en = document.getElementById('langOptionEn');
    if (ar) ar.classList.toggle('selected', currentLang === 'ar');
    if (en) en.classList.toggle('selected', currentLang === 'en');
}

function openLoginPage() {
    openPage('loginPage');
    const errorEl = $('loginError');
    if (errorEl) errorEl.style.display = 'none';
    setTimeout(initGoogleLogin, 300);
}

function closeLoginPage() { closePage('loginPage'); }

function openRegisterPage() {
    openPage('registerPage');
    const errorEl = $('registerError');
    if (errorEl) errorEl.style.display = 'none';
    setTimeout(initGoogleLogin, 300);
}

function closeRegisterPage() { closePage('registerPage'); }

function handleLogin() {
    const identifier = $('loginIdentifier') ? $('loginIdentifier').value.trim() : '';
    const password = $('loginPassword') ? $('loginPassword').value.trim() : '';
    const errorEl = $('loginError');

    if (!identifier || !password) {
        if (errorEl) { errorEl.textContent = t('required_field'); errorEl.style.display = 'block'; }
        return;
    }

    const loginBtn = document.querySelector('#loginForm .btn-submit');
    if (loginBtn) {
        loginBtn.disabled = true;
        loginBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('loading');
    }

    callAPI('login', { identifier: identifier, password: password }, 'POST').then(function(response) {
        if (loginBtn) { loginBtn.disabled = false; loginBtn.innerHTML = t('login'); }

        if (response.success) {
            const user = response.data;
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;

            try {
                localStorage.setItem('Tokmart_user', JSON.stringify({
                    id: user.id, name: user.name, email: user.email, isAdmin: user.isAdmin,
                    isVerified: user.isVerified, balance: user.balance, avatar_path: user.avatar_path
                }));
            } catch (e) {}

            updateBalanceDisplay();
            updateAccountUI();
            closeLoginPage();
            renderAll();
            showToast('👋 مرحباً ' + user.name);
            if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
            requestNotificationPermission();
        } else if (errorEl) {
            errorEl.textContent = response.message || 'بيانات الدخول غير صحيحة';
            errorEl.style.display = 'block';
        }
    }).catch(function() {
        if (loginBtn) { loginBtn.disabled = false; loginBtn.innerHTML = t('login'); }
        if (errorEl) { errorEl.textContent = 'حدث خطأ في الاتصال بالخادم'; errorEl.style.display = 'block'; }
    });
}

function performLogout() {
    callAPI('logout', null, 'GET').then(function() {
        state.isLoggedIn = false;
        state.user = null;
        state.isAdmin = false;
        state.isVerified = false;
        state.balance = 0;

        try { localStorage.removeItem('Tokmart_user'); } catch (e) {}

        updateBalanceDisplay();
        updateAccountUI();
        closeLogoutSheet();
        showToast('✅ تم تسجيل الخروج');
        renderAll();
        clearAllIntervals();
        switchPage('page-home');
    });
}

function saveAccountSettings() {
    if (!state.isLoggedIn || !state.user) { showToast('يرجى تسجيل الدخول'); return; }
    const form = $('settingsForm');
    if (!form) return;

    const formData = new FormData(form);
    formData.set('id', state.user.id);
    formData.set('name', formData.get('username') || '');

    callAPI('updateUser', formData, 'POST').then(function(response) {
        if (response.success) {
            state.user = response.data;
            state.balance = state.user.balance || 0;
            updateBalanceDisplay();
            updateAccountUI();
            showToast('✅ تم حفظ التغييرات');
            closeSettingsPage('page-account-settings');
        } else {
            showToast(response.message || 'حدث خطأ');
        }
    });
}
