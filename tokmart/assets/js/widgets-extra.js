/* ============================================================
   Tokmart - Order-support chat widget, push notifications, product reviews
   ============================================================ */
// ============================================================
// جافا سكريبت - النسخة النهائية
// ============================================================

// ============================================================
// دعم الطلب - التواصل مع الدعم الفني
// ============================================================

/**
 * فتح الدردشة مع الدعم بخصوص طلب معين
 */
function openOrderSupportChat(orderId) {
    console.log('📞 فتح الدردشة للطلب:', orderId);
    
    // التأكد من تسجيل الدخول
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }
    
    // البحث عن الطلب
    const order = state.orders.find(function(o) {
        return o.id == orderId || o.orderId == orderId;
    });
    
    if (!order) {
        showToast('⚠️ الطلب غير موجود');
        return;
    }
    
    // حفظ رقم الطلب للرجوع إليه لاحقاً
    try {
        localStorage.setItem('last_order_id', orderId);
    } catch (e) {}
    
    // إغلاق صفحة تفاصيل الطلب
    const detailPage = document.getElementById('orderDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
    }
    
    // فتح صفحة الدردشة
    const chatPage = document.getElementById('chatPageUser');
    if (!chatPage) {
        showToast('⚠️ صفحة الدردشة غير متوفرة');
        return;
    }
    
    // إظهار صفحة الدردشة
    chatPage.classList.add('open');
    document.body.style.overflow = 'hidden';
    state.isUserChatOpen = true;
    updateBottomNavVisibility();
    
    // إعادة تعيين المحادثة
    resetUserChat();
    
    // إضافة رسالة تمهيدية عن الطلب
    const orderNumber = order.orderId || order.id;
    const orderTotal = parseFloat(order.total || 0).toFixed(2);
    const orderDate = order.date || new Date(order.createdAt).toLocaleDateString('ar-EG');
    
    setTimeout(function() {
        const container = document.getElementById('userMessagesContainer');
        if (!container) return;
        
        // إزالة رسالة "ابدأ محادثتك"
        const emptyMsg = document.getElementById('emptyChatMessage');
        if (emptyMsg) emptyMsg.remove();
        
        // إزالة الإشعارات القديمة
        const notification = document.getElementById('newMsgNotification');
        if (notification) notification.remove();
        
        // إضافة رسالة النظام
        const systemMsg = document.createElement('div');
        systemMsg.className = 'message received';
        systemMsg.style.cssText = 'align-self:center;max-width:92%;margin:8px 0;';
        systemMsg.innerHTML = `
            <div class="message-content" style="background:var(--bg2);border-radius:16px;padding:14px 18px;border:1px solid var(--border);text-align:center;">
                <div class="msg-text" style="font-size:13px;color:var(--text2);">
                    <div style="font-size:28px;margin-bottom:8px;">💬</div>
                    <div style="font-weight:700;font-size:15px;color:var(--text);">الدعم الفني للطلب</div>
                    <div style="margin:6px 0;padding:8px;background:var(--surface);border-radius:8px;border:1px solid var(--border);">
                        <div style="font-weight:700;color:var(--primary);">#${orderNumber}</div>
                        <div style="font-size:12px;color:var(--text3);">💰 ${orderTotal} د.ع | 📅 ${orderDate}</div>
                        <div style="font-size:12px;color:var(--text3);">📌 الحالة: ${getOrderStatusText(order.status)}</div>
                    </div>
                    <div style="font-size:12px;color:var(--text3);margin-top:6px;">
                        <i class="fa-regular fa-clock"></i> فريق الدعم متصل الآن، اكتب رسالتك
                    </div>
                </div>
            </div>
        `;
        container.appendChild(systemMsg);
        
        // التركيز على حقل الإدخال
        const input = document.getElementById('userChatInput');
        if (input) {
            setTimeout(function() {
                input.focus();
                input.placeholder = 'اكتب رسالتك بخصوص الطلب #' + orderNumber + '...';
            }, 400);
        }
        
        // التمرير للأسفل
        container.scrollTop = container.scrollHeight;
    }, 400);
    
    // بدء تحديث الرسائل
    if (state.chatIntervals.user) {
        clearInterval(state.chatIntervals.user);
    }
    state.chatIntervals.user = setInterval(loadUserChatMessages, 5000);
    
    // تحميل رسائل المحادثة السابقة
    loadUserChatMessages();
    
    showToast('💬 تم فتح الدردشة مع الدعم');
}

