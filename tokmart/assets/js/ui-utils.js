/* ============================================================
   Tokmart - Geolocation, search, countdown, drag-scroll, page navigation, lightbox
   ============================================================ */
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
            logoEl.style.width = '50px';
            logoEl.style.height = '50px';
            logoEl.style.borderRadius = '50%';
            logoEl.style.objectFit = 'cover';
        }
    } else if (logoEl) {
        logoEl.style.display = 'flex';
        logoEl.innerHTML = '<i class="fa-solid fa-store" style="font-size:24px;color:#fff;"></i>';
    }

    const titleEl = document.querySelector('title');
    if (titleEl) {
        titleEl.textContent = site.name || 'Tokmart';
    }
}

function getCurrentLocation() {
    const statusEl = document.getElementById('locationStatus');
    const btnText = document.querySelector('#getLocationBtn span');
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
    if (btnText) btnText.textContent = 'جاري التحديد...';
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            console.log('📍 الموقع:', lat, lng);
            getAddressFromCoords(lat, lng);
        },
        function(error) {
            console.warn('⚠️ خطأ في تحديد الموقع:', error);
            let errorMsg = '';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    errorMsg = '❌ تم رفض صلاحية الوصول إلى الموقع';
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMsg = '❌ معلومات الموقع غير متوفرة';
                    break;
                case error.TIMEOUT:
                    errorMsg = '⏰ انتهت مهلة تحديد الموقع';
                    break;
                default:
                    errorMsg = '❌ حدث خطأ في تحديد الموقع';
            }
            
            if (statusEl) {
                statusEl.style.display = 'block';
                statusEl.innerHTML = errorMsg;
                statusEl.style.color = 'var(--red)';
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 4000);
            }
            if (btnText) btnText.textContent = 'تحديد موقعي';
            showToast(errorMsg);
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 60000
        }
    );
}

function getAddressFromCoords(lat, lng) {
    const statusEl = document.getElementById('locationStatus');
    const btnText = document.querySelector('#getLocationBtn span');
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
            console.log('📍 بيانات العنوان:', data);
            
            if (data && data.display_name) {
                const address = data.display_name;
                if (addressInput) {
                    addressInput.value = address;
                    addressInput.style.borderColor = 'var(--green)';
                }
                
                if (statusEl) {
                    statusEl.innerHTML = '✅ تم تحديد الموقع بنجاح';
                    statusEl.style.color = 'var(--green)';
                    setTimeout(function() {
                        statusEl.style.display = 'none';
                        addressInput.style.borderColor = 'var(--border)';
                    }, 3000);
                }
                
                showToast('✅ تم تحديد موقعك بنجاح');
            } else {
                throw new Error('لا يوجد عنوان لهذا الموقع');
            }
            
            if (btnText) btnText.textContent = 'تحديد موقعي';
        })
        .catch(function(error) {
            console.warn('⚠️ خطأ في تحويل الموقع:', error);
            
            const coordsAddress = `📍 ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            if (addressInput) {
                addressInput.value = coordsAddress;
            }
            
            if (statusEl) {
                statusEl.innerHTML = '✅ تم تحديد الإحداثيات';
                statusEl.style.color = 'var(--green)';
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 3000);
            }
            
            if (btnText) btnText.textContent = 'تحديد موقعي';
            showToast('✅ تم تحديد الإحداثيات');
        });
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
    
    if (minPriceInput) {
        minPriceInput.addEventListener('input', performCategorySearch);
    }
    
    if (maxPriceInput) {
        maxPriceInput.addEventListener('input', performCategorySearch);
    }
}

// ============================================================
// UPDATE COUNTDOWN, DRAG SCROLL
// ============================================================

function updateCountdown() {
    const target = new Date();
    target.setHours(target.getHours() + 2);
    const dist = target.getTime() - Date.now();
    if (dist < 0) {
        ['cdHours', 'cdMinutes', 'cdSeconds'].forEach(function(id) {
            const el = $(id);
            if (el) el.textContent = '00';
        });
        return;
    }
    const hoursEl = $('cdHours');
    const minutesEl = $('cdMinutes');
    const secondsEl = $('cdSeconds');
    if (hoursEl) hoursEl.textContent = String(Math.floor(dist / (1000 * 60 * 60))).padStart(2, '0');
    if (minutesEl) minutesEl.textContent = String(Math.floor((dist % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
    if (secondsEl) secondsEl.textContent = String(Math.floor((dist % (1000 * 60)) / 1000)).padStart(2, '0');
}

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
// NAVIGATION FUNCTIONS
// ============================================================

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
        'rechargePage', 'adminPage', 'orderDetailPage', 'page-notifications',
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

