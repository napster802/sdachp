/* ============================================================
   Bible Challenge Arena - Offline reading-rewards queue
   A minimal IndexedDB-backed queue: when a reading heartbeat
   (js/bible_reader.js) can't reach the server, its raw signal
   (events_count/scroll_delta) is stored here with a client
   timestamp instead of being discarded. Once back online, flush()
   replays the whole queue through api/sync_offline_reading.php,
   which re-validates every interval server-side using the same
   rules as the live heartbeat endpoint (see that file's PHP for
   the actual anti-cheat logic - this is just local storage).
   ============================================================ */
const OfflineQueue = (function () {
  const DB_NAME = 'bca_offline';
  const STORE_NAME = 'reading_events';
  const DB_VERSION = 1;

  let dbPromise = null;

  function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) { reject(new Error('IndexedDB not supported')); return; }
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return dbPromise;
  }

  async function addEvent(eventsCount, scrollDelta) {
    try {
      const db = await openDb();
      await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).add({
          client_ts: Date.now(),
          events_count: eventsCount,
          scroll_delta: scrollDelta,
        });
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
      });
    } catch (e) {
      // IndexedDB unavailable/blocked (private browsing, quota, etc) - the
      // interval is simply lost, same as it would have been before this
      // feature existed.
    }
  }

  async function getAll() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly');
      const req = tx.objectStore(STORE_NAME).getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  }

  async function clearAll() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      tx.objectStore(STORE_NAME).clear();
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
  }

  function newBatchId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'batch-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }

  let flushing = false;
  async function flush() {
    if (flushing || !navigator.onLine) return;
    flushing = true;
    try {
      const events = await getAll();
      if (!events.length) return;
      if (typeof Profile === 'undefined') return;
      const deviceId = Profile.getDeviceId();
      if (!deviceId) return;

      const payload = events.map(e => ({
        client_ts: e.client_ts,
        events_count: e.events_count,
        scroll_delta: e.scroll_delta,
      }));

      const res = await fetch('api/sync_offline_reading.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id: deviceId, batch_id: newBatchId(), events: payload }),
      });
      const data = await res.json();
      if (!data.success) return; // leave the queue in place, retry next flush()

      await clearAll();
      if (data.credited_points > 0 && typeof App !== 'undefined') {
        App.showToast(`📖 +${data.credited_points} points synced from offline reading!`, 'success', 3000);
      }
      if (data.skipped_ms > 0) {
        console.info(`Offline reading sync: ${Math.round(data.skipped_ms / 1000)}s of queued activity didn't fit the plausible window and were skipped.`);
      }
      if (data.wallet != null && typeof Profile !== 'undefined' && Profile.setWalletCache) {
        Profile.setWalletCache(data.wallet);
      }
    } catch (e) {
      // Still offline, or a transient error - the next 'online' event retries.
    } finally {
      flushing = false;
    }
  }

  return { addEvent, flush };
})();
