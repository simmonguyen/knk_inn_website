/* KnK Inn — shared client scripts
 * Handles: nav scroll, mobile menu, language switcher mounting,
 * reveal-on-scroll, lightbox, sports fixtures (Saigon kickoff times)
 */

(function () {
  'use strict';

  /* ---------- Nav scroll shadow ---------- */
  const nav = document.getElementById('nav');
  if (nav) {
    const onScroll = () => {
      if (window.scrollY > 40) nav.classList.add('scrolled');
      else nav.classList.remove('scrolled');
    };
    document.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------- Mobile menu ---------- */
  const hamburger = document.querySelector('.hamburger');
  const mobileMenu = document.querySelector('.mobile-menu');
  if (hamburger && mobileMenu) {
    hamburger.addEventListener('click', () => {
      const open = mobileMenu.classList.toggle('open');
      hamburger.classList.toggle('open', open);
      hamburger.setAttribute('aria-expanded', String(open));
    });
    mobileMenu.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        mobileMenu.classList.remove('open');
        hamburger.classList.remove('open');
      });
    });
  }

  /* ---------- Mount language switcher ---------- */
  document.querySelectorAll('[data-lang-switch]').forEach(el => {
    if (window.KNK_I18N && typeof window.KNK_I18N.buildLangSwitcher === 'function') {
      window.KNK_I18N.buildLangSwitcher(el);
    }
  });
  if (window.KNK_I18N && typeof window.KNK_I18N.applyLang === 'function') {
    window.KNK_I18N.applyLang(window.KNK_I18N.getCurrentLang());
  }

  /* ---------- Reveal on scroll ---------- */
  const revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('visible');
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.12 });
    revealEls.forEach(el => io.observe(el));
  } else {
    revealEls.forEach(el => el.classList.add('visible'));
  }

  /* ---------- Hero slideshow ---------- */
  const slides = document.querySelectorAll('.hero-slide');
  if (slides.length > 1) {
    let i = 0;
    setInterval(() => {
      slides[i].classList.remove('active');
      i = (i + 1) % slides.length;
      slides[i].classList.add('active');
    }, 5500);
  }

  /* ---------- Lightbox ---------- */
  const lb = document.getElementById('lightbox');
  if (lb) {
    const lbImg = lb.querySelector('.lb-img-wrap img') || lb.querySelector('img');
    const lbClose = lb.querySelector('.lb-close');
    const lbPrev = lb.querySelector('#lbPrev') || lb.querySelector('.lb-prev');
    const lbNext = lb.querySelector('#lbNext') || lb.querySelector('.lb-next');
    const lbCounter = lb.querySelector('.lb-counter');
    let images = [];
    let cursor = 0;

    function update() {
      lbImg.src = images[cursor];
      if (lbCounter) lbCounter.textContent = (cursor + 1) + ' / ' + images.length;
    }
    function open(idx) {
      cursor = idx;
      update();
      lb.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
    function close() {
      lb.classList.remove('open');
      lbImg.src = '';
      document.body.style.overflow = '';
    }
    function prev() { cursor = (cursor - 1 + images.length) % images.length; update(); }
    function next() { cursor = (cursor + 1) % images.length; update(); }

    lbClose && lbClose.addEventListener('click', close);
    lbPrev && lbPrev.addEventListener('click', prev);
    lbNext && lbNext.addEventListener('click', next);
    lb.addEventListener('click', (e) => { if (e.target === lb) close(); });
    document.addEventListener('keydown', (e) => {
      if (!lb.classList.contains('open')) return;
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowLeft') prev();
      else if (e.key === 'ArrowRight') next();
    });

    function bind(scope) {
      const targets = (scope || document).querySelectorAll('[data-lb]');
      images = Array.from(targets).map(t => t.getAttribute('data-lb-src') || t.querySelector('img').src);
      targets.forEach((t, idx) => {
        t.addEventListener('click', (e) => {
          e.preventDefault();
          open(idx);
        });
      });
    }
    window.KNK_LB = { bind };
    bind(document);
  }

  /* ---------- Gallery filter ---------- */
  const filterBar = document.querySelector('.filter-bar');
  if (filterBar) {
    const items = document.querySelectorAll('[data-cat]');
    filterBar.querySelectorAll('.chip').forEach(chip => {
      chip.addEventListener('click', () => {
        filterBar.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        const cat = chip.getAttribute('data-filter');
        items.forEach(it => {
          const cats = (it.getAttribute('data-cat') || '').split(' ');
          if (cat === 'all' || cats.includes(cat)) it.style.display = '';
          else it.style.display = 'none';
        });
        if (window.KNK_LB && typeof window.KNK_LB.bind === 'function') {
          // rebuild lightbox list to visible items
          const visible = Array.from(items).filter(i => i.style.display !== 'none');
          // tag them temporarily
          // simpler: rebind all and let user navigate full set
        }
      });
    });
  }

  /* ---------- Smooth anchor scroll ---------- */
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    const href = a.getAttribute('href');
    if (href === '#' || href.length < 2) return;
    a.addEventListener('click', (e) => {
      const tgt = document.querySelector(href);
      if (!tgt) return;
      e.preventDefault();
      const y = tgt.getBoundingClientRect().top + window.scrollY - 70;
      window.scrollTo({ top: y, behavior: 'smooth' });
    });
  });

  /* ---------- Footer year ---------- */
  document.querySelectorAll('[data-year]').forEach(el => {
    el.textContent = String(new Date().getFullYear());
  });
})();


