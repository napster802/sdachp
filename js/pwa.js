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
    // Retry any Bible version that didn't finish downloading while we were
    // offline (or on a previous visit) - a device that missed the initial
    // download gets another automatic shot at it here.
    if (typeof OfflineBible !== 'undefined') OfflineBible.preloadAll();
    if (typeof BibleReader !== 'undefined' && BibleReader.refreshOfflineStatus) BibleReader.refreshOfflineStatus();
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
    // Download both Bible versions in the background so they're ready the
    // moment the Bible tab opens, online or not - the visible status row
    // on the Bible screen (js/bible_reader.js) shows/retries anything that
    // doesn't finish.
    if (typeof OfflineBible !== 'undefined') OfflineBible.preloadAll();
  }

  return { init, updateBadge };
})();

document.addEventListener('DOMContentLoaded', PWA.init);
