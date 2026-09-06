/* ============================================================
   Tokmart - Balance recharge: request form, history, detail view
   ============================================================ */

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
    const amount = state.rechargeAmount || 0;
    const fileInput = document.getElementById('rechargeReceipt');

    let hasError = false;

    if (amount < 1000) {
        showToast('⚠️ الحد الأدنى للشحن هو 1000 دينار عراقي');
        const amountInput = document.getElementById('rechargeAmount');
        if (amountInput) {
            amountInput.style.borderColor = 'var(--red)';
            setTimeout(function() { amountInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        hasError = true;
    }

    if (!fileInput) {
        showToast('⚠️ حقل رفع الصورة غير موجود');
        hasError = true;
    } else if (!fileInput.files || !fileInput.files[0]) {
        showToast('⚠️ الرجاء رفع صورة الإيصال');
        fileInput.style.borderColor = 'var(--red)';
        setTimeout(function() { fileInput.style.borderColor = 'var(--border)'; }, 3000);
        hasError = true;
    } else if (fileInput.files[0].size > 5 * 1024 * 1024) {
        showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
        fileInput.value = '';
        const preview = document.getElementById('receiptPreview');
        if (preview) {
            preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
            preview.style.borderColor = 'var(--border)';
        }
        hasError = true;
    }

    if (hasError) return;

    const formData = new FormData();
    formData.append('amount', amount);
    formData.append('paymentMethod', 'bank_transfer');
    formData.append('receipt', fileInput.files[0]);

    const submitBtn = document.getElementById('rechargeSubmitBtn') || document.querySelector('#rechargeForm .btn-submit:last-child');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        submitBtn.style.opacity = '0.6';
    }

    callAPI('requestRecharge', formData, 'POST')
        .then(function(response) {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن';
                submitBtn.style.opacity = '1';
            }

            if (response.success) {
                showToast('✅ تم تقديم طلب الشحن بنجاح، في انتظار المراجعة');

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

                loadRechargeHistory();
                loadAllData(true);
                updateBalanceDisplay();
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال طلب الشحن'));
            }
        })
        .catch(function(error) {
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
// RECHARGE DETAIL (customer view)
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
    if (recharge.receiptImage_url) {
        const imgUrl = recharge.receiptImage_url;
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

function closeRechargeDetail() {
    const overlay = document.querySelector('.recharge-detail-overlay');
    if (overlay) {
        overlay.remove();
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}