/* ============================================================
 * Sports module — fetches upcoming fixtures, displays in
 * Saigon time (ICT, UTC+7). Falls back to a curated list
 * if the API is unreachable.
 * ============================================================ */
(function () {
  'use strict';
  /* Mounts on #fixtures-list — the styled <li class="fixture"> grid
   * the home page already had hardcoded. Earlier work targeted
   * #sports-list (which never existed in the DOM), so the JS was a
   * silent no-op and guests only ever saw the stale hardcoded list.
   * That's the "3 listings vs many on the TV" Ben spotted. */
  const mount = document.getElementById('fixtures-list');
  if (!mount) return;

  const SAIGON_TZ = 'Asia/Ho_Chi_Minh';

  /* Sport name → filter-chip slug. Matches the buttons in the
   * .sports-filter row of index.php. Unmapped sports get "other"
   * so they still show under "All" but per-sport filters won't
   * grab them. */
  function sportSlug(sport) {
    const s = String(sport || '').toLowerCase();
    if (s === 'nrl' || s === 'rugby league')        return 'nrl';
    if (s === 'afl' || s === 'australian football') return 'afl';
    if (s === 'soccer' || s === 'football')         return 'soccer';
    if (s === 'rugby union' || s === 'rugby')       return 'rugby';
    if (s.indexOf('formula') === 0 || s === 'motogp' || s === 'motorsport') return 'motogp';
    if (s === 'nfl' || s === 'american football')   return 'nfl';
    return 'other';
  }
  function tagClass(slug) {
    if (['nrl','afl','soccer','rugby','motogp','nfl'].indexOf(slug) >= 0) return 'fx-' + slug;
    return 'fx-other';
  }

  function fmtKickoff(iso) {
    if (!iso) return null;
    const d = new Date(iso);
    if (isNaN(d.getTime())) return null;
    const dow = new Intl.DateTimeFormat('en-GB', { timeZone: SAIGON_TZ, weekday: 'short' }).format(d);
    const dom = new Intl.DateTimeFormat('en-GB', { timeZone: SAIGON_TZ, day: '2-digit' }).format(d);
    const mon = new Intl.DateTimeFormat('en-GB', { timeZone: SAIGON_TZ, month: 'short' }).format(d);
    const time = new Intl.DateTimeFormat('en-GB', {
      timeZone: SAIGON_TZ, hour: 'numeric', minute: '2-digit', hour12: true
    }).format(d).toLowerCase();
    return { dow, dom, mon, time };
  }

  function escapeText(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  /* Emit the same <li class="fixture"> shape the hardcoded HTML used
   * — preserves the existing CSS and the filterFixtures() chip
   * logic in index.php (which queries #fixtures-list .fixture +
   * data-sport). */
  function card(ev) {
    const k = fmtKickoff(ev.kickoff);
    const slug = sportSlug(ev.sport);
    const tagCls = tagClass(slug);
    const tagLabel = escapeText(ev.sport || 'Sport');
    const dateBlock = k
      ? `<div class="fx-date"><span class="dow">${escapeText(k.dow)}</span><span class="dom">${escapeText(k.dom)}</span><span class="mon">${escapeText(k.mon)}</span></div>`
      : `<div class="fx-date"><span class="dow">TBD</span></div>`;
    const venue = ev.subtitle
      ? `<p class="fx-venue">${escapeText(ev.subtitle)}</p>`
      : '';
    const timeBlock = k
      ? `<div class="fx-time">${escapeText(k.time)}<span class="small">ICT kickoff</span></div>`
      : `<div class="fx-time">TBD<span class="small">time</span></div>`;
    return `
      <li class="fixture" data-sport="${escapeText(slug)}">
        ${dateBlock}
        <div class="fx-meta">
          <div class="fx-tags"><span class="fx-tag ${tagCls}">${tagLabel}</span></div>
          <p class="fx-title">${escapeText(ev.title || '')}</p>
          ${venue}
        </div>
        ${timeBlock}
      </li>`;
  }

  function render(events) {
    if (!events.length) {
      mount.innerHTML = '<li class="fixture"><div class="fx-meta" style="padding:1rem;color:rgba(245,233,209,0.7);">Fixtures will be posted soon — pop in and ask what’s on.</div></li>';
      return;
    }
    mount.innerHTML = events.map(card).join('');
  }

  // Filter + sort helper — shared by JSON-fetched list and the
  // hard-coded last-ditch fallback below. Keeps only upcoming events
  // within the next 30 days, sorts soonest-first, caps at 14 cards.
  function trimForDisplay(all) {
    const now = new Date();
    const horizon = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000);
    return all
      .filter(e => !e.kickoff || (new Date(e.kickoff) >= now && new Date(e.kickoff) <= horizon))
      .sort((a, b) => {
        if (!a.kickoff) return 1;
        if (!b.kickoff) return -1;
        return new Date(a.kickoff) - new Date(b.kickoff);
      })
      .slice(0, 14);
  }

  // Curated fixtures JSON — shared source of truth with the PHP
  // reminder script. If the fetch fails (e.g. file missing mid-deploy)
  // we drop through to the hard-coded fallback below.
  async function fetchCurated() {
    try {
      const r = await fetch('/assets/data/fixtures.json', { cache: 'no-cache' });
      if (!r.ok) return null;
      const data = await r.json();
      if (!data || !Array.isArray(data.fixtures)) return null;
      return trimForDisplay(data.fixtures);
    } catch (e) {
      return null;
    }
  }

  // Hard-coded last-ditch fallback — only used if both the live API
  // AND the curated JSON are unreachable. Keep this list short;
  // edit the real list in /assets/data/fixtures.json.
  function fallbackFixtures() {
    const all = [
      // Cricket — IPL 2026 fixtures (typical 7:30pm IST = 9:00pm SGT)
      { sport: 'Cricket', title: 'IPL: Mumbai Indians vs Chennai Super Kings', subtitle: 'Wankhede Stadium', kickoff: '2026-04-21T14:00:00Z' },
      { sport: 'Cricket', title: 'IPL: Royal Challengers Bangalore vs Delhi Capitals', subtitle: 'M. Chinnaswamy Stadium', kickoff: '2026-04-25T14:00:00Z' },
      { sport: 'Cricket', title: 'IPL: Kolkata Knight Riders vs Punjab Kings', subtitle: 'Eden Gardens', kickoff: '2026-05-02T14:00:00Z' },
      // F1 — 2026 calendar
      { sport: 'Formula 1', title: 'F1: Miami Grand Prix', subtitle: 'Race day', kickoff: '2026-05-03T19:30:00Z' },
      { sport: 'Formula 1', title: 'F1: Emilia Romagna GP (Imola)', subtitle: 'Race day', kickoff: '2026-05-17T13:00:00Z' },
      // Boxing
      { sport: 'Boxing', title: 'Crawford vs Madrimov II', subtitle: 'Welterweight title', kickoff: '2026-04-26T03:00:00Z' },
      { sport: 'Boxing', title: 'Joshua vs Dubois — Heavyweight', subtitle: 'Wembley Stadium', kickoff: '2026-05-09T20:00:00Z' },
      // AFL — Round 6/7
      { sport: 'AFL', title: 'Collingwood vs Essendon (Anzac Day)', subtitle: 'MCG', kickoff: '2026-04-25T05:20:00Z' },
      { sport: 'AFL', title: 'Geelong Cats vs Hawthorn', subtitle: 'GMHBA Stadium', kickoff: '2026-05-02T04:35:00Z' },
      { sport: 'AFL', title: 'Sydney Swans vs Brisbane Lions', subtitle: 'SCG', kickoff: '2026-05-09T07:25:00Z' },
      // NRL — Round 8/9
      { sport: 'NRL', title: 'Penrith Panthers vs Brisbane Broncos', subtitle: 'BlueBet Stadium', kickoff: '2026-04-24T09:50:00Z' },
      { sport: 'NRL', title: 'Sydney Roosters vs Melbourne Storm', subtitle: 'Allianz Stadium', kickoff: '2026-05-01T09:50:00Z' },
      // Soccer — Premier League run-in
      { sport: 'Soccer', title: 'EPL: Liverpool vs Tottenham', subtitle: 'Anfield', kickoff: '2026-04-25T14:00:00Z' },
      { sport: 'Soccer', title: 'EPL: Manchester City vs Aston Villa', subtitle: 'Etihad Stadium', kickoff: '2026-04-26T15:30:00Z' },
      { sport: 'Soccer', title: 'UCL Semi-final: Real Madrid vs Bayern', subtitle: 'Santiago Bernabéu', kickoff: '2026-04-29T19:00:00Z' },
      { sport: 'Soccer', title: 'UCL Semi-final: Arsenal vs PSG', subtitle: 'Emirates Stadium', kickoff: '2026-04-30T19:00:00Z' },
      // Rugby Union
      { sport: 'Rugby Union', title: 'Super Rugby: Crusaders vs Blues', subtitle: 'Apollo Projects Stadium', kickoff: '2026-04-25T06:35:00Z' },
      { sport: 'Rugby Union', title: 'Super Rugby: Brumbies vs Reds', subtitle: 'GIO Stadium Canberra', kickoff: '2026-05-02T09:35:00Z' },
      // Tennis
      { sport: 'Tennis', title: 'Madrid Open — Men\u2019s Final', subtitle: 'Caja Mágica', kickoff: '2026-05-03T14:30:00Z' },
      { sport: 'Tennis', title: 'Italian Open begins (Rome)', subtitle: 'Foro Italico — week 1', kickoff: '2026-05-06T09:00:00Z' },
      // Olympics & World Cup notes
      { sport: 'World Cup', title: 'FIFA World Cup 2026 — Group Stage opens 11 Jun', subtitle: 'Mexico City\u00A0\u2022\u00A0Hosted by USA, Canada, Mexico', kickoff: '2026-06-11T22:00:00Z' },
      { sport: 'Olympics', title: 'LA 2028 — qualifiers underway', subtitle: 'Multiple sports across the season', kickoff: null }
    ];
    return trimForDisplay(all);
  }

  async function fetchLive() {
    const sports = [
      { tsdb: 'Cricket',          label: 'Cricket' },
      { tsdb: 'Motorsport',       label: 'Formula 1' },
      { tsdb: 'Fighting',         label: 'Boxing' },
      { tsdb: 'Australian Football', label: 'AFL' },
      { tsdb: 'Rugby',            label: 'Rugby Union' },
      { tsdb: 'Soccer',           label: 'Soccer' },
      { tsdb: 'Tennis',           label: 'Tennis' }
    ];
    const events = [];
    for (const s of sports) {
      try {
        const r = await fetch('https://www.thesportsdb.com/api/v1/json/3/eventsday.php?d=' +
          new Date().toISOString().slice(0, 10) + '&s=' + encodeURIComponent(s.tsdb));
        if (!r.ok) continue;
        const data = await r.json();
        if (!data.events) continue;
        data.events.slice(0, 2).forEach(ev => {
          const iso = (ev.strTimestamp || (ev.dateEvent + 'T' + (ev.strTime || '00:00:00') + 'Z'));
          events.push({
            sport: s.label,
            title: ev.strEvent,
            subtitle: ev.strLeague || '',
            kickoff: iso
          });
        });
      } catch (e) { /* ignore */ }
    }
    return events;
  }

  // Try live API first (today's games from TheSportsDB).
  // If that returns nothing useful, fall back to the curated JSON list.
  // If the JSON is unreachable too, use the inline hard-coded list.
  fetchLive()
    .then(async live => {
      if (live && live.length >= 4) { render(live); return; }
      const curated = await fetchCurated();
      if (curated && curated.length) { render(curated); return; }
      render(fallbackFixtures());
    })
    .catch(async () => {
      const curated = await fetchCurated();
      render(curated && curated.length ? curated : fallbackFixtures());
    });
})();

/* ═══════════════════════════════════════════
   Enquiry form — stamp timestamp for anti-spam check
═══════════════════════════════════════════ */
document.querySelectorAll('.enquire-form input[name="ts"]').forEach(el => {
  el.value = Math.floor(Date.now() / 1000);
});
