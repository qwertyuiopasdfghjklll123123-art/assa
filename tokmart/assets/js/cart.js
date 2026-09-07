/* ============================================================
   Tokmart - Cart
   ============================================================ */

function addToCart(product) {
    const existing = state.cart.find(function(item) { return item.id === product.id; });
    if (existing) { existing.qty = (existing.qty || 1) + 1; }
    else { state.cart.push({ ...product, qty: 1 }); }
    renderCart();
    updateCartBadge();
    showToast('✅ تمت الإضافة للسلة');
    if (navigator.vibrate) navigator.vibrate(10);
}

function removeFromCart(index) {
    const item = state.cart[index];
    if (item && item.qty > 1) { item.qty--; }
    else { state.cart.splice(index, 1); }
    renderCart();
    updateCartBadge();
    if (navigator.vibrate) navigator.vibrate(5);
}

function getCartTotal() {
    return state.cart.reduce(function(sum, item) { return sum + (item.price || 0) * (item.qty || 1); }, 0);
}

function renderCart() {
    const container = $('cartContent');

    if (state.cart.length === 0) {
        if (container) {
            container.innerHTML = `
                <div class="cart-empty" style="text-align:center;padding:60px 20px;color:var(--text3);">
                    <i class="fa-regular fa-face-frown" style="font-size:72px;margin-bottom:16px;display:block;color:var(--primary);opacity:.3;"></i>
                    <h3 style="font-size:20px;font-weight:700;color:var(--text2);margin-bottom:6px;">${t('cart_empty')}</h3>
                    <p style="font-size:14px;color:var(--text3);margin-bottom:20px;">أضف منتجاتك الآن وابدأ التسوق</p>
                    <button class="btn btn-sm btn-primary" onclick="switchPage('page-home')">
                        <i class="fa-solid fa-bag-shopping"></i> ابدأ التسوق
                    </button>
                </div>
            `;
        }
        const section = $('checkoutSection');
        if (section) section.style.display = 'none';
        updateCartBadge();
        return;
    }

    let html = '', total = 0;
    state.cart.forEach(function(item, index) {
        const price = item.price || 0;
        const qty = item.qty || 1;
        total += price * qty;
        const imgSrc = item.image_url || '';
        const imgHtml = imgSrc ? '<img src="' + imgSrc + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;">' : '<i class="' + (item.icon || 'fa-solid fa-box') + '"></i>';
        html += `
            <div class="cart-item" style="display:flex;align-items:center;gap:12px;padding:14px;background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:10px;">
                <div class="cart-img" style="width:50px;height:50px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;font-size:20px;color:var(--primary);flex-shrink:0;">${imgHtml}</div>
                <div class="info" style="flex:1;min-width:0;">
                    <h4 style="font-size:14px;font-weight:700;word-break:break-word;">${item.name}</h4>
                    <p style="font-size:12px;color:var(--text3);">الكمية: ${qty}</p>
                </div>
                <div class="price" style="font-weight:800;color:var(--primary);font-size:15px;white-space:nowrap;">${price.toFixed(0)} د.ع</div>
                <button class="remove-btn" onclick="removeFromCart(${index})" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:18px;padding:8px;border-radius:10px;">
                    <i class="fa-regular fa-trash-can"></i>
                </button>
            </div>
        `;
    });
    html += `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 4px;border-top:1px solid var(--border);margin-top:8px;">
            <span style="font-weight:700;font-size:16px;color:var(--text2);">${t('total')}: <span style="color:var(--primary);font-size:18px;">${total.toFixed(0)} د.ع</span></span>
            <button class="icon-btn" onclick="clearCart()" style="color:var(--red);border-color:rgba(231,76,60,.3);background:var(--surface);">
                <i class="fa-regular fa-trash-can"></i>
            </button>
        </div>
    `;
    if (container) container.innerHTML = html;
    updateCartBadge();
    renderCheckout();
}

function clearCart() {
    if (confirm('هل تريد تفريغ السلة؟')) {
        state.cart = [];
        renderCart();
        updateCartBadge();
        showToast('🗑️ تم تفريغ السلة');
    }
}

function updateCartBadge() {
    const badge = $('cartBadge');
    const count = state.cart.reduce(function(sum, item) { return sum + (item.qty || 1); }, 0);
    if (badge) {
        badge.style.display = count > 0 ? 'flex' : 'none';
        badge.textContent = count;
    }
}
