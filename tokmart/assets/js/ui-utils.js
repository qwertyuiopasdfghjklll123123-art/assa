/* ============================================================
   Tokmart - Navigation, search, lightbox, drag-scroll, site settings sync
   ============================================================ */

function switchPage(pageId) {
    $$('.page').forEach(function(p) { p.classList.remove('active'); });
    const target = $(pageId);
    if (target) target.classList.add('active');

    $$('.nav-item').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.page === pageId);
    });

    state.currentPage = pageId;
    updateBottomNavVisibility();
    updateCartBadge();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (pageId === 'page-cart') renderCheckout();
}

function updateBottomNavVisibility() {
    const bottomNav = $('bottomNav');
    const hiddenPages = ['favoritesPage', 'ordersPage', 'loginPage', 'registerPage',
        'productDetailPage', 'allProductsPage', 'categoryProductsPage',
        'rechargePage', 'orderDetailPage', 'page-notifications',
        'page-chat-support', 'page-account-settings', 'chatPageUser', 'otpVerificationPage',
        'forgotPasswordPage'
    ];
    const isHidden = hiddenPages.some(function(id) {
        const el = $(id);
        return el && (el.classList.contains('open') || el.style.display === 'flex');
    }) || !!document.querySelector('.settings-page.open');

    if (bottomNav) {
        bottomNav.classList.toggle('hidden', isHidden);
    }
}

function openPage(pageId) {
    const page = $(pageId);
    if (!page) return;

    const siblings = page.parentElement.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .chat-app-user.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open');
    siblings.forEach(function(sibling) {
        if (sibling !== page) {
            sibling.classList.remove('open');
        }
    });

    page.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
    void page.offsetWidth;
}

function closePage(pageId) {
    const page = $(pageId);
    if (!page) return;
    page.classList.remove('open');
    document.body.style.overflow = '';
    updateBottomNavVisibility();
}

function closeSettingsPage(id) { closePage(id); }
function closeChatPage() { closePage('page-chat-support'); }
function closeCategoryProducts() { closePage('categoryProductsPage'); }
function closeAllProducts() { closePage('allProductsPage'); }
function closeRecharge() { closePage('rechargePage'); }
function closeFavorites() { closePage('favoritesPage'); }
function closeOrders() { closePage('ordersPage'); }

// ============================================================
// LIGHTBOX
// ============================================================

let currentLightboxImage = '';

function openLightbox(src) {
    const lightbox = $('lightbox');
    const img = $('lightboxImage');
    if (!lightbox || !img) return;
    currentLightboxImage = src;
    img.src = src;
    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    const lightbox = $('lightbox');
    if (!lightbox) return;
    lightbox.classList.remove('active');
    document.body.style.overflow = '';
}

function downloadLightboxImage() {
    if (!currentLightboxImage) return;
    const link = document.createElement('a');
    link.href = currentLightboxImage;
    link.download = currentLightboxImage.split('/').pop() || 'image.jpg';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ============================================================
// SITE SETTINGS
// ============================================================

function loadSiteData() {
    callAPI('getSiteData', null, 'GET').then(function(response) {
        if (response.success) {
            updateSiteUI(response.data.site || {});
        }
    });
}

function updateSiteUI(site) {
    const logo = site.logo_url || '';
    const logoEl = $('siteLogo');
    if (logo) {
        if (logoEl) {
            logoEl.src = logo;
            logoEl.style.display = 'block';
        }
    } else if (logoEl) {
        logoEl.style.display = 'none';
    }
}

// ============================================================
// SEARCH
// ============================================================

function performCategorySearch() {
    const searchTerm = $('categorySearchInput') ? $('categorySearchInput').value.trim().toLowerCase() : '';
    const minPrice = parseFloat($('minPriceInput') ? $('minPriceInput').value : 0) || 0;
    const maxPrice = parseFloat($('maxPriceInput') ? $('maxPriceInput').value : 0) || 0;

    const grid = $('categoriesGrid');
    if (!grid) return;

    if (!searchTerm && !minPrice && !maxPrice) {
        renderCategories();
        return;
    }

    let matchedCategories = state.categories.filter(function(c) {
        const nameMatch = c.name.toLowerCase().includes(searchTerm) ||
                         c.nameEn.toLowerCase().includes(searchTerm);

        const categoryProducts = state.products.filter(function(p) {
            return p.category === c.id;
        });

        const productNameMatch = categoryProducts.some(function(p) {
            return p.name.toLowerCase().includes(searchTerm) ||
                   (p.nameEn && p.nameEn.toLowerCase().includes(searchTerm));
        });

        let priceMatch = true;
        if (minPrice > 0 || maxPrice > 0) {
            priceMatch = categoryProducts.some(function(p) {
                const price = p.price || 0;
                let match = true;
                if (minPrice > 0 && price < minPrice) match = false;
                if (maxPrice > 0 && price > maxPrice) match = false;
                return match;
            });
        }

        return (nameMatch || productNameMatch) && priceMatch;
    });

    if (matchedCategories.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text3);">
                <i class="fa-regular fa-search" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                <div style="font-size:16px;font-weight:600;">لا توجد نتائج</div>
                <div style="font-size:13px;margin-top:4px;">جرب كلمات بحث مختلفة أو عدل نطاق السعر</div>
            </div>
        `;
        return;
    }

    grid.innerHTML = matchedCategories.map(function(c) {
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
                ${searchTerm ? '<div style="font-size:10px;color:var(--primary);margin-top:2px;">🔍 مطابق للبحث</div>' : ''}
            </div>
        `;
    }).join('');
}

