/* ============================================================
   Tokmart - Data loading, product/category rendering, favorites, orders
   ============================================================ */

const CACHE_DURATION = 5 * 60 * 1000;
const CACHE_KEY = 'Tokmart_ultra_cache';

function getCachedData() {
    try {
        const cached = localStorage.getItem(CACHE_KEY);
        if (!cached) return null;
        const data = JSON.parse(cached);
        if (Date.now() - data._timestamp < CACHE_DURATION) {
            return data;
        }
        return null;
    } catch (e) { return null; }
}

function setCachedData(data) {
    try {
        data._timestamp = Date.now();
        localStorage.setItem(CACHE_KEY, JSON.stringify(data));
    } catch (e) {}
}

function loadAllData(forceRefresh) {
    forceRefresh = forceRefresh || false;

    try {
        const savedUser = localStorage.getItem('Tokmart_user');
        if (savedUser && !state.isLoggedIn) {
            const user = JSON.parse(savedUser);
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;
            updateBalanceDisplay();
            updateAccountUI();
        }
    } catch (e) {}

    const cached = getCachedData();
    if (cached && !forceRefresh) {
        applyData(cached, true);
        setTimeout(function() {
            refreshDataInBackgroundSilent();
        }, 300);
        return;
    }

    callAPI('getData', null, 'GET').then(function(response) {
        if (response.success) {
            setCachedData(response.data);
            applyData(response.data, true);
        } else {
            const cached2 = getCachedData();
            if (cached2) {
                applyData(cached2, true);
            }
        }
    });
}

function refreshDataInBackgroundSilent() {
    setTimeout(function() {
        callAPI('getData', null, 'GET').then(function(response) {
            if (response.success) {
                setCachedData(response.data);
                applyData(response.data, true);
            }
        });
    }, 500);
}

function applyData(data, silent) {
    silent = silent || false;

    state.products = data.products || [];
    state.categories = data.categories || [];

    state.orders = (data.orders || []).map(function(order) {
        if (order.items && typeof order.items === 'string') {
            try {
                const parsed = JSON.parse(order.items);
                order.items = Array.isArray(parsed) ? parsed : [parsed];
            } catch (e) {
                order.items = [];
            }
        }
        if (!Array.isArray(order.items)) {
            order.items = [];
        }
        return order;
    });

    if (data.payments) state.payments = data.payments;
    state.notifications = data.notifications || [];
    state.chats = data.chats || [];
    state.users = data.users || [];
    state.recharges = data.recharges || [];
    state.flashDeals = data.flashDeals || [];
    state.bestSellers = data.bestSellers || [];
    state.newArrivals = data.newArrivals || [];
    state.featured = data.featured || [];

    if (data.user) {
        state.user = data.user;
        state.isLoggedIn = true;
        state.balance = data.user.balance || 0;
        state.isAdmin = data.user.isAdmin || false;
        state.isVerified = data.user.isVerified || false;
        try {
            localStorage.setItem('Tokmart_user', JSON.stringify({
                id: data.user.id,
                name: data.user.name,
                email: data.user.email,
                isAdmin: data.user.isAdmin,
                isVerified: data.user.isVerified,
                balance: data.user.balance,
                avatar_path: data.user.avatar_path
            }));
        } catch (e) {}
    } else if (state.isLoggedIn && state.user) {
        const updated = state.users.find(function(u) { return u.id === state.user.id; });
        if (updated) {
            state.user = updated;
            state.balance = updated.balance || 0;
            state.isAdmin = updated.isAdmin || false;
            state.isVerified = updated.isVerified || false;
        }
    }

    renderAll();

    if (!silent) {
        showToast('✅ تم تحديث البيانات');
    }
}

function renderAll() {
    renderProducts();
    renderCategories();
    renderCategoryBanners();
    renderCart();
    renderFavorites();
    renderOrders();
    renderNotifications();
    updateBalanceDisplay();
    updateNotifBadge();
    updateAccountUI();
}