/**
 * الحصول على نص حالة الطلب
 */
function getOrderStatusText(status) {
    const statusMap = {
        'pending': '⏳ قيد المعالجة',
        'shipped': '🚚 قيد التوصيل',
        'completed': '✅ مكتمل',
        'cancelled': '❌ ملغي',
        'approved': '✅ تم الموافقة',
        'rejected': '❌ مرفوض'
    };
    return statusMap[status] || status || 'غير معروف';
}

/**
 * إغلاق الدردشة والعودة إلى تفاصيل الطلب
 */
function closeChatAndReturn() {
    console.log('🔙 العودة من الدردشة');
    
    // إغلاق الدردشة
    const chatPage = document.getElementById('chatPageUser');
    if (chatPage) {
        chatPage.classList.remove('open');
    }
    
    document.body.style.overflow = '';
    state.isUserChatOpen = false;
    updateBottomNavVisibility();
    
    // إيقاف تحديث الرسائل
    if (state.chatIntervals.user) {
        clearInterval(state.chatIntervals.user);
        state.chatIntervals.user = null;
    }
    
    // العودة إلى تفاصيل الطلب
    const lastOrderId = localStorage.getItem('last_order_id');
    if (lastOrderId) {
        setTimeout(function() {
            // البحث عن الطلب في state.orders
            const order = state.orders.find(function(o) {
                return o.id == lastOrderId || o.orderId == lastOrderId;
            });
            if (order) {
                openOrderDetail(order.id);
                // مسح الـ ID بعد العودة
                localStorage.removeItem('last_order_id');
            } else {
                // إذا لم يتم العثور على الطلب، افتح صفحة الطلبات
                openOrders();
            }
        }, 300);
    } else {
        // العودة إلى الطلبات
        setTimeout(function() {
            openOrders();
        }, 300);
    }
}

/**
 * إرسال رسالة دعم بخصوص الطلب
 */
function sendOrderSupportMessage(orderId, message) {
    if (!orderId || !message) return;
    
    // إرسال إشعار للأدمن مع رقم الطلب
    callAPI('sendOrderSupportMessage', {
        orderId: orderId,
        message: message
    }, 'POST').then(function(response) {
        if (!response.success) {
            console.warn('⚠️ فشل إرسال إشعار الدعم:', response.message);
        }
    });
}

/**
 * دالة محسنة لإرسال رسالة المستخدم مع دعم الطلبات
 */
