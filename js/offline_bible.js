/* ============================================================
   Bible Challenge Arena - Offline Bible data
   Parses the two full Bible JSON files (precached by sw.js at
   install time - see PRECACHE_URLS) into the same shape
   js/bible_reader.js already expects from api/bible.php, so the
   reader can fall back to this when there is no connection.
   Both files use the same simple shape: an array of 66 books,
   each { name, abbrev, chapters }, where chapters[c] is an array
   of verse strings for chapter c+1 - matches how api/bible.php's
   own PHP-side seeding parses these exact files.
   ============================================================ */
const OfflineBible = (function () {
  const OT_BOOK_COUNT = 39; // matches api/bible.php: ($num <= 39) ? 'OT' : 'NT'

  const fileByVersion = { kjv: 'bible/en_kjv.json', abhil82: 'bible/abhil82.json' };

  const cache = {};   // version -> parsed array
  const loading = {}; // version -> in-flight promise

  function stripBom(text) {
    return text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;
  }

  function ensureLoaded(version) {
    if (cache[version]) return Promise.resolve(cache[version]);
    if (loading[version]) return loading[version];
    const file = fileByVersion[version] || fileByVersion.kjv;
    loading[version] = fetch(file)
      .then(res => {
        if (!res.ok) throw new Error('Offline Bible data not available (HTTP ' + res.status + ')');
        return res.text();
      })
      .then(text => {
        const data = JSON.parse(stripBom(text));
        cache[version] = data;
        loading[version] = null;
        return data;
      })
      .catch(err => { loading[version] = null; throw err; });
    return loading[version];
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

  function isReady(version) {
    return !!cache[version];
  }

  // Kicks off parsing both versions in the background so they're instantly
  // available the moment the user opens the Bible tab, online or not.
  // Failures are swallowed - the reader simply falls back to the live API
  // when this hasn't completed (e.g. the raw JSON files aren't cached yet
  // on a brand-new install with no connection at all).
  function preload() {
    return Promise.allSettled([ensureLoaded('kjv'), ensureLoaded('abhil82')]);
  }

  return { getBooks, getChapter, search, isReady, preload };
})();
