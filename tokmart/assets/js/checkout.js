/* ============================================================
   Tokmart - Checkout: payment selection, order placement, geolocation
   ============================================================ */

function renderCheckout() {
    const section = $('checkoutSection');
    if (!section) return;
    if (state.cart.length === 0 || !state.isLoggedIn) {
        section.style.display = 'none';
        return;
    }
    section.style.display = 'block';
    const total = getCartTotal();
    // Fixed by design, not admin-configurable: checkout always offers exactly
    // these two, regardless of what's in the payments table (bank transfer
    // stays admin-configurable, but only as a balance recharge method).
    const paymentOptions = [
        { id: 'cash', name: 'الدفع عند الاستلام', icon: 'fa-solid fa-hand-holding-dollar' },
        { id: 'electronic', name: 'الدفع الإلكتروني', icon: 'fa-solid fa-credit-card' }
    ];

    let transferHtml = '';
    if (state.selectedPayment === 'transfer' || state.selectedPayment === 'bank_transfer') {
        const transferPayments = state.payments.filter(function(p) {
            return p.enabled && (p.id === 'transfer' || p.id === 'bank_transfer');
        });
        if (transferPayments.length > 0) {
            const pm = transferPayments[0];
            transferHtml = `
                <div class="transfer-details" style="background:var(--bg2);border-radius:10px;padding:12px;margin:10px 0;border:1px solid var(--border);">
                    <div style="font-weight:700;font-size:14px;margin-bottom:8px;color:var(--text2);">
                        <i class="fa-solid fa-building-columns"></i> تفاصيل التحويل البنكي
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">💰 المبلغ المطلوب تحويله</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">${total.toFixed(0)} د.ع</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">🏦 رقم الحساب</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);direction:ltr;">${escapeHtml(pm.accountNumber || '')}</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">👤 المستفيد</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">${escapeHtml(pm.beneficiary || '')}</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">📝 المبلغ الذي حولته</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">
                            <input type="number" id="transferAmount" value="${total.toFixed(0)}" step="0.01" min="0"
                                   style="width:120px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;font-size:13px;">
                        </span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:none;">
                        <span class="detail-label" style="color:var(--text3);">🖼️ صورة التحويل</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">
                            <input type="file" id="transferImageInput" accept="image/*" style="font-size:12px;padding:4px;">
                            <div id="transferPreview" style="width:80px;height:80px;border-radius:8px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:4px;font-size:24px;color:var(--text3);border:1px dashed var(--border);">
                                <i class="fa-solid fa-image"></i>
                            </div>
                        </span>
                    </div>
                </div>
            `;
        }
    }

    section.innerHTML = `
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:14px;box-shadow:var(--shadow);">
            <h3 style="font-size:16px;font-weight:800;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-receipt" style="color:var(--primary);"></i> إتمام الطلب
            </h3>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                    <i class="fa-solid fa-location-dot"></i> العنوان <span style="color:var(--red);">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="checkoutAddress" placeholder="أدخل عنوانك بالكامل" required
                           style="flex:1;min-width:150px;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    <button type="button" onclick="getCurrentLocation()" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-location-crosshairs"></i> <span>تحديد موقعي</span>
                    </button>
                </div>
                <div id="locationStatus" style="font-size:11px;color:var(--text3);margin-top:4px;display:none;">
                    <i class="fa-solid fa-spinner fa-spin"></i> جاري تحديد الموقع...
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                    <i class="fa-solid fa-phone"></i> رقم المستلم <span style="color:var(--red);">*</span>
                </label>
                <input type="tel" id="checkoutPhone" placeholder="07XX XXX XXXX" required
                       style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
            </div>

            <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--text2);">
                <i class="fa-solid fa-credit-card"></i> اختر طريقة الدفع
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0;">
                ${paymentOptions.map(function(pm) {
                    const isActive = state.selectedPayment === pm.id;
                    return `
                        <div class="payment-option ${isActive ? 'active' : ''}" data-payment="${pm.id}" onclick="selectPayment('${pm.id}')">
                            <i class="${pm.icon}" style="font-size:18px;display:block;margin-bottom:2px;"></i>
                            <div class="pm-name" style="font-size:11px;font-weight:600;">${pm.name}</div>
                            ${isActive ? '<div style="font-size:9px;color:var(--primary);margin-top:2px;">✅</div>' : ''}
                        </div>
                    `;
                }).join('')}
            </div>
            ${transferHtml}

            <div style="display:flex;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border);margin:10px 0;">
                <span style="font-weight:700;color:var(--text2);">${t('total')}</span>
                <span style="font-size:20px;font-weight:900;color:var(--primary);">${total.toFixed(0)} د.ع</span>
            </div>

            ${state.selectedPayment === 'electronic' && state.balance < total ? `
                <div style="background:rgba(231,76,60,0.1);border:2px solid var(--red);border-radius:10px;padding:12px;margin:10px 0;text-align:center;">
                    <p style="color:var(--red);font-weight:700;">⚠️ رصيدك غير كافي!</p>
                    <button onclick="openRechargePage()" class="btn btn-sm btn-primary" style="margin-top:8px;">
                        <i class="fa-solid fa-plus"></i> شحن الرصيد
                    </button>
                </div>
            ` : ''}

            <div class="checkout-actions" style="display:flex;gap:10px;margin-top:14px;">
                <button class="btn btn-lg btn-primary" style="flex:1;" onclick="completeOrder()">
                    <i class="fa-solid fa-check"></i> إتمام الطلب
                </button>
                <button class="btn btn-lg btn-outline" style="flex:1;" onclick="clearCart()">
                    إلغاء
                </button>
            </div>
        </div>
    `;

    const transferInput = $('transferImageInput');
    if (transferInput) {
        transferInput.addEventListener('change', function() {
            const preview = $('transferPreview');
            if (this.files && this.files[0] && preview) {
                if (this.files[0].size > 5 * 1024 * 1024) {
                    showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
                    this.value = '';
                    preview.innerHTML = '<i class="fa-solid fa-image"></i>';
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
}

function selectPayment(id) {
    state.selectedPayment = id;
    renderCheckout();
}

function completeOrder() {
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }

    if (!state.cart || state.cart.length === 0) {
        showToast('⚠️ السلة فارغة');
        return;
    }

    const addressInput = document.getElementById('checkoutAddress');
    const phoneInput = document.getElementById('checkoutPhone');

    const address = addressInput ? addressInput.value.trim() : '';
    const phone = phoneInput ? phoneInput.value.trim() : '';

    if (!address) {
        showToast('⚠️ الرجاء إدخال عنوان التوصيل');
        if (addressInput) {
            addressInput.style.borderColor = 'var(--red)';
            setTimeout(function() { addressInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }

    if (!phone) {
        showToast('⚠️ الرجاء إدخال رقم الهاتف');
        if (phoneInput) {
            phoneInput.style.borderColor = 'var(--red)';
            setTimeout(function() { phoneInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }

    if (!/^07[0-9]{8,10}$/.test(phone)) {
        showToast('⚠️ رقم الهاتف يجب أن يبدأ بـ 07 ويتكون من 10-12 رقم');
        if (phoneInput) {
            phoneInput.style.borderColor = 'var(--red)';
            setTimeout(function() { phoneInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }

    const total = getCartTotal();
    const payment = state.selectedPayment || 'cash';

    if (payment === 'electronic' && state.balance < total) {
        showToast('⚠️ رصيدك غير كافي، يرجى شحن الرصيد');
        return;
    }

    let transferImage = null;
    let transferAmount = 0;
    let accountNumber = '';
    let beneficiary = '';

    if (payment === 'transfer' || payment === 'bank_transfer') {
        const fileInput = document.getElementById('transferImageInput');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            showToast('⚠️ الرجاء رفع صورة التحويل');
            if (fileInput) {
                fileInput.style.borderColor = 'var(--red)';
                setTimeout(function() { fileInput.style.borderColor = 'var(--border)'; }, 3000);
            }
            return;
        }

        if (fileInput.files[0].size > 5 * 1024 * 1024) {
            showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            fileInput.value = '';
            const preview = document.getElementById('transferPreview');
            if (preview) {
                preview.innerHTML = '<i class="fa-solid fa-image"></i>';
                preview.style.borderColor = 'var(--border)';
            }
            return;
        }

        const amountInput = document.getElementById('transferAmount');
        transferAmount = parseFloat(amountInput ? amountInput.value : 0);
        if (transferAmount <= 0) {
            showToast('⚠️ الرجاء إدخال المبلغ المحول');
            return;
        }
        transferImage = fileInput.files[0];

        const transferPayments = state.payments.filter(function(p) {
            return p.enabled && (p.id === 'transfer' || p.id === 'bank_transfer');
        });
        if (transferPayments.length > 0) {
            accountNumber = transferPayments[0].accountNumber || '';
            beneficiary = transferPayments[0].beneficiary || '';
        }
    }

    const cartItems = state.cart.map(function(item) {
        return {
            id: item.id,
            name: item.name,
            price: item.price,
            qty: item.qty || 1,
            image_url: item.image_url || ''
        };
    });

    const formData = new FormData();
    formData.append('userId', state.user.id);
    formData.append('address', address);
    formData.append('phone', phone);
    formData.append('payment', payment);
    formData.append('items', JSON.stringify(cartItems));
    formData.append('total', total);

    if (transferImage) {
        formData.append('transferImage', transferImage);
        formData.append('transferAmount', transferAmount);
        formData.append('accountNumber', accountNumber);
        formData.append('beneficiary', beneficiary);
    }

    const completeBtn = document.querySelector('.checkout-actions .btn-primary');
    if (completeBtn) {
        completeBtn.disabled = true;
        completeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري المعالجة...';
    }

    showToast('⏳ جاري إنشاء الطلب...');

    callAPI('addOrderWithTransfer', formData, 'POST')
        .then(function(response) {
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="fa-solid fa-check"></i> إتمام الطلب';
            }

            if (response.success) {
                if (payment === 'electronic') {
                    state.balance -= total;
                    if (state.user) state.user.balance = state.balance;
                    updateBalanceDisplay();
                }

                showOrderSuccess(response.data);

                state.cart = [];
                renderCart();
                updateCartBadge();

                loadAllData(true);

                showToast('✅ تم إنشاء الطلب بنجاح');
                if (navigator.vibrate) navigator.vibrate([10, 50, 10]);
            } else {
                showToast('❌ ' + (response.message || 'فشل إنشاء الطلب'));
            }
        })
        .catch(function(error) {
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="fa-solid fa-check"></i> إتمام الطلب';
            }
            showToast('❌ حدث خطأ في الاتصال: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
        });
}

function showOrderSuccess(order) {
    const overlay = document.getElementById('orderSuccessOverlay');
    if (!overlay) return;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
    if (navigator.vibrate) navigator.vibrate([10, 50, 10]);
}

function closeSuccessModal() {
    const overlay = document.getElementById('orderSuccessOverlay');
    if (overlay) {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

// ============================================================
// GET CURRENT LOCATION
// ============================================================

function getCurrentLocation() {
    const statusEl = document.getElementById('locationStatus');
    const addressInput = document.getElementById('checkoutAddress');

    if (!navigator.geolocation) {
        showToast('⚠️ متصفحك لا يدعم تحديد الموقع');
        return;
    }

    if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري تحديد الموقع...';
        statusEl.style.color = 'var(--text3)';
    }

    navigator.geolocation.getCurrentPosition(
        function(position) {
            getAddressFromCoords(position.coords.latitude, position.coords.longitude);
        },
        function(error) {
            let errorMsg = '';
            switch (error.code) {
                case error.PERMISSION_DENIED: errorMsg = '❌ تم رفض صلاحية الوصول إلى الموقع'; break;
                case error.POSITION_UNAVAILABLE: errorMsg = '❌ معلومات الموقع غير متوفرة'; break;
                case error.TIMEOUT: errorMsg = '⏰ انتهت مهلة تحديد الموقع'; break;
                default: errorMsg = '❌ حدث خطأ في تحديد الموقع';
            }
            if (statusEl) {
                statusEl.style.display = 'block';
                statusEl.innerHTML = errorMsg;
                statusEl.style.color = 'var(--red)';
                setTimeout(function() { statusEl.style.display = 'none'; }, 4000);
            }
            showToast(errorMsg);
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
    );
}

function getAddressFromCoords(lat, lng) {
    const statusEl = document.getElementById('locationStatus');
    const addressInput = document.getElementById('checkoutAddress');

    if (statusEl) {
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري تحويل الموقع إلى عنوان...';
        statusEl.style.color = 'var(--text3)';
    }

    const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=ar`;

    fetch(url)
        .then(function(response) {
            if (!response.ok) throw new Error('فشل في جلب العنوان');
            return response.json();
        })
        .then(function(data) {
            if (data && data.display_name) {
                if (addressInput) {
                    addressInput.value = data.display_name;
                    addressInput.style.borderColor = 'var(--green)';
                }
                if (statusEl) {
                    statusEl.innerHTML = '✅ تم تحديد الموقع بنجاح';
                    statusEl.style.color = 'var(--green)';
                    setTimeout(function() {
                        statusEl.style.display = 'none';
                        if (addressInput) addressInput.style.borderColor = 'var(--border)';
                    }, 3000);
                }
                showToast('✅ تم تحديد موقعك بنجاح');
            } else {
                throw new Error('لا يوجد عنوان لهذا الموقع');
            }
        })
        .catch(function() {
            const coordsAddress = `📍 ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            if (addressInput) addressInput.value = coordsAddress;
            if (statusEl) {
                statusEl.innerHTML = '✅ تم تحديد الإحداثيات';
                statusEl.style.color = 'var(--green)';
                setTimeout(function() { statusEl.style.display = 'none'; }, 3000);
            }
            showToast('✅ تم تحديد الإحداثيات');
        });
}