function sendUserMessage() {
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول');
        return;
    }
    
    const input = document.getElementById('userChatInput');
    if (!input) {
        showToast('⚠️ حقل الإدخال غير موجود');
        return;
    }
    
    const msg = input.value.trim();
    if (!msg) {
        showToast('⚠️ لا يمكن إرسال رسالة فارغة');
        return;
    }
    
    // تعطيل الإدخال مؤقتاً
    input.disabled = true;
    const sendBtn = document.querySelector('.send-btn');
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    }
    
    // محاولة استخراج رقم الطلب من الرسالة التمهيدية أو من localStorage
    let orderId = localStorage.getItem('last_order_id') || null;
    
    // إرسال الرسالة
    callAPI('sendChatMessage', {
        userId: state.user.id,
        adminId: 1,
        message: msg,
        messageType: 'text',
        sender: 'user'
    }, 'POST')
        .then(function(response) {
            // إعادة تفعيل الإدخال
            input.disabled = false;
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال';
            }
            
            if (response.success) {
                // إضافة الرسالة إلى الواجهة
                const container = document.getElementById('userMessagesContainer');
                if (container) {
                    // إزالة رسالة "لا توجد رسائل"
                    const emptyMsg = document.getElementById('emptyChatMessage');
                    if (emptyMsg) emptyMsg.remove();
                    
                    const msgDiv = document.createElement('div');
                    msgDiv.className = 'message sent';
                    msgDiv.style.cssText = 'align-self:flex-end;animation:messageSlideIn 0.3s var(--ease-out);';
                    const now = new Date();
                    const timeStr = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
                    
                    msgDiv.innerHTML = `
                        <div class="message-content" style="background:#0f3d1c;color:#fff;border-radius:16px;border-bottom-right-radius:4px;padding:10px 14px;max-width:100%;">
                            <div class="msg-text" style="font-size:14px;line-height:1.6;white-space:pre-wrap;word-break:break-word;margin:0;color:#fff;">${escapeHtml(msg)}</div>
                            <div class="msg-footer" style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:4px;font-size:10px;opacity:0.7;color:rgba(255,255,255,0.8);">
                                <span class="msg-time">${timeStr}</span>
                            </div>
                        </div>
                    `;
                    container.appendChild(msgDiv);
                    container.scrollTop = container.scrollHeight;
                }
                
                input.value = '';
                input.placeholder = 'اكتب رسالتك...';
                showToast('✅ تم إرسال رسالتك');
                if (navigator.vibrate) navigator.vibrate(10);
                
                // إذا كان هناك طلب، أرسل إشعار دعم
                if (orderId) {
                    sendOrderSupportMessage(orderId, msg);
                }
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال الرسالة'));
                input.value = msg;
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في إرسال الرسالة:', error);
            input.disabled = false;
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال';
            }
            showToast('❌ حدث خطأ في الاتصال');
            input.value = msg;
        });
}


// 1. الدالة الأساسية لتحليل واستخراج المنتجات بأمان من أي شكل بيانات
function getOrderItems(order) {
    let items = [];
    
    try {
        let rawItems = order.items || order.cart || order.products;
        
        if (rawItems) {
            if (typeof rawItems === 'string') {
                const parsed = JSON.parse(rawItems);
                items = Array.isArray(parsed) ? parsed : [parsed];
            } else if (Array.isArray(rawItems)) {
                items = rawItems;
            } else if (typeof rawItems === 'object' && rawItems !== null) {
                items = Object.values(rawItems);
            }
        }
        
        if (!Array.isArray(items)) {
            items = [];
        }
        
        // تنظيف العناصر والتأكد أنها كائنات صحيحة
        items = items.filter(function(item) {
            return item && typeof item === 'object';
        });

    } catch (e) {
        console.error('⚠️ خطأ في معالجة منتجات الطلب:', e);
        items = [];
    }
    
    return items;
}

// 2. دالة رسم وعرض المنتجات داخل واجهة الطلب
function renderOrderProducts(order) {
    let items = getOrderItems(order);
    
    if (!items || items.length === 0) {
        return `
            <div style="text-align:center;padding:20px;color:var(--text3);">
                <i class="fa-regular fa-box-open" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                لا توجد منتجات مسجلة في هذا الطلب
            </div>
        `;
    }
    
    return items.map(function(item, index) {
        const price = parseFloat(item.price) || 0;
        const qty = parseInt(item.qty) || 1;
        const total = price * qty;
        const imgSrc = item.image_url || item.image || '';
        
        return `
            <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:${index < items.length - 1 ? '1px solid var(--border)' : 'none'};">
                <div style="width:48px;height:48px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;flex-shrink:0;border:1px solid var(--border);">
                    ${imgSrc ? `<img src="${imgSrc}" style="width:100%;height:100%;object-fit:cover;">` : `<i class="fa-solid fa-box" style="color:var(--primary);font-size:20px;"></i>`}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:13px;word-break:break-word;">${item.name || 'منتج غير معروف'}</div>
                    <div style="font-size:11px;color:var(--text3);">
                        الكمية: ${qty} × ${price.toFixed(2)} د.ع
                    </div>
                </div>
                <div style="font-weight:800;font-size:14px;color:var(--primary);white-space:nowrap;">
                    ${total.toFixed(2)} د.ع
                </div>
            </div>
        `;
    }).join('');
}

