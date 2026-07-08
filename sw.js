/* ============================================================
   Bible Challenge Arena - Service Worker
   Precaches the app shell (all js/css assets index.html loads),
   so the app opens with zero connection. Never intercepts /api/
   traffic - this is a real-time multiplayer app, and a stale
   cached API response would be actively wrong, not just
   unhelpful.

   The two full Bible text files are intentionally NOT part of the
   atomic shell install below - cache.addAll() is all-or-nothing,
   and bundling two ~4MB fetches in with 31 small JS/CSS files
   meant a single flaky mobile-network hiccup on either large file
   could silently fail the ENTIRE install, shell included. They're
   downloaded separately and best-effort here (a nice-to-have head
   start) - js/offline_bible.js's download() is the real, retryable,
   user-visible path that actually guarantees they end up cached
   (see the "Download for Offline" row on the Bible screen).

   CACHE_NAME is tied to index.html's manual css/style.css?v=N
   convention - bump the trailing number in BOTH places together
   whenever any precached file changes, so a deploy doesn't leave
   old JS/CSS/Bible data stuck in a stale cache forever.
   ============================================================ */
const CACHE_NAME = 'bca-v8';

const SHELL_URLS = [
  './',
  'index.html',
  'manifest.json',
  'css/style.css?v=8',
  'images/icon-192.png',
  'images/icon-512.png',
  // App shell scripts - keep this in sync with index.html's <script src> list.
  'js/questions.js',
  'js/book_questions.js',
  'js/twotruths_data.js',
  'js/higherlower_data.js',
  'js/versefill_data.js',
  'js/emojiclue_data.js',
  'js/impostor_data.js',
  'js/drawing_words.js',
  'js/scrabble_words.js',
  'js/game_instructions.js',
  'js/question_tracker.js',
  'js/custom_questions.js',
  'js/profile.js',
  'js/shop.js',
  'js/admin.js',
  'js/admin_dashboard.js',
  'js/bible_reader.js',
  'js/offline_bible.js',
  'js/offline_queue.js',
  'js/solo_quiz.js',
  'js/pwa.js',
  'js/qrcode.js',
  'js/app.js',
  'js/multiplayer.js',
  'js/host.js',
  'js/join.js',
];

const BIBLE_URLS = ['bible/en_kjv.json', 'bible/abhil82.json'];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(SHELL_URLS)
        // Best-effort only - see the big comment above. Any failure here
        // must never reject the install; the shell must still activate.
        .then(() => Promise.allSettled(BIBLE_URLS.map(url => cache.add(url)))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(key => key.startsWith('bca-') && key !== CACHE_NAME)
          .map(key => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Never touch the live API - always hit the network, fail naturally
  // offline (the app already handles fetch failures gracefully).
  if (url.pathname.includes('/api/')) return;

  // Only handle same-origin GETs; let everything else (cross-origin, POST) pass through.
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  if (req.mode === 'navigate') {
    // App shell page load: network-first so updates show up promptly,
    // falling back to the cached shell when offline.
    event.respondWith(
      fetch(req).catch(() => caches.match('index.html'))
    );
    return;
  }

  // Static assets (js/css/images/bible json): cache-first, since they're
  // versioned by CACHE_NAME itself - a cache hit is always correct for
  // this deployed version.
  event.respondWith(
    caches.match(req).then(cached => cached || fetch(req))
  );
});
