// جسر بين صفحة أدمن واصل والإضافة. يشتغل بس بصفحات واصل (فيها علامة wasel-ext-bridge)
(function () {
  if (!document.querySelector('meta[name="wasel-ext-bridge"]')) return;
  document.documentElement.setAttribute('data-wasel-ext', chrome.runtime.getManifest().version);
  const origin = location.origin;
  window.addEventListener('message', (e) => {
    if (e.source !== window || !e.data || e.data.source !== 'wasel-page') return;
    chrome.runtime.sendMessage({ ...e.data, origin }, (resp) => {
      if (chrome.runtime.lastError) resp = { ok: false, error: chrome.runtime.lastError.message };
      if (e.data.reqId) window.postMessage({ source: 'wasel-ext', type: 'reply', reqId: e.data.reqId, resp }, origin);
    });
  });
  chrome.runtime.onMessage.addListener((msg) => {
    if (msg && msg.source === 'wasel-ext') window.postMessage(msg, origin);
  });
  window.postMessage({ source: 'wasel-ext', type: 'ready', version: chrome.runtime.getManifest().version }, origin);
})();
