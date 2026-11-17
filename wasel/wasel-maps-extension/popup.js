chrome.storage.local.get('site').then(({ site }) => { if (site) document.getElementById('site').textContent = 'مربوطة بـ: ' + site; });
document.getElementById('reset').onclick = async () => { await chrome.storage.local.remove('site'); document.getElementById('site').textContent = 'تم فك الربط - أول موقع يستخدمها ينربط تلقائياً'; };
