/* ============================================================
   Bible Challenge Arena - Offline Bible data
   Parses the two full Bible JSON files into the same shape
   js/bible_reader.js already expects from api/bible.php, so the
   reader can fall back to this when there is no connection.
   Both files use the same simple shape: an array of 66 books,
   each { name, abbrev, chapters }, where chapters[c] is an array
   of verse strings for chapter c+1 - matches how api/bible.php's
   own PHP-side seeding parses these exact files.

   download() is the one real, retryable path that gets a version
   durably available offline: it fetches the raw JSON and writes it
   into the same Cache Storage the service worker uses, independent
   of whether sw.js's own best-effort install-time attempt
   succeeded (that one is atomic-shell-safe but best-effort for the
   large Bible files - see sw.js's comment). Both the automatic
   background download (js/pwa.js) and the manual "Download for
   Offline" button (js/bible_reader.js) call this same function, so
   there is only one download implementation to trust.
   ============================================================ */
const OfflineBible = (function () {
  const OT_BOOK_COUNT = 39; // matches api/bible.php: ($num <= 39) ? 'OT' : 'NT'
  const CACHE_NAME = 'bca-v8'; // must match sw.js's CACHE_NAME - same Cache Storage bucket

  const fileByVersion = { kjv: 'bible/en_kjv.json', abhil82: 'bible/abhil82.json' };

  const cache = {};   // version -> parsed array (this session only)
  const loading = {}; // version -> in-flight download promise

  function stripBom(text) {
    return text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;
  }

  function parseAndStore(version, text) {
    const data = JSON.parse(stripBom(text));
    cache[version] = data;
    return data;
  }

  function download(version) {
    const file = fileByVersion[version] || fileByVersion.kjv;
    if (loading[version]) return loading[version];

    const p = fetch(file, { cache: 'no-store' }).then(async res => {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const resForCache = res.clone();
      const text = await res.text();
      // Persist independently of the service worker's own install-time
      // attempt - this is what actually guarantees offline availability.
      if ('caches' in window) {
        try {
          const c = await caches.open(CACHE_NAME);
          await c.put(file, resForCache);
        } catch (e) {
          // Cache Storage unavailable/full (private browsing, quota) - the
          // in-memory copy below still works for the rest of this session.
        }
      }
      return parseAndStore(version, text);
    });
    loading[version] = p;
    // Clear the in-flight flag on either outcome without creating an
    // unhandled rejection of our own - unlike .finally(), a .then() with
    // both handlers resolves cleanly either way while still letting the
    // original rejection (if any) propagate to whoever awaits `p` itself.
    p.then(() => { loading[version] = null; }, () => { loading[version] = null; });
    return p;
  }

  function ensureLoaded(version) {
    if (cache[version]) return Promise.resolve(cache[version]);
    return download(version);
  }

  // The real persisted state (Cache Storage), not a guess - lets the UI
  // show accurate "Ready offline" / "Download" status even on a fresh
  // page load before anything has been re-fetched this session.
  async function isDownloaded(version) {
    if (cache[version]) return true;
    if (!('caches' in window)) return false;
    const file = fileByVersion[version] || fileByVersion.kjv;
    try {
      const match = await caches.match(file);
      return !!match;
    } catch (e) {
      return false;
    }
  }

  function testamentFor(bookNum) {
    return bookNum <= OT_BOOK_COUNT ? 'OT' : 'NT';
  }

  async function getBooks(version) {
    const data = await ensureLoaded(version);
    return data.map((book, i) => ({
      book_num: i + 1,
      book_name: book.name,
      testament: testamentFor(i + 1),
      chapters: book.chapters.length,
    }));
  }

  async function getChapter(version, bookNum, chapter) {
    const data = await ensureLoaded(version);
    const book = data[bookNum - 1];
    if (!book) throw new Error('Unknown book');
    const chapterVerses = book.chapters[chapter - 1] || [];
    return {
      book_name: book.name,
      testament: testamentFor(bookNum),
      total_chapters: book.chapters.length,
      verses: chapterVerses.map((text, i) => ({ verse: i + 1, text })),
    };
  }

  async function search(version, q) {
    const data = await ensureLoaded(version);
    const needle = q.toLowerCase();
    const results = [];
    for (let bi = 0; bi < data.length && results.length < 100; bi++) {
      const book = data[bi];
      for (let ci = 0; ci < book.chapters.length && results.length < 100; ci++) {
        const verses = book.chapters[ci];
        for (let vi = 0; vi < verses.length; vi++) {
          if (verses[vi].toLowerCase().includes(needle)) {
            results.push({
              book_num: bi + 1, book_name: book.name,
              chapter: ci + 1, verse: vi + 1, text: verses[vi],
            });
            if (results.length >= 100) break;
          }
        }
      }
    }
    return { results, total: results.length };
  }

  // Kicks off downloading both versions in the background so they're
  // ready the moment the user opens the Bible tab or goes offline.
  // Failures are swallowed here - js/pwa.js calls this on load and on
  // reconnect, and the visible status row (js/bible_reader.js) shows and
  // allows retrying anything that didn't succeed.
  function preloadAll() {
    return Promise.allSettled([download('kjv'), download('abhil82')]);
  }

  return { download, isDownloaded, getBooks, getChapter, search, preloadAll };
})();