// ============================================================
// PRODUCTS AND CATEGORIES
// ============================================================

function renderProducts() {}

function createProductCard(product) {
    const isFav = state.favorites.some(function(f) { return f.id === product.id; });
    const imgSrc = product.image_url || '';
    const imgHtml = imgSrc ? '<img src="' + imgSrc + '" loading="lazy" alt="' + product.name + '">' :
        '<i class="' + (product.icon || 'fa-solid fa-box') + '"></i>';
    const discountHtml = product.discount ?
        '<div class="discount-chip"><i class="fa-solid fa-tag"></i><span class="percent">' + product.discount + '</span></div>' : '';
    const priceDisplay = parseFloat(product.price).toFixed(2) + ' د.ع';
    const oldPriceDisplay = product.oldPrice ? parseFloat(product.oldPrice).toFixed(2) + ' د.ع' : '';

    return `
        <div class="product-card" data-id="${product.id}" onclick="openProductDetail(${product.id})">
            ${discountHtml}
            ${product.brand ? `<div class="brand-tag">${product.brand}</div>` : ''}
            <div class="product-image">${imgHtml}</div>
            <h4>${product.name}</h4>
            <div class="product-desc">${product.desc || ''}</div>
            <div class="price-row">
                ${oldPriceDisplay ? `<span class="old-price">${oldPriceDisplay}</span>` : ''}
                <span class="${oldPriceDisplay ? 'new-price' : 'normal-price'}">${priceDisplay}</span>
            </div>
            <button class="add-btn" data-id="${product.id}" onclick="event.stopPropagation();addToCartById(${product.id})">
                <i class="fa-solid fa-cart-plus"></i> أضف
            </button>
            <div class="favorite-icon ${isFav ? 'active' : ''}" data-id="${product.id}" onclick="event.stopPropagation();toggleFavorite(${product.id})">
                <i class="fa-solid fa-heart"></i>
            </div>
        </div>
    `;
}

function addToCartById(productId) {
    const product = state.products.find(function(p) { return p.id === productId; });
    if (product) addToCart(product);
}

