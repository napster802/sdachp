/* ============================================================
   Bible Challenge Arena - Solo Practice
   A fully local, offline-capable trivia practice mode built on
   the same client-bundled QUESTION_DB every multiplayer classic-
   trivia game already uses (js/questions.js) - no server call at
   all. Scored with the identical formula api/submit_answer.php
   uses (base 500 + time bonus, streak multiplier), but this is a
   deliberate, disclosed local-only computation: there's no
   opponent to be unfair to in a solo session, and it NEVER credits
   the shared wallet, History, or Leaderboard - only a personal
   best cached in localStorage.
   ============================================================ */
const SoloQuiz = (function () {
  const TIME_LIMITS = { easy: 30, medium: 25, hard: 20, expert: 15 };
  const BEST_KEY = 'bca_solo_best';

  let difficulty = 'easy';
  let count = 10;

  let pool = [];        // this session's picked question objects
  let qIndex = 0;
  let score = 0;
  let correctCount = 0;
  let wrongCount = 0;
  let streak = 0;
  let bestStreak = 0;

  let questionStartMs = 0;
  let timeLimit = 30;
  let tickTimer = null;
  let answered = false;

  // ── Entry ──────────────────────────────────────────────────
  function open() {
    if (typeof Profile !== 'undefined' && Profile.exists()) {
      App.goTo('solo-setup');
      renderBestBox();
    } else {
      sessionStorage.setItem('bca_pending_action', 'solo');
      App.goTo('profile');
    }
  }

  function close() {
    stopTimer();
    App.goTo('home');
  }

  function quit() {
    close();
  }

  function setDifficulty(diff) {
    difficulty = diff;
    document.querySelectorAll('.solo-diff-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.diff === diff);
    });
    renderBestBox();
  }

  function setCount(n) {
    count = n;
    document.querySelectorAll('.solo-count-btn').forEach(b => {
      b.classList.toggle('active', Number(b.dataset.count) === n);
    });
  }

  function getBests() {
    try { return JSON.parse(localStorage.getItem(BEST_KEY) || '{}'); }
    catch (e) { return {}; }
  }

  function renderBestBox() {
    const box = document.getElementById('solo-best-box');
    const bests = getBests();
    const best = bests[difficulty];
    if (!box) return;
    if (best) {
      box.style.display = 'block';
      document.getElementById('solo-best-diff').textContent = difficulty;
      document.getElementById('solo-best-score').textContent = best.score.toLocaleString();
    } else {
      box.style.display = 'none';
    }
  }

  // ── Session ────────────────────────────────────────────────
  function start() {
    const bank = (typeof QUESTION_DB !== 'undefined' ? QUESTION_DB[difficulty] : null) || [];
    if (!bank.length) {
      App.showToast('No questions available for this difficulty.', 'error');
      return;
    }
    const indices = shuffledIndices(bank.length).slice(0, Math.min(count, bank.length));
    pool = indices.map(i => bank[i]);

    qIndex = 0;
    score = 0;
    correctCount = 0;
    wrongCount = 0;
    streak = 0;
    bestStreak = 0;
    timeLimit = TIME_LIMITS[difficulty] || 30;

    App.goTo('solo-question');
    renderQuestion();
  }

  function shuffledIndices(n) {
    const arr = Array.from({ length: n }, (_, i) => i);
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
  }

  function renderQuestion() {
    answered = false;
    const q = pool[qIndex];
    document.getElementById('solo-q-counter').textContent = `Question ${qIndex + 1}/${pool.length}`;
    document.getElementById('solo-q-score').textContent = score.toLocaleString();
    document.getElementById('solo-q-text').textContent = q.question;
    document.getElementById('solo-q-streak').textContent = streak >= 2 ? `🔥 ${streak} streak!` : '';

    for (let i = 0; i < 4; i++) {
      const btn = document.getElementById('solo-c' + i);
      const txt = document.getElementById('solo-c' + i + '-txt');
      if (txt) txt.textContent = q.choices[i] ?? '';
      if (btn) {
        btn.classList.remove('correct', 'wrong', 'reveal-correct');
        btn.disabled = false;
      }
    }

    questionStartMs = Date.now();
    startTimer();
  }

  function startTimer() {
    stopTimer();
    const fill = document.getElementById('solo-q-progress-fill');
    tickTimer = setInterval(() => {
      const elapsed = (Date.now() - questionStartMs) / 1000;
      const pct = Math.max(0, 100 - (elapsed / timeLimit) * 100);
      if (fill) fill.style.width = pct + '%';
      if (elapsed >= timeLimit && !answered) {
        answer(-1); // time's up, no choice selected
      }
    }, 100);
  }

  function stopTimer() {
    if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
  }

  // Mirrors api/submit_answer.php's classic scoring formula exactly, just
  // computed locally since there's no server round-trip in solo practice.
  function computePoints(isCorrect, timeTaken) {
    if (!isCorrect) return 0;
    const ratio = Math.max(0, 1 - timeTaken / timeLimit);
    const basePoints = 500 + 500 * ratio;
    const newStreak = streak + 1;
    const streakMultiplier = 1 + Math.min(0.5, Math.max(0, newStreak - 2) * 0.1);
    return Math.round(basePoints * streakMultiplier);
  }

  function answer(choiceIdx) {
    if (answered) return;
    answered = true;
    stopTimer();

    const q = pool[qIndex];
    const timeTaken = Math.min(timeLimit, (Date.now() - questionStartMs) / 1000);
    const isCorrect = choiceIdx >= 0 && q.choices[choiceIdx] === q.answer;
    const correctIdx = q.choices.indexOf(q.answer);

    const points = computePoints(isCorrect, timeTaken);
    score += points;
    if (isCorrect) {
      correctCount++;
      streak++;
      bestStreak = Math.max(bestStreak, streak);
    } else {
      wrongCount++;
      streak = 0;
    }

    if (choiceIdx >= 0) {
      const clickedBtn = document.getElementById('solo-c' + choiceIdx);
      if (clickedBtn) clickedBtn.classList.add(isCorrect ? 'correct' : 'wrong');
    }
    if (!isCorrect && correctIdx >= 0) {
      const correctBtn = document.getElementById('solo-c' + correctIdx);
      if (correctBtn) correctBtn.classList.add('reveal-correct');
    }
    for (let i = 0; i < 4; i++) {
      const btn = document.getElementById('solo-c' + i);
      if (btn) btn.disabled = true;
    }
    document.getElementById('solo-q-score').textContent = score.toLocaleString();

    setTimeout(() => {
      qIndex++;
      if (qIndex >= pool.length) finish();
      else renderQuestion();
    }, 1200);
  }

  function finish() {
    stopTimer();
    const bests = getBests();
    const prevBest = bests[difficulty];
    const isNewBest = !prevBest || score > prevBest.score;
    if (isNewBest) {
      bests[difficulty] = { score, date: Date.now() };
      try { localStorage.setItem(BEST_KEY, JSON.stringify(bests)); } catch (e) { /* storage full/unavailable - not critical */ }
    }

    App.goTo('solo-results');
    document.getElementById('solo-results-score').textContent = score.toLocaleString() + ' pts';
    document.getElementById('solo-results-detail').textContent =
      `${correctCount} correct, ${wrongCount} wrong · best streak ${bestStreak}`;
    const bestEl = document.getElementById('solo-results-best');
    if (bestEl) {
      if (isNewBest) {
        bestEl.style.display = 'block';
        bestEl.textContent = '🏅 New personal best!';
      } else {
        bestEl.style.display = 'block';
        bestEl.textContent = `Personal best (${difficulty}): ${prevBest.score.toLocaleString()} pts`;
      }
    }
  }

  return { open, close, quit, setDifficulty, setCount, start, answer };
})();