// 3. دالة فتح نافذة تفاصيل الطلب وربط البيانات
function openOrderDetail(order) {
    // افترض أن order هو كائن الطلب القادم من الاستعلام
    const body = document.getElementById('orderDetailBody');
    if (body) {
        body.innerHTML = renderOrderProducts(order);
    }
}



// فتح وإغلاق النافذة المنبثقة في منتصف الشاشة
function toggleReviewModal(show) {
    const modal = document.getElementById('reviewModalOverlay');
    if (modal) {
        modal.style.display = show ? 'flex' : 'none';
        if (show) {
            // إعادة ضبط المحتوى ليظهر النموذج الطبيعي عند الفتح
            document.getElementById('reviewFormContent').style.display = 'block';
            document.getElementById('reviewSuccessContent').style.display = 'none';
            document.getElementById('userReviewText').value = '';
            setRatingStar(5); // افتراضياً 5 نجوم
        }
    }
}

// دالة لتحديد وإضاءة النجوم عند الاختيار
function setRatingStar(val) {
    document.getElementById('selectedRatingValue').value = val;
    const stars = document.querySelectorAll('#interactive-star-picker i');
    stars.forEach((star, index) => {
        if (index < val) {
            star.style.color = '#f39c12'; // أصفر للنجوم المحددة
        } else {
            star.style.color = '#ddd';  // رمادي للنجوم الباقية
        }
    });
}

// إرسال التقييم وعرض رسالة "شكراً على تقيمك" مع علامة الصح
function submitProductReview(productId) {
    const rating = document.getElementById('selectedRatingValue').value;
    const review = document.getElementById('userReviewText').value;

    // إخفاء حقول الإدخال وإظهار رسالة النجاح المنبثقة
    document.getElementById('reviewFormContent').style.display = 'none';
    document.getElementById('reviewSuccessContent').style.display = 'block';

    // إغلاق النافذة المنبثقة تلقائياً بعد ثانيتين ونصف
    setTimeout(() => {
        toggleReviewModal(false);
    }, 500);
}

document.addEventListener("DOMContentLoaded", function () {
    const TWO_HOURS = 2 * 60 * 60 * 1000; // ساعتان بالمللي ثانية
    let endTime = localStorage.getItem("tokmart_discount_end");
    let now = new Date().getTime();

    // التحقق من الوقت أو إنشاء وقت جديد للساعتين
    if (!endTime || now >= parseInt(endTime)) {
        endTime = now + TWO_HOURS;
        localStorage.setItem("tokmart_discount_end", endTime);
    }

    const timer = setInterval(function () {
        let currentTime = new Date().getTime();
        let distance = parseInt(endTime) - currentTime;

        // إذا انتهى العداد، يعود للعد من جديد تلقائياً
        if (distance <= 0) {
            endTime = new Date().getTime() + TWO_HOURS;
            localStorage.setItem("tokmart_discount_end", endTime);
            distance = TWO_HOURS;
        }

        // حساب الساعات، الدقائق، والثواني
        let hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        let minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        let seconds = Math.floor((distance % (1000 * 60)) / 1000);

        // تنسيق الأرقام لتصبح دائماً خانتين (مثل 02 بدلاً من 2)
        const format = (num) => String(num).padStart(2, '0');

        // جلب العناصر وتحديثها مباشرة
        const hoursEl = document.querySelector(".t-hours");
        const minutesEl = document.querySelector(".t-minutes");
        const secondsEl = document.querySelector(".t-seconds");

        if (hoursEl) hoursEl.innerText = format(hours);
        if (minutesEl) minutesEl.innerText = format(minutes);
        if (secondsEl) secondsEl.innerText = format(seconds);

    }, 1000);
});
// ===== دوال Toast و API و State =====