function renderCategoryBanners() {
    const container = $('categoryBannersContainer');
    if (!container) return;
    const categoriesWithBanner = state.categories.filter(function(c) { return c.banner_image_url; });

    if (!categoriesWithBanner.length) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text3);">
                <i class="fa-regular fa-folder-open" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                <div style="font-size:14px;font-weight:600;">لا توجد تصنيفات مع بانر</div>
                <div style="font-size:12px;color:var(--text3);margin-top:4px;">أضف صور بانر للتصنيفات من لوحة التحكم</div>
            </div>
        `;
        return;
    }

    container.innerHTML = categoriesWithBanner.map(function(c) {
        const products = state.products.filter(function(p) { return p.category === c.id; });
        const mid = Math.ceil(products.length / 2);
        const row1 = products.slice(0, mid);
        const row2 = products.slice(mid);

        const productHtml = products.length ? `
            <div class="category-products" style="padding:8px 0 10px;">
                <div class="section-header" style="display:flex;align-items:center;justify-content:space-between;margin:6px 10px 4px;padding:0 4px;">
                    <div class="title" style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800;color:var(--text2);">
                        <span class="icon-bg" style="width:28px;height:28px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:13px;color:var(--primary);">
                            <i class="${c.icon || 'fa-solid fa-box'}"></i>
                        </span>
                        <span>منتجات ${c.name}</span>
                    </div>
                    <span class="view-all" onclick="event.stopPropagation();openCategoryProducts('${c.id}')" style="font-size:11px;font-weight:700;color:var(--text3);cursor:pointer;display:flex;align-items:center;gap:3px;touch-action:manipulation;">
                        عرض الكل <i class="fa-solid fa-chevron-left"></i>
                    </span>
                </div>
                <div class="products-two-rows" style="display:flex;flex-direction:column;gap:8px;padding:4px 10px 6px 10px;">
                    <div class="products-row" style="display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding:4px 4px 8px 4px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scroll-behavior:smooth;-webkit-user-select:none;user-select:none;cursor:grab;">
                        ${row1.map(function(p) { return createProductCard(p); }).join('')}
                    </div>
                    <div class="products-row" style="display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding:4px 4px 8px 4px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scroll-behavior:smooth;-webkit-user-select:none;user-select:none;cursor:grab;">
                        ${row2.map(function(p) { return createProductCard(p); }).join('')}
                    </div>
                </div>
            </div>
        ` : `
            <div style="padding:12px 16px;color:var(--text3);font-size:13px;text-align:center;">
                <i class="fa-regular fa-box-open" style="font-size:20px;display:block;margin-bottom:4px;opacity:0.3;"></i>
                لا توجد منتجات في هذا التصنيف
            </div>
        `;

        return `
            <div class="category-banner-section">
                <div class="category-banner" onclick="openCategoryProducts('${c.id}')">
                    <img src="${c.banner_image_url}" loading="lazy" alt="${c.name}">
                    <div class="banner-overlay">
                        <h3><i class="${c.icon || 'fa-solid fa-tag'}"></i> ${c.name}</h3>
                        <p>استكشف منتجات ${c.name}</p>
                    </div>
                </div>
                ${productHtml}
            </div>
        `;
    }).join('');
    setTimeout(initDragScroll, 100);
}

function renderCategories() {
    const grid = $('categoriesGrid');
    if (!grid) return;
    if (!state.categories.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--text3);">لا توجد تصنيفات</div>';
        return;
    }

    grid.innerHTML = state.categories.map(function(c) {
        const productCount = state.products.filter(function(p) { return p.category === c.id; }).length;
        return `
            <div class="category-grid-item" onclick="openCategoryProducts('${c.id}')">
                <div class="cat-img">
                    ${c.icon_image_url ?
                        '<img src="' + c.icon_image_url + '" loading="lazy" alt="' + c.name + '">' :
                        '<i class="' + c.icon + '"></i>'
                    }
                </div>
                <span>${c.name}</span>
                <div class="product-count">${productCount} منتج</div>
            </div>
        `;
    }).join('');
}

function openCategoryProducts(categoryId) {
    const products = state.products.filter(function(p) { return p.category === categoryId; });

    const detailPage = $('productDetailPage');
    if (detailPage) detailPage.classList.remove('open');

    openPage('categoryProductsPage');
    const cat = state.categories.find(function(c) { return c.id === categoryId; });
    const titleEl = $('catProductsTitle');
    if (titleEl) titleEl.textContent = cat ? cat.name : 'المنتجات';
    const grid = $('categoryProductsGrid');
    if (grid) {
        if (products.length) {
            grid.innerHTML = products.map(function(p) { return createProductCard(p); }).join('');
        } else {
            grid.innerHTML = `
                <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text3);">
                    <i class="fa-regular fa-box-open" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    <div style="font-size:16px;font-weight:600;">لا توجد منتجات في هذا التصنيف</div>
                    <div style="font-size:13px;margin-top:4px;">سيتم إضافة منتجات قريباً</div>
                </div>
            `;
        }
    }
}

function openProductDetail(productId) {
    const product = state.products.find(function(p) { return p.id === productId; });
    if (!product) { showToast('المنتج غير موجود'); return; }

    const openPages = document.querySelectorAll('.settings-page.open, .product-detail-page.open, .all-products-page.open, .category-products-page.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open, .auth-page.open, .chat-app-user.open');
    openPages.forEach(function(page) {
        if (page.id !== 'productDetailPage') {
            page.classList.remove('open');
        }
    });

    const detailPage = $('productDetailPage');
    if (detailPage) {
        detailPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
    }

    state.detailQty = 1;
    const titleEl = $('detailTitle');
    if (titleEl) titleEl.textContent = product.name;

    const isFav = state.favorites.some(function(f) { return f.id === product.id; });
    const stars = '★'.repeat(Math.floor(product.rating || 0)) + '☆'.repeat(5 - Math.floor(product.rating || 0));
    const imgSrc = product.image_url || '';
    const body = $('detailBody');

    if (body) {
        body.innerHTML = `
            <div class="detail-image">${imgSrc ? '<img src="' + imgSrc + '" style="width:100%;max-width:100%;height:350px;object-fit:contain;display:block;margin:0 auto;border-radius:14px;border:2px solid var(--border);padding:6px;background:var(--surface);">' : '<i class="fa-solid fa-box" style="font-size:60px;color:var(--primary);"></i>'}</div>
            <div class="detail-name" style="font-size:1.25rem;font-weight:700;margin:12px 0 6px;color:var(--text);">${product.name}</div>
            <div class="detail-desc" style="color:var(--text2);font-size:0.9rem;line-height:1.5;margin-bottom:12px;">${product.desc || ''}</div>
            ${product.discount ? `<div style="display:inline-flex;align-items:center;gap:6px;background:var(--discount-color);color:#fff;padding:4px 14px 4px 10px;border-radius:30px;font-weight:700;font-size:.8rem;box-shadow:0 4px 12px rgba(255,155,61,.4);margin-bottom:10px;"><i class="fa-solid fa-tag"></i>${product.discount} خصم</div>` : ''}
            <div class="detail-price-row" style="margin-bottom:12px;">
                  ${product.oldPrice ? '<span class="detail-old-price" style="text-decoration:line-through;color:var(--text3);margin-left:8px;font-size:0.95rem;">' + parseFloat(product.oldPrice).toFixed(2) + ' د.ع</span>' : ''}
                  <span class="${product.oldPrice ? 'detail-new-price' : 'detail-normal-price'}" style="font-size:1.2rem;font-weight:800;color:var(--primary);">${parseFloat(product.price).toFixed(2)} د.ع</span>
            </div>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin:8px 0 14px;display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-truck-fast" style="color:var(--green);font-size:16px;"></i>
                <div style="font-size:13px;color:var(--text2);">🚚 <b>توصيل سريع:</b> ${product.deliveryTime || 'خلال 24 ساعة'}</div>
            </div>
            <div class="detail-qty" style="display:flex;align-items:center;justify-content:space-between;background:var(--surface);border:1px solid var(--border);padding:10px 14px;border-radius:12px;margin-bottom:14px;">
                <label style="font-size:13px;font-weight:700;color:var(--text2);">الكمية المطلوبة:</label>
                <div class="qty-control" style="display:flex;align-items:center;gap:10px;">
                    <button onclick="updateDetailQty(-1)" style="width:34px;height:34px;border-radius:50%;border:2px solid var(--border);background:var(--surface);font-size:18px;cursor:pointer;display:grid;place-items:center;font-weight:bold;color:var(--text);">−</button>
                    <span class="qty-value" id="detailQtyValue" style="font-size:16px;font-weight:700;min-width:28px;text-align:center;">1</span>
                    <button onclick="updateDetailQty(1)" style="width:34px;height:34px;border-radius:50%;border:2px solid var(--border);background:var(--surface);font-size:18px;cursor:pointer;display:grid;place-items:center;font-weight:bold;color:var(--text);">+</button>
                </div>
          </div>
          <div class="detail-actions" style="display:flex;gap:10px;margin-bottom:12px;">
              <button class="btn btn-lg btn-primary" style="flex:1;" onclick="addDetailToCart(${product.id})"><i class="fa-solid fa-cart-plus"></i> أضف للسلة</button>
              <button class="btn-fav-detail ${isFav ? 'active' : ''}" data-id="${product.id}" onclick="toggleFavorite(${product.id})" style="width:50px;height:50px;border-radius:14px;border:2px solid var(--border);background:var(--surface);cursor:pointer;display:grid;place-items:center;font-size:18px;color:${isFav ? '#e74c3c' : 'var(--text2)'};"><i class="fa-solid fa-heart"></i></button>
          </div>

            <div class="detail-rating" style="background:var(--surface);border:1px solid var(--border);padding:10px 12px;border-radius:10px;display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <div class="stars" style="color:#f39c12;font-size:13px;">${stars}</div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="text" style="font-size:12px;font-weight:700;color:var(--text2);">${product.rating || 0} / 5 (${product.ratingCount || 0})</div>
                    <button onclick="toggleReviewModal(true)" style="background:var(--surface);border:1px solid var(--primary);color:var(--primary);padding:5px 10px;border-radius:6px;font-weight:700;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:4px;">
                        <i class="fa-solid fa-pen" style="font-size:10px;"></i> تقييم
                    </button>
                </div>
          </div>

            <div id="reviewModalOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px;">
                <div style="background:var(--surface);border:1px solid var(--border);width:100%;max-width:320px;padding:20px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.2);text-align:center;position:relative;">
                    <div id="reviewFormContent">
                        <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:12px;">تقييم المنتج</div>
                        <div id="interactive-star-picker" style="display:flex;gap:8px;font-size:24px;color:#f39c12;cursor:pointer;margin-bottom:14px;justify-content:center;">
                            <i class="fa-solid fa-star" onclick="setRatingStar(1)" data-value="1"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(2)" data-value="2"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(3)" data-value="3"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(4)" data-value="4"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(5)" data-value="5"></i>
                        </div>
                        <input type="hidden" id="selectedRatingValue" value="5">
                        <textarea id="userReviewText" placeholder="اكتب تعليقك باختصار..." style="width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:12px;color:var(--text);resize:none;height:70px;margin-bottom:12px;"></textarea>
                        <div style="display:flex;gap:8px;">
                            <button onclick="submitProductReview(${product.id})" style="flex:1;background:var(--primary);color:#fff;border:none;padding:9px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">إرسال</button>
                            <button onclick="toggleReviewModal(false)" style="background:var(--bg2);border:1px solid var(--border);color:var(--text2);padding:9px 14px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">إلغاء</button>
                        </div>
                    </div>
                    <div id="reviewSuccessContent" style="display:none;padding:20px 0;">
                        <div style="width:50px;height:50px;background:#2ecc71;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 12px;box-shadow:0 4px 12px rgba(46,204,113,0.3);">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div style="font-size:16px;font-weight:700;color:var(--text);">شكراً على تقيمك!</div>
                    </div>
                </div>
            </div>

          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
              <button onclick="closeProductDetail()" style="width:100%;padding:10px;border:2px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text2);font-weight:700;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;gap:6px;">
                  <i class="fa-solid fa-arrow-right"></i> العودة
              </button>
          </div>
        `;
    }
}

function updateDetailQty(delta) {
    state.detailQty = Math.max(1, state.detailQty + delta);
    const el = $('detailQtyValue');
    if (el) el.textContent = state.detailQty;
    if (navigator.vibrate) navigator.vibrate(5);
}

function addDetailToCart(productId) {
    const product = state.products.find(function(p) { return p.id === productId; });
    if (!product) return;
    for (let i = 0; i < state.detailQty; i++) {
        addToCart(product);
    }
    closePage('productDetailPage');
}

function closeProductDetail() {
    const detailPage = $('productDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

function toggleReviewModal(show) {
    const modal = document.getElementById('reviewModalOverlay');
    if (modal) {
        modal.style.display = show ? 'flex' : 'none';
        if (show) {
            document.getElementById('reviewFormContent').style.display = 'block';
            document.getElementById('reviewSuccessContent').style.display = 'none';
            document.getElementById('userReviewText').value = '';
            setRatingStar(5);
        }
    }
}

function setRatingStar(val) {
    document.getElementById('selectedRatingValue').value = val;
    const stars = document.querySelectorAll('#interactive-star-picker i');
    stars.forEach(function(star, index) {
        star.style.color = index < val ? '#f39c12' : '#ddd';
    });
}

function submitProductReview(productId) {
    document.getElementById('reviewFormContent').style.display = 'none';
    document.getElementById('reviewSuccessContent').style.display = 'block';
    setTimeout(function() {
        toggleReviewModal(false);
    }, 1500);
}

// ============================================================
// FAVORITES, ORDERS
// ============================================================

function openFavorites() {
    if (!requireLogin()) return;

    const detailPage = $('productDetailPage');
    if (detailPage) detailPage.classList.remove('open');

    openPage('favoritesPage');
    renderFavorites();
}

function renderFavorites() {
    const grid = $('favoritesGrid');
    if (!grid) return;

    if (!state.isLoggedIn) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text3);">
                <i class="fa-regular fa-heart" style="font-size:56px;display:block;margin-bottom:16px;color:var(--pink);opacity:0.4;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('login_to_view')} ${t('favorites')}</div>
                <div style="font-size:13px;margin-top:4px;">${t('favorites_empty')}</div>
                <button onclick="openLoginPage()" class="btn btn-lg btn-primary" style="margin-top:16px;">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> ${t('login')}
                </button>
            </div>
        `;
        return;
    }

    if (state.favorites.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:80px 20px;color:var(--text3);">
                <i class="fa-regular fa-heart" style="font-size:56px;display:block;margin-bottom:16px;color:var(--pink);opacity:0.2;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('no_favorites')}</div>
                <div style="font-size:13px;margin-top:4px;">${t('favorites_empty')}</div>
            </div>
        `;
        return;
    }

    grid.innerHTML = state.favorites.map(function(p) {
        return createProductCard(p);
    }).join('');
}

function toggleFavorite(productId) {
    if (!state.isLoggedIn) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }
    const product = state.products.find(function(p) { return p.id === productId; });
    if (!product) return;
    const index = state.favorites.findIndex(function(f) { return f.id === productId; });
    if (index > -1) {
        state.favorites.splice(index, 1);
        showToast('تمت الإزالة من المفضلة');
    } else {
        state.favorites.push(product);
        showToast('❤️ تمت الإضافة للمفضلة');
        if (navigator.vibrate) navigator.vibrate(10);
    }
    localStorage.setItem('favorites', JSON.stringify(state.favorites));
    renderFavorites();
}

function loadFavorites() {
    try {
        const saved = localStorage.getItem('favorites');
        if (saved) state.favorites = JSON.parse(saved);
    } catch (e) { state.favorites = []; }
}

function openOrders() {
    if (!requireLogin()) return;

    const detailPage = $('orderDetailPage');
    if (detailPage) detailPage.classList.remove('open');

    openPage('ordersPage');
    renderOrders();
}

function renderOrders() {
    const container = $('ordersList');
    if (!container) return;

    if (!state.isLoggedIn) {
        container.innerHTML = `
            <div style="text-align:center;padding:60px 20px;color:var(--text3);">
                <i class="fa-regular fa-circle-user" style="font-size:56px;display:block;margin-bottom:16px;opacity:0.3;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('login_to_view')} ${t('orders')}</div>
                <div style="font-size:13px;margin-top:4px;">ستظهر طلباتك هنا بعد الشراء</div>
                <button onclick="openLoginPage()" class="btn btn-lg btn-primary" style="margin-top:16px;">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> ${t('login')}
                </button>
            </div>
        `;
        return;
    }

    const userOrders = state.orders.filter(function(o) {
        return o.userId == state.user.id;
    });

    if (userOrders.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:80px 20px;color:var(--text3);">
                <i class="fa-regular fa-receipt" style="font-size:56px;display:block;margin-bottom:16px;opacity:0.3;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('no_orders')}</div>
                <div style="font-size:13px;margin-top:4px;">قم بشراء منتجات لتظهر طلباتك هنا</div>
                <button onclick="switchPage('page-home')" class="btn btn-lg btn-primary" style="margin-top:16px;">
                    <i class="fa-solid fa-bag-shopping"></i> ${t('start_shopping')}
                </button>
            </div>
        `;
        return;
    }

    const sortedOrders = [...userOrders].sort(function(a, b) {
        return new Date(b.createdAt) - new Date(a.createdAt);
    });

    container.innerHTML = sortedOrders.map(function(order) {
        const statusClass = ['pending', 'shipped', 'completed', 'cancelled', 'approved', 'rejected'].includes(order.status) ? order.status : 'pending';
        const statusText = {
            pending: '⏳ قيد المعالجة',
            shipped: '🚚 قيد التوصيل',
            completed: '✅ مكتمل',
            cancelled: '❌ ملغي',
            approved: '✅ تم الموافقة',
            rejected: '❌ مرفوض'
        }[order.status] || '⏳ قيد المعالجة';

        let items = [];
        try {
            items = order.items ? (typeof order.items === 'string' ? JSON.parse(order.items) : order.items) : [];
        } catch (e) {
            items = [];
        }

        const bgColor = statusClass === 'completed' || statusClass === 'approved' ? 'rgba(46,204,113,.15)' :
                       statusClass === 'pending' ? 'rgba(216,176,122,.15)' :
                       statusClass === 'shipped' ? 'rgba(5,134,147,.15)' : 'rgba(231,76,60,.15)';
        const textColor = statusClass === 'completed' || statusClass === 'approved' ? 'var(--green)' :
                         statusClass === 'pending' ? 'var(--secondary)' :
                         statusClass === 'shipped' ? 'var(--primary)' : 'var(--red)';

        return `
            <div class="order-item" onclick="openOrderDetail('${order.id}')"
                 style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px;cursor:pointer;box-shadow:var(--shadow);">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:150px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-weight:700;font-size:15px;color:var(--text2);">${order.orderId || order.id}</span>
                            <span style="font-size:12px;color:var(--text3);">📅 ${order.date || new Date(order.createdAt).toLocaleDateString('ar-EG')}</span>
                        </div>
                        <div style="font-size:13px;color:var(--text3);margin-top:3px;">
                            <i class="fa-solid fa-box"></i> ${items.length} منتج • 💰 ${parseFloat(order.total).toFixed(2)} د.ع
                        </div>
                        ${order.transferImage ? '<div style="font-size:11px;color:var(--primary);margin-top:2px;">🖼️ مع صورة تحويل</div>' : ''}
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="order-status ${statusClass}" style="padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;background:${bgColor};color:${textColor};">
                            ${statusText}
                        </span>
                        <i class="fa-solid fa-chevron-left" style="color:var(--text3);font-size:16px;"></i>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function openOrderDetail(orderId) {
    if (!state.isLoggedIn) {
        showToast('يرجى تسجيل الدخول');
        return;
    }

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

    document.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .chat-app-user.open, .favorites-page.open, .orders-page.open, .recharge-page.open')
        .forEach(function(page) {
            if (page.id !== 'orderDetailPage') {
                page.classList.remove('open');
            }
        });

    const detailPage = $('orderDetailPage');
    if (detailPage) {
        detailPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
    }

    const titleEl = $('orderDetailTitle');
    if (titleEl) {
        titleEl.textContent = '📋 تفاصيل الطلب - ' + (order.orderId || '#' + order.id);
    }

    const statusLabels = {
        pending: '⏳ قيد المعالجة', shipped: '🚚 قيد التوصيل', completed: '✅ مكتمل',
        cancelled: '❌ ملغي', approved: '✅ تم الموافقة', rejected: '❌ مرفوض'
    };
    const statusClass = ['pending', 'shipped', 'completed', 'cancelled', 'approved', 'rejected'].includes(order.status) ? order.status : 'pending';
    const statusColors = {
        pending: { bg: '#fff3cd', text: '#856404' }, shipped: { bg: '#cce5ff', text: '#004085' },
        completed: { bg: '#d4edda', text: '#155724' }, cancelled: { bg: '#f8d7da', text: '#721c24' },
        approved: { bg: '#d4edda', text: '#155724' }, rejected: { bg: '#f8d7da', text: '#721c24' }
    };
    const currentThemeColor = statusColors[statusClass] || { bg: '#e2e3e5', text: '#383d41' };

    let items = [];
    try {
        let rawItems = order.items || order.cart || order.products;
        if (rawItems) {
            if (typeof rawItems === 'string') {
                try { items = JSON.parse(rawItems); } catch (parseErr) { items = []; }
            } else if (Array.isArray(rawItems)) {
                items = rawItems;
            } else if (typeof rawItems === 'object' && rawItems !== null) {
                items = Object.values(rawItems);
            }
        }
    } catch (e) { items = []; }
    if (!Array.isArray(items)) items = [];

    let transferHtml = '';
    if (order.transferImage) {
        const imgUrl = order.transferImage_url || order.transferImage;
        transferHtml = `
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <i class="fa-solid fa-image" style="color:var(--primary);"></i>
                <span style="font-weight:700;font-size:14px;">🖼️ صورة التحويل</span>
            </div>
            <img src="${imgUrl}" onclick="openLightbox('${imgUrl}')"
                 style="max-width:100%;border-radius:8px;cursor:pointer;max-height:300px;object-fit:contain;border:1px solid var(--border);">
            ${order.transferAmount ? `<div style="margin-top:8px;font-size:13px;color:var(--text3);">💰 المبلغ المحول: <strong>${parseFloat(order.transferAmount || 0).toFixed(2)} د.ع</strong></div>` : ''}
            ${order.accountNumber ? `<div style="font-size:13px;color:var(--text3);">🏦 رقم الحساب: <strong>${order.accountNumber}</strong></div>` : ''}
            ${order.beneficiary ? `<div style="font-size:13px;color:var(--text3);">👤 المستفيد: <strong>${order.beneficiary}</strong></div>` : ''}
        </div>
        `;
    }

    const body = $('orderDetailBody');
    if (!body) return;

    body.innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow);">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));gap:12px;">
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">📌 الحالة</div>
                <span class="order-status-badge ${statusClass}" style="display:inline-block;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;background:${currentThemeColor.bg};color:${currentThemeColor.text};margin-top:4px;">
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
                const productInState = state.products.find(function(p) { return p.id == item.id; });
                if (productInState) imgSrc = productInState.image_url || '';
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
                <div style="font-weight:800;font-size:14px;color:var(--primary);white-space:nowrap;">
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
        <div style="display:flex;justify-content:space-between;padding:12px 0 4px;border-top:2px solid var(--primary);margin-top:8px;">
            <span style="font-weight:700;font-size:15px;color:var(--text2);">المجموع الكلي</span>
            <span style="font-size:18px;font-weight:900;color:var(--primary);">${parseFloat(order.total || 0).toFixed(2)} د.ع</span>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px;padding-bottom:20px;">
        <button onclick="openOrderSupportChat('${order.orderId || order.id}')" class="btn btn-lg btn-primary">
            <i class="fa-solid fa-headset"></i> التواصل مع الدعم
        </button>
        <button onclick="closeOrderDetail()" class="btn btn-lg btn-outline">
            <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
        </button>
    </div>
    `;
}

function closeOrderDetail() {
    const detailPage = document.getElementById('orderDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

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
