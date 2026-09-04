/* ============================================================
   Tokmart - Google sign-in, OTP register, password reset, login/logout, account settings, notification list
   ============================================================ */

// ===== Account settings form: avatar preview + password visibility =====
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const preview = document.getElementById('settingsAvatarPreview');
        if (!preview) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function togglePassword(btn) {
    const wrapper = btn.closest('.password-field');
    const input = wrapper ? wrapper.querySelector('input') : null;
    const icon = btn.querySelector('i');
    if (!input || !icon) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

function initGoogleLogin() {
    const clientId = window.APP_CONFIG.googleClientId;
    const googleEnabled = window.APP_CONFIG.googleEnabled;
    
    if (!googleEnabled) {
        const loginContainer = document.getElementById('googleLoginContainer');
        const registerContainer = document.getElementById('googleRegisterContainer');
        if (loginContainer) {
            loginContainer.innerHTML = `
                <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                    <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                    ⚠️ تسجيل الدخول عبر Google معطل حالياً
                </div>
            `;
        }
        if (registerContainer) {
            registerContainer.innerHTML = `
                <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                    <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                    ⚠️ تسجيل الدخول عبر Google معطل حالياً
                </div>
            `;
        }
        return;
    }
    
    if (typeof window.google === 'undefined' || typeof window.google.accounts === 'undefined') {
        googleInitAttempts++;
        if (googleInitAttempts < MAX_GOOGLE_INIT_ATTEMPTS) {
            console.log('⏳ انتظار تحميل مكتبة Google... المحاولة ' + googleInitAttempts);
            setTimeout(initGoogleLogin, 800);
        } else {
            console.warn('⚠️ فشل تحميل مكتبة Google');
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
            console.log('✅ Google Identity Services initialized with client ID:', clientId);
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
            { 
                type: 'standard', 
                size: 'large', 
                theme: 'outline', 
                text: buttonText, 
                shape: 'rectangular', 
                logo_alignment: 'center',
                width: 320
            }
        );
        console.log('✅ Google button rendered:', containerId);
    } catch (e) {
        console.warn('⚠️ Error rendering Google button:', e);
        buttonDiv.innerHTML = `
            <button onclick="initGoogleLogin()" style="width:100%;padding:12px;border:2px solid #EA4335;border-radius:10px;background:#fff;color:#EA4335;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;">
                <i class="fa-brands fa-google" style="font-size:20px;"></i>
                ${buttonText === 'sign_in_with' ? 'تسجيل الدخول عبر Google' : 'إنشاء حساب عبر Google'}
            </button>
        `;
    }
}

function showFallbackGoogleButtons() {
    const containers = ['googleLoginContainer', 'googleRegisterContainer'];
    containers.forEach(function(id) {
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
    console.log('📱 Google login response received');
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
                        id: user.id,
                        name: user.name,
                        email: user.email,
                        isAdmin: user.isAdmin,
                        isVerified: user.isVerified,
                        balance: user.balance,
                        avatar_path: user.avatar_path
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
        .catch(function(error) {
            console.error('❌ Google login error:', error);
            googleBtns.forEach(function(btn) {
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });
            showToast('❌ حدث خطأ في الاتصال، يرجى المحاولة مرة أخرى');
        });
}

// ============================================================
// OTP VERIFICATION
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
        if (errorEl) {
            errorEl.textContent = t('required_field');
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (!/^07[0-9]{8,10}$/.test(phone)) {
        if (errorEl) {
            errorEl.textContent = t('invalid_phone');
            errorEl.style.display = 'block';
        }
        return;
    }
    
    callAPI('registerWithOTP', {
        name: name,
        email: email,
        phone: phone,
        password: password
    }, 'POST').then(function(response) {
        if (response.success) {
            const emailDisplay = $('otpEmailDisplay');
            if (emailDisplay) emailDisplay.textContent = email;
            closeRegisterPage();
            openOTPPage();
            showToast('✅ تم إرسال رمز التحقق');
            startResendTimer();
        } else {
            if (errorEl) {
                errorEl.textContent = response.message || t('error');
                errorEl.style.display = 'block';
            }
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
        if (next && next.classList.contains('otp-input')) {
            next.focus();
        }
    } else {
        input.classList.remove('filled');
        input.style.borderColor = 'var(--border)';
        input.style.background = 'var(--bg2)';
    }
    
    const inputs = document.querySelectorAll('.otp-input');
    let code = '';
    inputs.forEach(function(inp) {
        code += inp.value;
    });
    otpCode = code;
    
    if (code.length === 6) {
        verifyOTP();
    }
}

function verifyOTP() {
    const errorEl = $('otpError');
    const successEl = $('otpSuccess');
    if (errorEl) errorEl.style.display = 'none';
    if (successEl) successEl.style.display = 'none';
    
    if (otpCode.length !== 6) {
        if (errorEl) {
            errorEl.textContent = 'الرجاء إدخال رمز التحقق الكامل (6 أرقام)';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const verifyBtn = document.querySelector('#otpVerificationPage .btn-submit');
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('loading');
        verifyBtn.style.opacity = '0.6';
    }
    
    callAPI('verifyOTP', { otp: otpCode }, 'POST').then(function(response) {
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'تحقق';
            verifyBtn.style.opacity = '1';
        }
        
        if (response.success) {
            if (successEl) {
                successEl.textContent = '✅ تم التحقق بنجاح! جاري تسجيل الدخول...';
                successEl.style.display = 'block';
            }
            const user = response.data;
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = true;
            
            try {
                localStorage.setItem('Tokmart_user', JSON.stringify({
                    id: user.id,
                    name: user.name,
                    email: user.email,
                    isAdmin: user.isAdmin,
                    isVerified: user.isVerified,
                    balance: user.balance,
                    avatar_path: user.avatar_path
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
            if (errorEl) {
                errorEl.textContent = response.message || 'رمز التحقق غير صحيح';
                errorEl.style.display = 'block';
            }
            document.querySelectorAll('.otp-input').forEach(function(inp) {
                inp.value = '';
                inp.classList.remove('filled');
                inp.style.borderColor = 'var(--border)';
                inp.style.background = 'var(--bg2)';
            });
            otpCode = '';
            document.querySelector('.otp-input').focus();
        }
    }).catch(function() {
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'تحقق';
            verifyBtn.style.opacity = '1';
        }
        if (errorEl) {
            errorEl.textContent = 'حدث خطأ في الاتصال بالخادم';
            errorEl.style.display = 'block';
        }
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
    if (resendEl) {
        resendEl.style.pointerEvents = 'none';
        resendEl.style.opacity = '0.5';
    }
    
    clearInterval(otpResendTimer);
    otpResendTimer = setInterval(function() {
        otpResendSeconds--;
        if (timerEl) timerEl.textContent = '(' + otpResendSeconds + ' ثانية)';
        if (otpResendSeconds <= 0) {
            clearInterval(otpResendTimer);
            if (timerEl) timerEl.textContent = '';
            if (resendEl) {
                resendEl.style.pointerEvents = 'auto';
                resendEl.style.opacity = '1';
            }
        }
    }, 1000);
}

function openOTPPage() {
    const page = $('otpVerificationPage');
    if (page) {
        page.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
        document.querySelectorAll('.otp-input').forEach(function(inp) {
            inp.value = '';
            inp.classList.remove('filled');
            inp.style.borderColor = 'var(--border)';
            inp.style.background = 'var(--bg2)';
        });
        otpCode = '';
        setTimeout(function() {
            document.querySelector('.otp-input').focus();
        }, 300);
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
    const step1 = $('resetStep1');
    const step2 = $('resetStep2');
    const step3 = $('resetStep3');
    if (step1) step1.style.display = 'block';
    if (step2) step2.style.display = 'none';
    if (step3) step3.style.display = 'none';
    
    const errorEl = $('resetError');
    if (errorEl) errorEl.style.display = 'none';
}

function resetStep2() {
    const step1 = $('resetStep1');
    const step2 = $('resetStep2');
    const step3 = $('resetStep3');
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
    setTimeout(function() {
        document.querySelector('#resetOtpContainer .otp-input').focus();
    }, 300);
}

function resetStep3() {
    const step1 = $('resetStep1');
    const step2 = $('resetStep2');
    const step3 = $('resetStep3');
    if (step1) step1.style.display = 'none';
    if (step2) step2.style.display = 'none';
    if (step3) step3.style.display = 'block';
}

function requestPasswordReset() {
    const email = $('resetEmail') ? $('resetEmail').value.trim() : '';
    const errorEl = $('resetError');
    
    if (!email) {
        if (errorEl) {
            errorEl.textContent = 'الرجاء إدخال البريد الإلكتروني';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (errorEl) {
            errorEl.textContent = 'البريد الإلكتروني غير صحيح';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = document.querySelector('#resetStep1 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        btn.style.opacity = '0.6';
    }
    
    if (errorEl) errorEl.style.display = 'none';
    
    callAPI('requestPasswordReset', { email: email }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                resetEmail = email;
                const display = $('resetEmailDisplay');
                if (display) display.textContent = email;
                resetStep2();
                startResetResendTimer();
                showToast('✅ تم إرسال كود التحقق إلى بريدك');
            } else {
                if (errorEl) {
                    errorEl.textContent = response.message || 'فشل إرسال الكود';
                    errorEl.style.display = 'block';
                }
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق';
                btn.style.opacity = '1';
            }
            if (errorEl) {
                errorEl.textContent = 'حدث خطأ في الاتصال';
                errorEl.style.display = 'block';
            }
        });
}

function resetOtpInputHandler(input, index) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length === 1) {
        input.classList.add('filled');
        input.style.borderColor = 'var(--primary)';
        input.style.background = 'var(--surface)';
        const next = input.nextElementSibling;
        if (next && next.classList.contains('otp-input')) {
            next.focus();
        }
    } else {
        input.classList.remove('filled');
        input.style.borderColor = 'var(--border)';
        input.style.background = 'var(--bg2)';
    }
    
    const inputs = document.querySelectorAll('#resetOtpContainer .otp-input');
    let code = '';
    inputs.forEach(function(inp) {
        code += inp.value;
    });
    resetCode = code;
    
    if (code.length === 6) {
        verifyResetCode();
    }
}

function verifyResetCode() {
    const errorEl = $('resetCodeError');
    if (errorEl) errorEl.style.display = 'none';
    
    if (resetCode.length !== 6) {
        if (errorEl) {
            errorEl.textContent = 'الرجاء إدخال الرمز الكامل (6 أرقام)';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = document.querySelector('#resetStep2 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التحقق...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('verifyResetCode', { email: resetEmail, code: resetCode }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                resetStep3();
                showToast('✅ تم التحقق من الكود بنجاح');
            } else {
                if (errorEl) {
                    errorEl.textContent = response.message || 'الكود غير صحيح';
                    errorEl.style.display = 'block';
                }
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
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            if (errorEl) {
                errorEl.textContent = 'حدث خطأ في الاتصال';
                errorEl.style.display = 'block';
            }
        });
}

function resendResetCode() {
    if (resetResendSeconds > 0) return;
    
    const btn = document.querySelector('#resetStep2 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('requestPasswordReset', { email: resetEmail }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast('✅ تم إرسال كود جديد');
                startResetResendTimer();
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال الكود'));
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال');
        });
}

function startResetResendTimer() {
    resetResendSeconds = 60;
    const timerEl = $('resetResendTimer');
    const resendEl = document.querySelector('#resetStep2 a[onclick="resendResetCode()"]');
    if (timerEl) timerEl.textContent = '(60 ثانية)';
    if (resendEl) {
        resendEl.style.pointerEvents = 'none';
        resendEl.style.opacity = '0.5';
    }
    
    clearInterval(resetResendTimer);
    resetResendTimer = setInterval(function() {
        resetResendSeconds--;
        if (timerEl) timerEl.textContent = '(' + resetResendSeconds + ' ثانية)';
        if (resetResendSeconds <= 0) {
            clearInterval(resetResendTimer);
            if (timerEl) timerEl.textContent = '';
            if (resendEl) {
                resendEl.style.pointerEvents = 'auto';
                resendEl.style.opacity = '1';
            }
        }
    }, 1000);
}

function resetPassword() {
    const newPassword = $('resetNewPassword') ? $('resetNewPassword').value : '';
    const confirmPassword = $('resetConfirmPassword') ? $('resetConfirmPassword').value : '';
    const errorEl = $('resetPasswordError');
    
    if (errorEl) errorEl.style.display = 'none';
    
    if (!newPassword || newPassword.length < 6) {
        if (errorEl) {
            errorEl.textContent = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (newPassword !== confirmPassword) {
        if (errorEl) {
            errorEl.textContent = 'كلمات المرور غير متطابقة';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = document.querySelector('#resetStep3 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التغيير...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('resetPassword', { 
        email: resetEmail, 
        code: resetCode, 
        new_password: newPassword 
    }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تغيير كلمة المرور';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast('✅ تم تغيير كلمة المرور بنجاح');
                closeForgotPasswordPage();
                setTimeout(function() {
                    openLoginPage();
                    const identifier = $('loginIdentifier');
                    if (identifier) identifier.value = resetEmail;
                    showToast('🔑 يمكنك الآن تسجيل الدخول بكلمة المرور الجديدة');
                }, 500);
            } else {
                if (errorEl) {
                    errorEl.textContent = response.message || 'فشل تغيير كلمة المرور';
                    errorEl.style.display = 'block';
                }
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تغيير كلمة المرور';
                btn.style.opacity = '1';
            }
            if (errorEl) {
                errorEl.textContent = 'حدث خطأ في الاتصال';
                errorEl.style.display = 'block';
            }
        });
}

// ============================================================
// REQUIRE LOGIN
// ============================================================

function requireLogin(callback) {
    if (!state.isLoggedIn) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return false;
    }
    if (typeof callback === 'function') {
        callback();
    }
    return true;
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
// NOTIFICATION PERMISSION
// ============================================================

function requestNotificationPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
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
    const notification = state.notifications.find(function(n) { return n.id === id; });
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
    
    state.notifications.forEach(function(n) {
        n.isRead = 1;
    });
    updateNotifBadge();
    renderNotifications();
    
    callAPI('markAllNotificationsRead', {}, 'POST').then(function(response) {
        if (!response.success) {
            loadAllData(true);
        }
    });
}

function deleteAllNotifications() {
    if (!requireLogin()) return;
    
    state.notifications = [];
    updateNotifBadge();
    renderNotifications();
    
    callAPI('deleteAllNotifications', {}, 'POST').then(function(response) {
        if (!response.success) {
            loadAllData(true);
        }
    });
}

function deleteNotification(id) {
    if (!requireLogin()) return;
    
    const index = state.notifications.findIndex(function(n) { return n.id === id; });
    if (index !== -1) {
        state.notifications.splice(index, 1);
        updateNotifBadge();
        renderNotifications();
    }
    
    callAPI('deleteNotification', { id: id }, 'POST').then(function(response) {
        if (!response.success) {
            loadAllData(true);
        }
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
let swipeData = {
    startX: 0,
    currentX: 0,
    isSwiping: false,
    element: null
};

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
        setTimeout(function() {
            deleteNotification(id);
        }, 400);
    } else {
        element.style.setProperty('--swipe-offset', '0px');
        element.style.opacity = '1';
    }
}

// ===== دعم السحب بالفأرة =====
let mouseSwipeData = {
    startX: 0,
    isDragging: false,
    element: null
};

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
    if (Math.abs(diffX) > 10) {
        mouseSwipeData.isDragging = true;
    }
    
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
        setTimeout(function() {
            deleteNotification(id);
        }, 400);
    } else {
        mouseSwipeData.element.style.setProperty('--swipe-offset', '0px');
        mouseSwipeData.element.style.opacity = '1';
    }
    
    mouseSwipeData.isDragging = false;
    mouseSwipeData.element = null;
});

// ============================================================
// RECHARGE
// ============================================================

function openRechargePage() {
    if (!requireLogin()) return;
    openPage('rechargePage');
    renderRechargeForm();
    loadRechargeHistory();
}

function renderRechargeForm() {
    const container = $('rechargeForm');
    if (!container) return;
    
let html = `
    <div class="payment-card-container" style="background-image: url('https://i.ibb.co/FbQvCzn3/image.png'); background-size: cover; background-position: center; background-repeat: no-repeat; border-radius: 16px; overflow: hidden; position: relative; width: 100%; aspect-ratio: 16/9; margin-bottom: 16px; box-shadow: 0 8px 32px rgba(5,134,147,.2); border: 1px solid rgba(255,255,255,0.1);">
        <div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>
        <div style="position:relative;z-index:1;padding:16px 20px;display:flex;flex-direction:column;justify-content:space-between;height:100%;color:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:10px;opacity:0.8;letter-spacing:1px;font-weight:600;text-shadow:0 1px 4px rgba(0,0,0,0.3);">بطاقة الدفع سوبر كي</div>
                    <div style="font-size:18px;font-weight:800;margin-top:2px;text-shadow:0 1px 4px rgba(0,0,0,0.3);"> </div>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:10px;opacity:0.7;text-shadow:0 1px 4px rgba(0,0,0,0.3);">مدعوم من</span>
                    <i class="fa-brands fa-cc-visa" style="font-size:22px;opacity:0.9;text-shadow:0 1px 4px rgba(0,0,0,0.3);"></i>
                    <i class="fa-brands fa-cc-mastercard" style="font-size:22px;opacity:0.9;text-shadow:0 1px 4px rgba(0,0,0,0.3);"></i>
                </div>
            </div>
            <div style="text-align:center;padding:6px 0;">
                <div style="font-size:11px;opacity:0.8;font-weight:600;text-shadow:0 1px 4px rgba(0,0,0,0.3);">المبلغ المطلوب</div>
                <div style="font-size:32px;font-weight:900;letter-spacing:1px;text-shadow:0 2px 8px rgba(0,0,0,0.4);" id="cardAmountDisplay">0.00 د.ع</div>
                <div style="font-size:11px;opacity:0.6;margin-top:2px;text-shadow:0 1px 4px rgba(0,0,0,0.3);">أدخل المبلغ في الحقل أدناه</div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:flex-end;font-size:10px;opacity:0.7;border-top:1px solid rgba(255,255,255,0.15);padding-top:8px;text-shadow:0 1px 4px rgba(0,0,0,0.3);">
                <div style="display:flex;align-items:center;gap:6px;">
                    <i class="fa-regular fa-clock"></i>
                    <span>معالجة فورية</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <i class="fa-regular fa-shield"></i>
                    <span>مدفوعات آمنة</span>
                </div>
            </div>
        </div>
    </div>
    
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:var(--shadow);">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <i class="fa-solid fa-wallet" style="color:var(--primary);font-size:20px;"></i>
            <h3 style="font-size:16px;font-weight:800;color:var(--text2);margin:0;">طلب شحن الرصيد</h3>
        </div>
       
            
            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                    <i class="fa-solid fa-dollar-sign"></i> المبلغ <span style="color:var(--red);">*</span>
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number" id="rechargeAmount" step="100" min="1000" placeholder="1000" required 
                           style="flex:1;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:16px;font-weight:700;outline:none;transition:border-color 0.3s;">
                    <span style="font-size:16px;font-weight:800;color:var(--primary);min-width:40px;">د.ع</span>
                </div>
                <small style="color:var(--text3);font-size:11px;">💡 الحد الأدنى <strong>1000</strong> دينار عراقي</small>
            </div>
            
            <button class="btn-submit" onclick="showPaymentDetails()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-weight:700;font-size:16px;cursor:pointer;transition:all 0.3s var(--ease-spring);display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;">
                <i class="fa-solid fa-credit-card"></i> إدفع الآن
            </button>
        </div>
        
        <div id="paymentDetailsContainer" style="display:none;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:14px;box-shadow:var(--shadow);animation:slideUp 0.4s var(--ease-out);">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <i class="fa-solid fa-circle-info" style="color:var(--primary);font-size:18px;"></i>
                <h4 style="font-size:15px;font-weight:800;color:var(--text2);margin:0;">تفاصيل الدفع</h4>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:10px;border:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">رقم الحساب</div>
                        <div style="font-size:18px;font-weight:900;color:var(--text2);letter-spacing:1px;" id="accountNumberDisplay">7114152353</div>
                    </div>
                    <button onclick="copyAccountNumber()" style="padding:8px 14px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:all 0.3s;">
                        <i class="fa-regular fa-copy"></i> نسخ
                    </button>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="font-size:11px;color:var(--text3);font-weight:600;">ملاحضة</div>
                <div style="font-size:16px;font-weight:700;color:var(--text2);" id="beneficiaryDisplay">الحوالة تتم عبر سوبر كي حصرا بعد قم بتحويل وارفاق صوره للتحويل  وانتظر موافقة والاضافة خلال دقائق</div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">المبلغ المطلوب</div>
                        <div style="font-size:18px;font-weight:900;color:var(--primary);" id="paymentAmountDisplay">0 د.ع</div>
                    </div>
                    <i class="fa-solid fa-money-bill-wave" style="font-size:24px;color:var(--gold);opacity:0.5;"></i>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:6px;">
                    <i class="fa-regular fa-image"></i> صورة التحويل <span style="color:var(--red);">*</span>
                </label>
                <input type="file" id="rechargeReceipt" accept="image/*" required style="width:100%;padding:10px;border:2px solid var(--border);border-radius:8px;background:var(--surface);font-size:13px;cursor:pointer;">
                <div id="receiptPreview" style="width:100%;height:120px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:40px;color:var(--text3);border:2px dashed var(--border);transition:all 0.3s;">
                    <i class="fa-solid fa-image"></i>
                    <div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>
                </div>
                <small style="color:var(--text3);font-size:11px;">📷 الصيغ المدعومة: PNG, JPG, WEBP, GIF (الحد الأقصى 5MB)</small>
            </div>
            
      <button class="btn-submit" id="rechargeSubmitBtn" onclick="submitRechargeRequest()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--green);color:#fff;font-weight:700;font-size:16px;cursor:pointer;transition:all 0.3s var(--ease-spring);display:flex;align-items:center;justify-content:center;gap:10px;">
    <i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن
</button>
        </div>
        <div style="height: 120px;"></div>
        <div id="rechargeHistoryContainer" style="margin-top:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;border-bottom:1px solid var(--border);padding-bottom:8px;">
                <h3 style="font-size:16px;font-weight:800;color:var(--text2);display:flex;align-items:center;gap:8px;margin:0;">
                    <i class="fa-solid fa-clock-rotate-left"></i> سجل الشحن
                </h3>
                <span id="historyCount" style="font-size:12px;color:var(--text3);font-weight:600;">0 طلب</span>
            </div>
            <div id="rechargeHistory"></div>
        </div>
    `;
    
    container.innerHTML = html;
    
    const amountInput = $('rechargeAmount');
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            const val = parseFloat(this.value) || 0;
            const display = $('cardAmountDisplay');
            if (display) display.textContent = val > 0 ? val.toFixed(2) + ' د.ع' : '0.00 د.ع';
        });
    }
    
    const fileInput = $('rechargeReceipt');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const preview = $('receiptPreview');
            if (this.files && this.files[0] && preview) {
                if (this.files[0].size > 5 * 1024 * 1024) {
                    showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
                    this.value = '';
                    preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
                    preview.style.borderColor = 'var(--border)';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    loadRechargeHistory();
}

function showPaymentDetails() {
    const amountInput = $('rechargeAmount');
    const amount = parseFloat(amountInput ? amountInput.value : 0);
    
    if (isNaN(amount) || amount < 1000) {
        showToast('⚠️ الحد الأدنى للشحن هو 1000 دينار عراقي');
        if (amountInput) {
            amountInput.style.borderColor = 'var(--red)';
            setTimeout(function() { amountInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }
    
    const container = $('paymentDetailsContainer');
    if (container) {
        container.style.display = 'block';
        container.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    const display = $('paymentAmountDisplay');
    if (display) display.textContent = amount.toFixed(2) + ' د.ع';
    state.rechargeAmount = amount;
}

function copyAccountNumber() {
    const accountEl = $('accountNumberDisplay');
    if (!accountEl) return;
    const text = accountEl.textContent.trim();
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showToast('✅ تم نسخ رقم الحساب');
        }).catch(function() {
            copyTextFallback(text);
        });
    } else {
        copyTextFallback(text);
    }
}

function copyTextFallback(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showToast('✅ تم نسخ رقم الحساب');
    } catch (e) {
        showToast('❌ فشل النسخ، يرجى نسخ الرقم يدوياً');
    }
    document.body.removeChild(textarea);
}

function submitRechargeRequest() {
    console.log('🔄 بدء عملية تقديم طلب الشحن...');
    
    const amount = state.rechargeAmount || 0;
    const paymentMethod = 'bank_transfer';
    const fileInput = document.getElementById('rechargeReceipt');
    
    let hasError = false;
    
    // ===== التحقق من المبلغ =====
    if (amount < 1000) {
        showToast('⚠️ الحد الأدنى للشحن هو 1000 دينار عراقي');
        const amountInput = document.getElementById('rechargeAmount');
        if (amountInput) {
            amountInput.style.borderColor = 'var(--red)';
            setTimeout(function() { amountInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        hasError = true;
    }
    
    // ===== التحقق من الصورة =====
    if (!fileInput) {
        showToast('⚠️ حقل رفع الصورة غير موجود');
        hasError = true;
    } else if (!fileInput.files || !fileInput.files[0]) {
        showToast('⚠️ الرجاء رفع صورة الإيصال');
        fileInput.style.borderColor = 'var(--red)';
        setTimeout(function() { fileInput.style.borderColor = 'var(--border)'; }, 3000);
        hasError = true;
    } else {
        const fileSize = fileInput.files[0].size;
        if (fileSize > 5 * 1024 * 1024) {
            showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            fileInput.value = '';
            const preview = document.getElementById('receiptPreview');
            if (preview) {
                preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
                preview.style.borderColor = 'var(--border)';
            }
            hasError = true;
        } else {
            console.log('📷 حجم الصورة:', fileSize, 'bytes');
        }
    }
    
    if (hasError) {
        console.log('❌ يوجد أخطاء في المدخلات، تم إيقاف الإرسال');
        return;
    }
    
    // ===== إنشاء FormData =====
    const formData = new FormData();
    formData.append('amount', amount);
    formData.append('paymentMethod', 'bank_transfer');
    formData.append('receipt', fileInput.files[0]);
    
    console.log('📦 البيانات المرسلة:', {
        amount: amount,
        paymentMethod: 'bank_transfer',
        receiptFile: fileInput.files[0].name
    });
    
    // ===== تعطيل زر التقديم =====
    const submitBtn = document.getElementById('rechargeSubmitBtn') || document.querySelector('#rechargeForm .btn-submit:last-child');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        submitBtn.style.opacity = '0.6';
    }
    
    // ===== إرسال الطلب =====
    callAPI('requestRecharge', formData, 'POST')
        .then(function(response) {
            console.log('📨 استجابة الخادم:', response);
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن';
                submitBtn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast('✅ تم تقديم طلب الشحن بنجاح، في انتظار المراجعة');
                
                // ===== إعادة تعيين الحقول =====
                const amountInput = document.getElementById('rechargeAmount');
                if (amountInput) amountInput.value = '';
                
                const fileInput2 = document.getElementById('rechargeReceipt');
                if (fileInput2) fileInput2.value = '';
                
                const preview = document.getElementById('receiptPreview');
                if (preview) {
                    preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
                    preview.style.borderColor = 'var(--border)';
                }
                
                const details = document.getElementById('paymentDetailsContainer');
                if (details) details.style.display = 'none';
                
                const cardDisplay = document.getElementById('cardAmountDisplay');
                if (cardDisplay) cardDisplay.textContent = '0.00 د.ع';
                
                state.rechargeAmount = 0;
                
                // ===== تحديث البيانات =====
                loadRechargeHistory();
                loadAllData(true);
                updateBalanceDisplay();
                
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال طلب الشحن'));
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في الاتصال:', error);
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن';
                submitBtn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال بالخادم: ' + (error.message || ''));
        });
}

function loadRechargeHistory() {
    if (!state.isLoggedIn) return;
    callAPI('getRecharges', {}, 'GET').then(function(response) {
        if (response.success) {
            state.recharges = response.data || [];
            renderRechargeHistory();
        }
    });
}

function renderRechargeHistory() {
    const container = $('rechargeHistory');
    if (!container) return;
    const countEl = $('historyCount');
    
    if (state.recharges.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px 20px;color:var(--text3);">
                <i class="fa-regular fa-clock" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                <div style="font-size:14px;font-weight:600;">${t('no_recharges')}</div>
                <div style="font-size:12px;margin-top:4px;">${t('recharge_now')}</div>
            </div>
        `;
        if (countEl) countEl.textContent = '0 طلب';
        return;
    }
    
    if (countEl) countEl.textContent = state.recharges.length + ' طلب';
    
    container.innerHTML = state.recharges.map(function(r) {
        const statusMap = {
            pending: { text: '⏳ ' + t('pending_review'), color: 'var(--secondary)', bg: 'rgba(216,176,122,.15)', icon: 'fa-spinner fa-spin' },
            approved: { text: '✅ ' + t('approved'), color: 'var(--green)', bg: 'rgba(46,204,113,.15)', icon: 'fa-circle-check' },
            rejected: { text: '❌ ' + t('rejected'), color: 'var(--red)', bg: 'rgba(231,76,60,.15)', icon: 'fa-circle-xmark' }
        };
        const status = statusMap[r.status] || statusMap.pending;
        const date = new Date(r.createdAt);
        const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
        
        return `
            <div class="recharge-item" onclick="openRechargeDetail(${r.id})" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px;cursor:pointer;transition:all 0.3s;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div style="flex:1;min-width:140px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:18px;font-weight:900;color:var(--primary);">${parseFloat(r.amount).toFixed(2)} د.ع</span>
                            <span style="font-size:11px;color:var(--text3);">${r.paymentMethod || 'تحويل بنكي'}</span>
                        </div>
                        <div style="font-size:12px;color:var(--text3);margin-top:2px;">
                            <i class="fa-regular fa-calendar"></i> ${dateStr}
                        </div>
                        ${r.receiptImage ? `
                            <div style="font-size:11px;color:var(--primary);margin-top:4px;">
                                <i class="fa-regular fa-image"></i> ${t('receipt')}
                            </div>
                        ` : ''}
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;background:${status.bg};color:${status.color};">
                            ${status.text}
                        </span>
                        <i class="fa-regular ${status.icon}" style="color:${status.color};font-size:16px;"></i>
                        <i class="fa-solid fa-chevron-left" style="color:var(--text3);font-size:14px;"></i>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// ============================================================
// RECHARGE DETAIL
// ============================================================
// ============================================================
// ✅ دالة عرض تفاصيل طلب الشحن (للمستخدم)
// ============================================================
function openRechargeDetail(rechargeId) {
    const recharge = state.recharges.find(function(r) { return r.id === rechargeId; });
    if (!recharge) {
        showToast('⚠️ طلب الشحن غير موجود');
        return;
    }
    
    const existingOverlay = document.querySelector('.recharge-detail-overlay');
    if (existingOverlay) {
        existingOverlay.remove();
    }
    
    const overlay = document.createElement('div');
    overlay.className = 'recharge-detail-overlay';
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        z-index: 400;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(8px);
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeInOverlay 0.3s var(--ease-out);
        padding: 20px;
        padding-top: var(--safe-area-top);
        padding-bottom: var(--safe-area-bottom);
    `;
    
    const statusMap = {
        pending: { text: '⏳ بانتظار المراجعة', color: 'var(--secondary)', bg: 'rgba(216,176,122,.15)' },
        approved: { text: '✅ تمت الموافقة', color: 'var(--green)', bg: 'rgba(46,204,113,.15)' },
        rejected: { text: '❌ مرفوض', color: 'var(--red)', bg: 'rgba(231,76,60,.15)' }
    };
    const status = statusMap[recharge.status] || statusMap.pending;
    
    const date = new Date(recharge.createdAt);
    const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    
    let receiptHtml = '';
    if (recharge.receiptImage) {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        const imgUrl = baseUrl + '/data/uploads/receipts/' + recharge.receiptImage;
        receiptHtml = `
            <div style="margin-top:12px;padding:12px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <i class="fa-solid fa-image" style="color:var(--primary);"></i>
                    <span style="font-weight:700;font-size:13px;">🖼️ صورة الإيصال</span>
                </div>
                <img src="${imgUrl}" onclick="event.stopPropagation();openLightbox('${imgUrl}')" 
                     style="width:100%;max-height:250px;border-radius:8px;cursor:pointer;object-fit:contain;border:1px solid var(--border);"
                     onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML += '<div style=\\'color:var(--red);padding:20px;text-align:center;background:var(--bg2);border-radius:8px;\\'><i class=\\'fa-solid fa-image\\' style=\\'font-size:48px;display:block;margin-bottom:8px;opacity:0.3;\\'></i>⚠️ تعذر تحميل الصورة<br><small style=\\'font-size:11px;color:var(--text3);\\'>يرجى المحاولة مرة أخرى</small></div>';">
            </div>
        `;
    }
    
    overlay.innerHTML = `
        <div class="recharge-detail-card" style="background:var(--surface);border-radius:16px;padding:24px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:slideUp 0.4s var(--ease-spring);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="font-size:18px;font-weight:800;color:var(--text2);display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-wallet" style="color:var(--primary);"></i>
                    تفاصيل طلب الشحن
                    <span style="font-size:12px;font-weight:400;color:var(--text3);">#${recharge.id}</span>
                </h3>
                <button onclick="closeRechargeDetail()" style="width:36px;height:36px;border:none;border-radius:50%;background:var(--bg2);color:var(--text3);font-size:18px;cursor:pointer;display:grid;place-items:center;transition:all 0.3s;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div style="background:var(--bg2);border-radius:10px;padding:12px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">💰 المبلغ</div>
                    <div style="font-size:20px;font-weight:900;color:var(--primary);">${parseFloat(recharge.amount).toFixed(2)} د.ع</div>
                </div>
                <div style="background:var(--bg2);border-radius:10px;padding:12px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">📌 الحالة</div>
                    <span style="padding:4px 12px;border-radius:12px;font-size:13px;font-weight:700;background:${status.bg};color:${status.color};display:inline-block;margin-top:2px;">
                        ${status.text}
                    </span>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">📅 تاريخ الطلب</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${dateStr}</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">💳 طريقة الدفع</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${recharge.paymentMethod || 'تحويل بنكي'}</div>
                    </div>
                </div>
            </div>
            
            ${receiptHtml}
            
            ${recharge.notes ? `
                <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-top:12px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">📝 ملاحظات</div>
                    <div style="font-size:14px;color:var(--text2);margin-top:4px;">${recharge.notes}</div>
                </div>
            ` : ''}
            
            <button onclick="closeRechargeDetail()" style="width:100%;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-weight:700;font-size:14px;cursor:pointer;margin-top:16px;transition:all 0.3s;">
                <i class="fa-solid fa-arrow-right"></i> إغلاق
            </button>
        </div>
    `;
    
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
}

// ============================================================
// ✅ دالة إغلاق تفاصيل طلب الشحن
// ============================================================
function closeRechargeDetail() {
    const overlay = document.querySelector('.recharge-detail-overlay');
    if (overlay) {
        overlay.remove();
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

// ============================================================
// ✅ دالة عرض تفاصيل الطلب (للمستخدم والأدمن)
// ============================================================
function openOrderDetail(orderId) {
    if (!state.isLoggedIn) {
        showToast('يرجى تسجيل الدخول');
        return;
    }

    const getEl = (id) => document.getElementById(id);

    const order = state.orders.find(function(o) {
        return o.id == orderId || o.orderId == orderId;
    });

    if (!order) {
        showToast('⚠️ الطلب غير موجود');
        return;
    }

    const isAdmin = state.isAdmin;
    const isOwner = state.user && order.userId == state.user.id;

    if (!isAdmin && !isOwner) {
        showToast('⚠️ غير مصرح لك بعرض هذا الطلب');
        return;
    }

    document.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .chat-app-user.open, .favorites-page.open, .orders-page.open, .recharge-page.open, .admin-page.open')
        .forEach(function(page) {
            if (page.id !== 'orderDetailPage') {
                page.classList.remove('open');
            }
        });

    const detailPage = getEl('orderDetailPage');
    if (detailPage) {
        detailPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
    }
    
    const titleEl = getEl('orderDetailTitle');
    if (titleEl) {
        titleEl.textContent = '📋 تفاصيل الطلب - ' + (order.orderId || '#' + order.id);
    }

    const statusLabels = {
        pending: '⏳ قيد المعالجة',
        shipped: '🚚 قيد التوصيل',
        completed: '✅ مكتمل',
        cancelled: '❌ ملغي',
        approved: '✅ تم الموافقة',
        rejected: '❌ مرفوض'
    };
    
    const statusClass = ['pending', 'shipped', 'completed', 'cancelled', 'approved', 'rejected'].includes(order.status) ? order.status : 'pending';
    
    const statusColors = {
        pending: { bg: '#fff3cd', text: '#856404' },
        shipped: { bg: '#cce5ff', text: '#004085' },
        completed: { bg: '#d4edda', text: '#155724' },
        cancelled: { bg: '#f8d7da', text: '#721c24' },
        approved: { bg: '#d4edda', text: '#155724' },
        rejected: { bg: '#f8d7da', text: '#721c24' }
    };
    const currentThemeColor = statusColors[statusClass] || { bg: '#e2e3e5', text: '#383d41' };
    const bgColor = currentThemeColor.bg;
    const textColor = currentThemeColor.text;

    let items = [];
    try {
        let rawItems = order.items || order.cart || order.products;
        if (rawItems) {
            if (typeof rawItems === 'string') {
                try {
                    items = JSON.parse(rawItems);
                } catch (parseErr) {
                    items = [];
                }
            } else if (Array.isArray(rawItems)) {
                items = rawItems;
            } else if (typeof rawItems === 'object' && rawItems !== null) {
                items = Object.values(rawItems);
            }
        }
    } catch (e) {
        items = [];
    }
    
    if (!Array.isArray(items)) {
        items = [];
    }

    const transferContainer = getEl('orderDetailTransferContainer');
    let transferHtml = '';
    if (order.transferImage) {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        const imgUrl = baseUrl + '/data/uploads/transfers/' + order.transferImage;
        
        transferHtml = `
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <i class="fa-solid fa-image" style="color:#0f3d1c;"></i>
                <span style="font-weight:700;font-size:14px;">🖼️ صورة التحويل</span>
            </div>
            <img src="${imgUrl}" onclick="openLightbox('${imgUrl}')"
                 style="max-width:100%;border-radius:8px;cursor:pointer;max-height:300px;object-fit:contain;border:1px solid var(--border);"
                 onerror="this.style.display='none'; this.parentElement.innerHTML += '<div style=\\'color:var(--red);text-align:center;padding:10px;\\'>⚠️ تعذر تحميل الصورة</div>';">
            
            ${order.transferAmount ? `<div style="margin-top:8px;font-size:13px;color:var(--text3);">💰 المبلغ المحول: <strong>${parseFloat(order.transferAmount || 0).toFixed(2)} د.ع</strong></div>` : ''}
            ${order.accountNumber ? `<div style="font-size:13px;color:var(--text3);">🏦 رقم الحساب: <strong>${order.accountNumber}</strong></div>` : ''}
            ${order.beneficiary ? `<div style="font-size:13px;color:var(--text3);">👤 المستفيد: <strong>${order.beneficiary}</strong></div>` : ''}
        </div>
        `;
    }
    if (transferContainer) {
        transferContainer.innerHTML = transferHtml;
    }

    let userInfoHtml = '';
    if (isAdmin && order.userId && state.users) {
        const targetUser = state.users.find(function(u) { return u.id == order.userId; });
        if (targetUser) {
            userInfoHtml = `
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <i class="fa-solid fa-user" style="color:var(--primary);"></i>
                    <span style="font-weight:700;font-size:14px;">👤 معلومات العميل</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
                    <div><span style="color:var(--text3);">الاسم:</span> <strong>${targetUser.name || '-'}</strong></div>
                    <div><span style="color:var(--text3);">الهاتف:</span> <strong>${targetUser.phone || '-'}</strong></div>
                    <div style="grid-column:1/-1;"><span style="color:var(--text3);">البريد:</span> <strong>${targetUser.email || '-'}</strong></div>
                </div>
            </div>
            `;
        }
    }

    const body = getEl('orderDetailBody');
    if (!body) return;

    body.innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow);">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));gap:12px;">
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">📌 الحالة</div>
                <span class="order-status-badge ${statusClass}" style="display:inline-block;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;background:${bgColor};color:${textColor};margin-top:4px;">
                    ${statusLabels[order.status] || order.status}
                </span>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">📅 التاريخ</div>
                <div style="font-weight:700;font-size:14px;margin-top:4px;">${order.date || (order.createdAt ? new Date(order.createdAt).toLocaleDateString('ar-EG') : '')}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">💰 المجموع</div>
                <div style="font-size:20px;font-weight:900;color:var(--primary);margin-top:2px;">${parseFloat(order.total || 0).toFixed(2)} د.ع</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">🆔 رقم الطلب</div>
                <div style="font-weight:700;font-size:13px;margin-top:4px;direction:ltr;">${order.orderId || order.id}</div>
            </div>
        </div>
    </div>

    ${userInfoHtml}

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <i class="fa-solid fa-location-dot" style="color:var(--primary);font-size:16px;"></i>
            <span style="font-weight:700;font-size:14px;">📍 معلومات التوصيل</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
            <div style="grid-column:1/-1;">
                <span style="color:var(--text3);">العنوان:</span>
                <div style="font-weight:600;margin-top:3px;padding:8px 12px;background:var(--bg2);border-radius:8px;word-break:break-word;">${order.address || 'غير محدد'}</div>
            </div>
            <div>
                <span style="color:var(--text3);">📞 الهاتف:</span>
                <div style="font-weight:600;margin-top:3px;">${order.phone || 'غير محدد'}</div>
            </div>
            <div>
                <span style="color:var(--text3);">💳 طريقة الدفع:</span>
                <div style="font-weight:600;margin-top:3px;">
                    ${order.payment === 'cash' ? '💰 الدفع عند الاستلام' : 
                      order.payment === 'electronic' ? '💳 الدفع الإلكتروني' : 
                      (order.payment === 'transfer' || order.payment === 'bank_transfer') ? '🏦 تحويل بنكي' : 
                      order.payment || 'غير محدد'}
                </div>
            </div>
        </div>
    </div>

    ${transferHtml}

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <i class="fa-solid fa-box" style="color:var(--primary);font-size:16px;"></i>
            <span style="font-weight:700;font-size:14px;">🛒 المنتجات (${items.length})</span>
        </div>
        ${items.length > 0 ? items.map(function(item, index) {
            if (!item) return '';
            let imgSrc = item.image_url || item.image || '';
            if (!imgSrc && state.products) {
                const productInState = state.products.find(p => p.id == item.id);
                if (productInState) imgSrc = productInState.image_url || productInState.image || '';
            }
            
            const imgHtml = imgSrc ? 
                `<img src="${imgSrc}" style="width:100%;height:100%;object-fit:cover;">` : 
                `<i class="fa-solid fa-box" style="color:var(--primary);"></i>`;

            return `
            <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:${index < items.length - 1 ? '1px solid var(--border)' : 'none'};">
                <div style="width:48px;height:48px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;font-size:20px;flex-shrink:0;border:1px solid var(--border);">
                    ${imgHtml}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:13px;word-break:break-word;">${item.name || item.title || 'منتج'}</div>
                    <div style="font-size:11px;color:var(--text3);">
                        الكمية: ${item.qty || item.quantity || 1} × ${parseFloat(item.price || 0).toFixed(2)} د.ع
                    </div>
                </div>
                <div style="font-weight:800;font-size:14px;color:#0f3d1c;white-space:nowrap;">
                    ${(parseFloat(item.price || 0) * (item.qty || item.quantity || 1)).toFixed(2)} د.ع
                </div>
            </div>
            `;
        }).join('') : `
        <div style="text-align:center;padding:20px;color:var(--text3);">
            <i class="fa-regular fa-box-open" style="font-size:24px;display:block;margin-bottom:8px;"></i>
            لا توجد منتجات مسجلة في هذا الطلب
        </div>
        `}
        
        <div style="display:flex;justify-content:space-between;padding:12px 0 4px;border-top:2px solid #0f3d1c;margin-top:8px;">
            <span style="font-weight:700;font-size:15px;color:var(--text2);">المجموع الكلي</span>
            <span style="font-size:18px;font-weight:900;color:#0f3d1c;">${parseFloat(order.total || 0).toFixed(2)} د.ع</span>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px;padding-bottom:20px;">
     
        
        
        <button onclick="openOrderSupportChat('${order.orderId || order.id}')"
            style="width:100%;padding:14px;border:none;border-radius:12px;background:#0f3d1c;color:#fff;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
            <i class="fa-solid fa-headset"></i> التواصل مع الدعم
        </button>
        
        <button onclick="closeOrderDetail()"
            style="width:100%;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-weight:700;font-size:14px;cursor:pointer;">
            <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
        </button>
    </div>
    `;
}

// ============================================================
// ✅ دالة إغلاق تفاصيل الطلب
// ============================================================
function closeOrderDetail() {
    const detailPage = document.getElementById('orderDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

// ============================================================
// ✅ دالة تحديث حالة الطلب من صفحة التفاصيل
// ============================================================
function updateOrderStatusFromDetail(orderId, status) {
    if (!confirm('هل أنت متأكد من تغيير حالة الطلب؟')) return;
    
    callAPI('updateOrderStatus', { id: orderId, status: status }, 'POST')
        .then(function(response) {
            if (response.success) {
                showToast('✅ تم تحديث حالة الطلب');
                loadAllData(true);
                setTimeout(function() {
                    openOrderDetail(orderId);
                }, 300);
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'));
            }
        });
}

// ============================================================
// ✅ دالة عرض تفاصيل طلب الشحن للأدمن
// ============================================================
function openAdminRechargeDetail(rechargeId) {
    console.log('🔄 فتح تفاصيل طلب الشحن للأدمن:', rechargeId);
    
    const recharge = state.recharges.find(function(r) { return r.id === rechargeId; });
    if (!recharge) {
        showToast('⚠️ طلب الشحن غير موجود');
        return;
    }
    
    const user = state.users.find(function(u) { return u.id === recharge.userId; });
    
    const overlay = document.createElement('div');
    overlay.className = 'recharge-detail-overlay';
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        z-index: 400;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(8px);
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeInOverlay 0.3s var(--ease-out);
        padding: 20px;
        padding-top: var(--safe-area-top);
        padding-bottom: var(--safe-area-bottom);
    `;
    
    const statusMap = {
        pending: { text: '⏳ بانتظار المراجعة', color: 'var(--secondary)', bg: 'rgba(216,176,122,.15)' },
        approved: { text: '✅ تمت الموافقة', color: 'var(--green)', bg: 'rgba(46,204,113,.15)' },
        rejected: { text: '❌ مرفوض', color: 'var(--red)', bg: 'rgba(231,76,60,.15)' }
    };
    const status = statusMap[recharge.status] || statusMap.pending;
    
    const date = new Date(recharge.createdAt);
    const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    
    let receiptHtml = '';
    if (recharge.receiptImage) {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        const imgUrl = baseUrl + '/data/uploads/receipts/' + recharge.receiptImage;
        receiptHtml = `
            <div style="margin-top:12px;padding:12px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <i class="fa-solid fa-image" style="color:var(--primary);"></i>
                    <span style="font-weight:700;font-size:13px;">🖼️ صورة الإيصال</span>
                </div>
                <img src="${imgUrl}" onclick="event.stopPropagation();openLightbox('${imgUrl}')" 
                     style="width:100%;max-height:250px;border-radius:8px;cursor:pointer;object-fit:contain;border:1px solid var(--border);"
                     onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML += '<div style=\\'color:var(--red);padding:20px;text-align:center;background:var(--bg2);border-radius:8px;\\'><i class=\\'fa-solid fa-image\\' style=\\'font-size:48px;display:block;margin-bottom:8px;opacity:0.3;\\'></i>⚠️ تعذر تحميل الصورة<br><small style=\\'font-size:11px;color:var(--text3);\\'>يرجى المحاولة مرة أخرى</small></div>';">
            </div>
        `;
    } else {
        receiptHtml = `
            <div style="margin-top:12px;padding:12px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);text-align:center;color:var(--text3);">
                <i class="fa-regular fa-image" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                لا توجد صورة إيصال مرفقة
            </div>
        `;
    }
    
    overlay.innerHTML = `
        <div class="recharge-detail-card" style="background:var(--surface);border-radius:16px;padding:24px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:slideUp 0.4s var(--ease-spring);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="font-size:18px;font-weight:800;color:var(--text2);display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-wallet" style="color:var(--primary);"></i>
                    تفاصيل طلب الشحن
                    <span style="font-size:12px;font-weight:400;color:var(--text3);">#${recharge.id}</span>
                </h3>
                <button onclick="closeRechargeDetail()" style="width:36px;height:36px;border:none;border-radius:50%;background:var(--bg2);color:var(--text3);font-size:18px;cursor:pointer;display:grid;place-items:center;transition:all 0.3s;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="font-size:11px;color:var(--text3);font-weight:600;margin-bottom:6px;">👤 معلومات المستخدم</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div><span style="font-size:12px;color:var(--text3);">الاسم:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? user.name : 'غير معروف'}</span></div>
                    <div><span style="font-size:12px;color:var(--text3);">البريد:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? user.email : 'غير معروف'}</span></div>
                    <div><span style="font-size:12px;color:var(--text3);">الهاتف:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? user.phone : 'غير معروف'}</span></div>
                    <div><span style="font-size:12px;color:var(--text3);">الرصيد:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? (user.balance || 0).toFixed(2) : 'غير معروف'} د.ع</span></div>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div style="background:var(--bg2);border-radius:10px;padding:14px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">💰 المبلغ</div>
                    <div style="font-size:22px;font-weight:900;color:var(--primary);">${parseFloat(recharge.amount).toFixed(2)} د.ع</div>
                </div>
                <div style="background:var(--bg2);border-radius:10px;padding:14px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">📌 الحالة</div>
                    <span style="padding:4px 12px;border-radius:12px;font-size:13px;font-weight:700;background:${status.bg};color:${status.color};display:inline-block;margin-top:2px;">
                        ${status.text}
                    </span>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">📅 تاريخ الطلب</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${dateStr}</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">💳 طريقة الدفع</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${recharge.paymentMethod || 'تحويل بنكي'}</div>
                    </div>
                </div>
            </div>
            
            ${receiptHtml}
            
            ${recharge.status === 'pending' ? `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;">
                    <button onclick="event.stopPropagation();handleRechargeAction(${recharge.id}, 'approved')" 
                            style="padding:14px;border:none;border-radius:12px;background:var(--green);color:#fff;font-weight:700;font-size:14px;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fa-solid fa-check"></i> موافقة
                    </button>
                    <button onclick="event.stopPropagation();handleRechargeAction(${recharge.id}, 'rejected')" 
                            style="padding:14px;border:none;border-radius:12px;background:var(--red);color:#fff;font-weight:700;font-size:14px;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fa-solid fa-xmark"></i> رفض
                    </button>
                </div>
            ` : `
                <div style="margin-top:16px;padding:12px;background:var(--bg2);border-radius:10px;text-align:center;border:1px solid var(--border);">
                    <span style="font-size:13px;color:var(--text3);">
                        ${recharge.status === 'approved' ? '✅ تمت الموافقة على هذا الطلب' : '❌ تم رفض هذا الطلب'}
                    </span>
                </div>
            `}
            
            <button onclick="closeRechargeDetail()" style="width:100%;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-weight:700;font-size:14px;cursor:pointer;margin-top:12px;transition:all 0.3s;">
                <i class="fa-solid fa-arrow-right"></i> إغلاق
            </button>
        </div>
    `;
    
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
}

// ============================================================
// ✅ دالة معالجة طلب الشحن (موافقة/رفض)
// ============================================================
function handleRechargeAction(id, action) {
    const actionText = action === 'approved' ? 'موافقة' : 'رفض';
    if (!confirm(`هل أنت متأكد من ${actionText} هذا الطلب؟`)) return;
    
    callAPI('updateRechargeStatus', { id: id, status: action }, 'POST')
        .then(function(response) {
            if (response.success) {
                showToast(`✅ تم ${actionText} طلب الشحن بنجاح`);
                closeRechargeDetail();
                loadAllData(true);
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'));
            }
        });
}
// ============================================================
// POLICY FUNCTIONS
// ============================================================

function togglePolicy(type) {
    const content = $(type === 'privacy' ? 'privacyPolicyContent' : 'termsPolicyContent');
    if (content) {
        content.classList.toggle('open');
        if (content.classList.contains('open')) {
            content.style.maxHeight = content.scrollHeight + 'px';
        } else {
            content.style.maxHeight = '0';
        }
    }
}

function savePolicy(type) {
    const input = $(type === 'privacy' ? 'privacyPolicyInput' : 'termsInput');
    if (!input) return;
    const value = input.value.trim();
    callAPI('savePolicy', { type: type, content: value }, 'POST').then(function(response) {
        if (response.success) {
            showToast('✅ تم حفظ ' + (type === 'privacy' ? 'سياسة الخصوصية' : 'الشروط والأحكام'));
        } else {
            showToast('❌ ' + (response.message || 'فشل الحفظ'));
        }
    });
}

// ============================================================
// ACCOUNT FUNCTIONS
// ============================================================

function updateBalanceDisplay() {
    const el = $('userBalance');
    if (el) el.textContent = state.balance.toFixed(2) + ' د.ع';
}

function updateAccountUI() {
    const loginBtn = $('loginBtn');
    const registerBtn = $('registerBtn');
    const logoutWrapper = $('logoutWrapper');
    const adminBtn = $('adminBtn');
    const adminPanelMenuItem = $('adminPanelMenuItem');
    
    if (state.isLoggedIn && state.user) {
        if (loginBtn) loginBtn.textContent = '👤 ' + state.user.name;
        if (registerBtn) registerBtn.textContent = '🚪 تسجيل الخروج';
        if (loginBtn) loginBtn.classList.add('active');
        if (logoutWrapper) logoutWrapper.style.display = 'block';
        if (adminBtn) adminBtn.style.display = state.isAdmin ? 'flex' : 'none';
        if (adminPanelMenuItem) adminPanelMenuItem.style.display = state.isAdmin ? 'flex' : 'none';
    } else {
        if (loginBtn) loginBtn.textContent = '🔑 تسجيل الدخول';
        if (registerBtn) registerBtn.textContent = '📝 إنشاء حساب';
        if (loginBtn) loginBtn.classList.add('active');
        if (logoutWrapper) logoutWrapper.style.display = 'none';
        if (adminBtn) adminBtn.style.display = 'none';
        if (adminPanelMenuItem) adminPanelMenuItem.style.display = 'none';
    }
    
    updateLanguageDisplay();
}

function updateLanguageDisplay() {
    const display = $('currentLangDisplay');
    if (display) {
        display.textContent = currentLang === 'ar' ? 'العربية' : 'English';
    }
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
        if (errorEl) {
            errorEl.textContent = t('required_field');
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const loginBtn = document.querySelector('#loginForm .btn-submit');
    if (loginBtn) {
        loginBtn.disabled = true;
        loginBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('loading');
        loginBtn.style.opacity = '0.6';
    }
    
    callAPI('login', { identifier: identifier, password: password }, 'POST').then(function(response) {
        if (loginBtn) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = t('login');
            loginBtn.style.opacity = '1';
        }
        
        if (response.success) {
            const user = response.data;
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;
            
            try {
                localStorage.setItem('Tokmart_user', JSON.stringify({
                    id: user.id,
                    name: user.name,
                    email: user.email,
                    isAdmin: user.isAdmin,
                    isVerified: user.isVerified,
                    balance: user.balance,
                    avatar_path: user.avatar_path
                }));
            } catch (e) {}
            
            updateBalanceDisplay();
            updateAccountUI();
            closeLoginPage();
            renderAll();
            showToast('👋 مرحباً ' + user.name);
            if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
            requestNotificationPermission();
        } else {
            if (errorEl) {
                errorEl.textContent = response.message || 'بيانات الدخول غير صحيحة';
                errorEl.style.display = 'block';
            }
        }
    }).catch(function() {
        if (loginBtn) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = t('login');
            loginBtn.style.opacity = '1';
        }
        if (errorEl) {
            errorEl.textContent = 'حدث خطأ في الاتصال بالخادم';
            errorEl.style.display = 'block';
        }
    });
}

function performLogout() {
    callAPI('logout', null, 'GET').then(function() {
        state.isLoggedIn = false;
        state.user = null;
        state.isAdmin = false;
        state.isVerified = false;
        state.balance = 0;
        
        try {
            localStorage.removeItem('Tokmart_user');
        } catch (e) {}
        
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
    const formData = new FormData();
    formData.append('id', state.user.id);
    const nameEl = $('settingsUsername');
    const phoneEl = $('settingsPhone');
    const emailEl = $('settingsEmail');
    const currentPassEl = $('settingsCurrentPassword');
    const newPassEl = $('settingsNewPassword');
    const avatarEl = $('settingsAvatar');
    
    if (nameEl) formData.append('name', nameEl.value.trim());
    if (phoneEl) formData.append('phone', phoneEl.value.trim());
    if (emailEl) formData.append('email', emailEl.value.trim());
    if (currentPassEl) formData.append('currentPassword', currentPassEl.value);
    if (newPassEl) formData.append('newPassword', newPassEl.value);
    if (avatarEl && avatarEl.files[0]) formData.append('avatar', avatarEl.files[0]);
    
    callAPI('updateUser', formData, 'POST').then(function(response) {
        if (response.success) {
            state.user = response.data;
            state.balance = state.user.balance || 0;
            updateBalanceDisplay();
            updateAccountUI();
            showToast('✅ تم حفظ التغييرات');
            closePage('page-account-settings');
        } else {
            showToast(response.message || 'حدث خطأ');
        }
    });
}

// ============================================================
// CART AND PRODUCTS
// ============================================================

