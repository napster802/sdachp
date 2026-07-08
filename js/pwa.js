/* ============================================================
   Bible Challenge Arena - PWA bootstrap
   Registers the service worker and shows a small persistent
   "Offline" badge plus toast notifications on connectivity
   changes. Also triggers the offline reading-rewards queue flush
   (OfflineQueue.flush) as soon as the connection comes back.
   ============================================================ */
const PWA = (function () {

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('sw.js').catch(err => {
        console.warn('Service worker registration failed:', err);
      });
    });
  }

  function updateBadge() {
    const badge = document.getElementById('offline-badge');
    if (!badge) return;
    badge.style.display = navigator.onLine ? 'none' : 'flex';
  }

  function onOnline() {
    updateBadge();
    App.showToast('✅ Back online - syncing…', 'success', 2000);
    if (typeof OfflineQueue !== 'undefined') OfflineQueue.flush();
  }

  function onOffline() {
    updateBadge();
    App.showToast('📴 You\'re offline - Solo Practice and Bible reading still work.', 'info', 3500);
  }

  function init() {
    registerServiceWorker();
    updateBadge();
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    // Attempt a flush on load too, in case the queue has leftovers from a
    // previous offline session that closed before ever coming back online.
    if (navigator.onLine && typeof OfflineQueue !== 'undefined') OfflineQueue.flush();
    // Parse both Bible versions in the background so they're instantly
    // ready the moment the Bible tab opens, online or not.
    if (typeof OfflineBible !== 'undefined') OfflineBible.preload();
  }

  return { init, updateBadge };
})();

document.addEventListener('DOMContentLoaded', PWA.init);
