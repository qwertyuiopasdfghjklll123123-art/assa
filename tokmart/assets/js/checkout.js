/* ============================================================
   Tokmart - Checkout / place order
   ============================================================ */
function clearAllIntervals() {
    Object.keys(state.chatIntervals).forEach(function(key) {
        if (state.chatIntervals[key]) {
            clearInterval(state.chatIntervals[key]);
            state.chatIntervals[key] = null;
        }
    });
}
function renderCheckout() {
    const section = $('checkoutSection');
    if (state.cart.length === 0 || !state.isLoggedIn) {
        section.style.display = 'none';
        return;
    }
    section.style.display = 'block';
    const total = getCartTotal();
    const paymentOptions = state.payments.filter(function(p) { return p.enabled && !p.isRechargeOnly; });
    
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
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">${total.toFixed(2)} د.ع</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">🏦 رقم الحساب</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);direction:ltr;">${escapeHtml(pm.accountNumber || '7114152353')}</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">👤 المستفيد</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">${escapeHtml(pm.beneficiary || 'الحوالة تتم عبر سوبر كي حصرا بعد قم بتحويل وارفاق صوره للتحويل  وانتظر موافقة والاضافة خلال دقائق')}</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">📝 المبلغ الذي حولته</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">
                            <input type="number" id="transferAmount" value="${total.toFixed(2)}" step="0.01" min="0" 
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
                           style="flex:1;min-width:150px;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;transition:border-color 0.3s;">
                    <button type="button" onclick="getCurrentLocation()" 
                            style="padding:10px 14px;border:none;border-radius:10px;background:var(--primary-light);color:#fff;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;transition:all 0.3s;display:flex;align-items:center;gap:6px;">
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
                        <div class="payment-option ${isActive ? 'active' : ''}" data-payment="${pm.id}" onclick="selectPayment('${pm.id}')" 
                             style="padding:8px 10px;border:1.5px solid ${isActive ? '#0f3d1c' : 'var(--border)'};border-radius:10px;text-align:center;cursor:pointer;background:${isActive ? 'rgba(15,61,28,0.06)' : 'var(--surface)'};transition:all 0.3s;box-shadow:${isActive ? '0 0 0 2px rgba(15,61,28,0.1)' : 'none'};">
                            <i class="${pm.icon}" style="font-size:18px;display:block;margin-bottom:2px;color:${isActive ? '#0f3d1c' : 'var(--text3)'};"></i>
                            <div class="pm-name" style="font-size:11px;font-weight:600;color:${isActive ? '#0f3d1c' : 'var(--text2)'};">${pm.name}</div>
                            ${isActive ? '<div style="font-size:9px;color:#0f3d1c;margin-top:2px;">✅</div>' : ''}
                        </div>
                    `;
                }).join('')}
            </div>
            ${transferHtml}
            
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border);margin:10px 0;">
                <span style="font-weight:700;color:var(--text2);">${t('total')}</span>
                <span style="font-size:20px;font-weight:900;color:var(--primary);">${total.toFixed(2)} د.ع</span>
            </div>
            
            ${state.selectedPayment === 'electronic' && state.balance < total ? `
                <div style="background:rgba(231,76,60,0.1);border:2px solid var(--red);border-radius:10px;padding:12px;margin:10px 0;text-align:center;">
                    <p style="color:var(--red);font-weight:700;">⚠️ رصيدك غير كافي!</p>
                    <button onclick="openRechargePage()" style="padding:8px 20px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-weight:700;cursor:pointer;margin-top:8px;">
                        <i class="fa-solid fa-plus"></i> شحن الرصيد
                    </button>
                </div>
            ` : ''}
            
            <div class="checkout-actions" style="display:flex;gap:10px;margin-top:14px;">
                <button class="btn-complete" onclick="completeOrder()" style="flex:1;padding:12px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;background:var(--primary-light);color:#fff;transition:all 0.3s;">
                    <i class="fa-solid fa-check"></i> إتمام الطلب
                </button>
                <button class="btn-cancel" onclick="clearCart()" style="flex:1;padding:12px;border:2px solid var(--border);border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;background:var(--surface);color:var(--text2);transition:all 0.3s;">
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
    
    $$('.payment-option').forEach(function(el) {
        const isActive = el.dataset.payment === id;
        el.classList.toggle('active', isActive);
        if (isActive) {
            el.style.borderColor = 'var(--primary)';
            el.style.background = 'var(--acSh)';
            el.style.boxShadow = '0 0 0 3px rgba(5,134,147,.1)';
            let statusEl = el.querySelector('.pm-status');
            if (!statusEl) {
                statusEl = document.createElement('div');
                statusEl.className = 'pm-status';
                statusEl.style.cssText = 'font-size:10px;color:var(--green);margin-top:4px;';
                el.appendChild(statusEl);
            }
            statusEl.textContent = '✅ مختار';
        } else {
            el.style.borderColor = 'var(--border)';
            el.style.background = 'var(--surface)';
            el.style.boxShadow = 'none';
            const statusEl = el.querySelector('.pm-status');
            if (statusEl) statusEl.remove();
        }
    });
    
    renderCheckout();
}

function completeOrder() {
    console.log('🔄 بدء عملية إتمام الطلب...');
    
    // التحقق من تسجيل الدخول
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }
    
    // التحقق من السلة
    if (!state.cart || state.cart.length === 0) {
        showToast('⚠️ السلة فارغة');
        return;
    }
    
    // جلب البيانات من الحقول
    const addressInput = document.getElementById('checkoutAddress');
    const phoneInput = document.getElementById('checkoutPhone');
    
    const address = addressInput ? addressInput.value.trim() : '';
    const phone = phoneInput ? phoneInput.value.trim() : '';
    
    // التحقق من الحقول
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
    
    // التحقق من صحة رقم الهاتف
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
    
    // التحقق من الرصيد للدفع الإلكتروني
    if (payment === 'electronic' && state.balance < total) {
        showToast('⚠️ رصيدك غير كافي، يرجى شحن الرصيد');
        return;
    }
    
    // التحقق من صورة التحويل للدفع البنكي
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
        
        // جلب تفاصيل الحساب البنكي
        const transferPayments = state.payments.filter(function(p) { 
            return p.enabled && (p.id === 'transfer' || p.id === 'bank_transfer'); 
        });
        if (transferPayments.length > 0) {
            accountNumber = transferPayments[0].accountNumber || '';
            beneficiary = transferPayments[0].beneficiary || '';
        }
    }
    
    // تجهيز بيانات المنتجات
    const cartItems = state.cart.map(function(item) { 
        return { 
            id: item.id,
            name: item.name,
            price: item.price,
            qty: item.qty || 1,
            image_url: item.image_url || ''
        }; 
    });
    
    console.log('📦 المنتجات المرسلة:', cartItems);
    
    // إنشاء FormData
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
    
    // تعطيل الزر
    const completeBtn = document.querySelector('.btn-complete');
    if (completeBtn) {
        completeBtn.disabled = true;
        completeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري المعالجة...';
        completeBtn.style.opacity = '0.6';
        completeBtn.style.pointerEvents = 'none';
    }
    
    showToast('⏳ جاري إنشاء الطلب...');
    
    // إرسال الطلب
    callAPI('addOrderWithTransfer', formData, 'POST')
        .then(function(response) {
            console.log('📨 استجابة الخادم:', response);
            
            // إعادة تفعيل الزر
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="fa-solid fa-check"></i> إتمام الطلب';
                completeBtn.style.opacity = '1';
                completeBtn.style.pointerEvents = 'auto';
            }
            
            if (response.success) {
                // خصم الرصيد للدفع الإلكتروني
                if (payment === 'electronic') {
                    state.balance -= total;
                    if (state.user) state.user.balance = state.balance;
                    updateBalanceDisplay();
                }
                
                // عرض رسالة النجاح
                showOrderSuccess(response.data);
                
                // تفريغ السلة
                state.cart = [];
                renderCart();
                updateCartBadge();
                
                // تحديث البيانات
                loadAllData(true);
                
                showToast('✅ تم إنشاء الطلب بنجاح');
                
                // اهتزاز للتأكيد
                if (navigator.vibrate) navigator.vibrate([10, 50, 10]);
                
            } else {
                showToast('❌ ' + (response.message || 'فشل إنشاء الطلب'));
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في إنشاء الطلب:', error);
            
            // إعادة تفعيل الزر
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="fa-solid fa-check"></i> إتمام الطلب';
                completeBtn.style.opacity = '1';
                completeBtn.style.pointerEvents = 'auto';
            }
            
            showToast('❌ حدث خطأ في الاتصال: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
        });
}

function showOrderSuccess(order) {
    console.log('📦 عرض تفاصيل الطلب الناجح:', order);
    
    const overlay = document.getElementById('orderSuccessOverlay');
    if (!overlay) {
        console.warn('⚠️ عنصر orderSuccessOverlay غير موجود');
        return;
    }
    
    // تحديث محتوى البطاقة
    const orderNumber = document.getElementById('orderNumber');
    const orderDate = document.getElementById('orderDate');
    const orderAddress = document.getElementById('orderAddress');
    const orderPhone = document.getElementById('orderPhone');
    const orderPayment = document.getElementById('orderPayment');
    const orderProductsList = document.getElementById('orderProductsList');
    const orderTotal = document.getElementById('orderTotal');
    
    if (orderNumber) orderNumber.textContent = order.orderId || order.id || 'غير محدد';
    if (orderDate) orderDate.textContent = order.date || new Date().toLocaleDateString('ar-EG');
    if (orderAddress) orderAddress.textContent = order.address || 'غير محدد';
    if (orderPhone) orderPhone.textContent = order.phone || 'غير محدد';
    
    if (orderPayment) {
        const paymentNames = {
            'cash': 'الدفع عند الاستلام',
            'electronic': 'الدفع الإلكتروني',
            'transfer': 'تحويل بنكي',
            'bank_transfer': 'تحويل بنكي'
        };
        orderPayment.textContent = paymentNames[order.payment] || order.payment || 'غير محدد';
    }
    
    // عرض المنتجات
    let items = [];
    try {
        if (order.items) {
            if (typeof order.items === 'string') {
                items = JSON.parse(order.items);
            } else if (Array.isArray(order.items)) {
                items = order.items;
            } else if (typeof order.items === 'object') {
                items = Object.values(order.items);
            }
        }
    } catch (e) {
        console.warn('⚠️ خطأ في تحليل items:', e);
        items = [];
    }
    
    if (!Array.isArray(items)) {
        items = [];
    }
    
    if (orderProductsList) {
        if (items.length > 0) {
            orderProductsList.innerHTML = items.map(function(item) {
                const itemName = item.name || 'منتج';
                const itemQty = item.qty || 1;
                const itemPrice = parseFloat(item.price) || 0;
                const totalPrice = itemPrice * itemQty;
                return `
                    <div class="product-item" style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span>${itemName} × ${itemQty}</span>
                        <span style="font-weight:700;color:var(--primary);">${totalPrice.toFixed(2)} د.ع</span>
                    </div>
                `;
            }).join('');
        } else {
            orderProductsList.innerHTML = '<div style="text-align:center;padding:10px;color:var(--text3);font-size:13px;">⚠️ لا توجد منتجات</div>';
        }
    }
    
    // عرض المجموع
    if (orderTotal) {
        const span = orderTotal.querySelector('span:last-child');
        if (span) {
            const totalAmount = parseFloat(order.total) || 0;
            span.textContent = totalAmount.toFixed(2) + ' د.ع';
        }
    }
    
    // إظهار النافذة
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
    
    if (navigator.vibrate) navigator.vibrate([10, 50, 10]);
}
// ============================================================
// GET CURRENT LOCATION
// ============================================================