function setupSearch() {
    const catSearch = $('categorySearchInput');
    const minPriceInput = $('minPriceInput');
    const maxPriceInput = $('maxPriceInput');
    const clearBtn = $('clearSearchBtn');

    if (catSearch) {
        catSearch.addEventListener('input', function() {
            performCategorySearch();
            if (clearBtn) {
                clearBtn.style.display = this.value.trim() ? 'block' : 'none';
            }
        });
    }

    if (minPriceInput) minPriceInput.addEventListener('input', performCategorySearch);
    if (maxPriceInput) maxPriceInput.addEventListener('input', performCategorySearch);
}

// ============================================================
// DRAG SCROLL
// ============================================================

function initDragScroll() {
    const scrollContainers = document.querySelectorAll('.products-row');
    scrollContainers.forEach(function(container) {
        let isDown = false;
        let startX;
        let scrollLeft;
        container.addEventListener('mousedown', function(e) {
            isDown = true;
            container.style.cursor = 'grabbing';
            startX = e.pageX - container.offsetLeft;
            scrollLeft = container.scrollLeft;
        });
        container.addEventListener('mouseleave', function() {
            isDown = false;
            container.style.cursor = 'grab';
        });
        container.addEventListener('mouseup', function() {
            isDown = false;
            container.style.cursor = 'grab';
        });
        container.addEventListener('mousemove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - container.offsetLeft;
            const walk = (x - startX) * 2;
            container.scrollLeft = scrollLeft - walk;
        });
        let touchStartX = 0;
        let touchScrollLeft = 0;
        container.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].pageX;
            touchScrollLeft = container.scrollLeft;
        }, { passive: true });
        container.addEventListener('touchmove', function(e) {
            const touchX = e.touches[0].pageX;
            const diff = (touchStartX - touchX);
            container.scrollLeft = touchScrollLeft + diff;
        }, { passive: true });
    });
}

// ============================================================
// NOTIFICATION PERMISSION
// ============================================================

function requestNotificationPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

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
// HOME DISCOUNT COUNTDOWN
// ============================================================

document.addEventListener('DOMContentLoaded', function () {
    const TWO_HOURS = 2 * 60 * 60 * 1000;
    let endTime = localStorage.getItem('tokmart_discount_end');
    let now = new Date().getTime();

    if (!endTime || now >= parseInt(endTime)) {
        endTime = now + TWO_HOURS;
        localStorage.setItem('tokmart_discount_end', endTime);
    }

    setInterval(function () {
        let currentTime = new Date().getTime();
        let distance = parseInt(endTime) - currentTime;

        if (distance <= 0) {
            endTime = new Date().getTime() + TWO_HOURS;
            localStorage.setItem('tokmart_discount_end', endTime);
            distance = TWO_HOURS;
        }

        let hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        let minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        let seconds = Math.floor((distance % (1000 * 60)) / 1000);

        const format = function(num) { return String(num).padStart(2, '0'); };

        const hoursEl = document.querySelector('.t-hours');
        const minutesEl = document.querySelector('.t-minutes');
        const secondsEl = document.querySelector('.t-seconds');

        if (hoursEl) hoursEl.textContent = format(hours);
        if (minutesEl) minutesEl.textContent = format(minutes);
        if (secondsEl) secondsEl.textContent = format(seconds);
    }, 1000);
});
