/*!
 * Sitewise admin app — ported from the Folium Suite design reference.
 * Two design bundles (core + widget section) followed by a boot shim that
 * injects real WordPress data (window.SitewiseData), wires Save/Rebuild/Test
 * to admin-ajax, and registers the app with the Folium frame.
 */

/* ===== design bundle: core (de6427f9) ===== */
/* ============================================================================
   SITEWISE — grounded on-page chat assistant.  Folium UI plugin (core).
   Renders into the shared #wpd panel. The Widget screen lives in
   sitewise-widget.js. Plugin host (in the page) swaps Sitewise <-> WP Disable.
   ============================================================================ */
(function () {
  const SW = (window.SW = window.SW || {});
  const $ = (s, r) => (r || document).querySelector(s);
  const ICON = (n) => window.FL.icon(n);

  /* ---- extra icons (merged into the Folium set) --------------------------- */
  SW.addIcons = function () {
    Object.assign(window.FL.icons, {
      book:'<path d="M5 4h10a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2V4Z"/><path d="M5 16h12"/><path d="M9 8h5M9 11h5"/>',
      palette:'<path d="M12 3a9 9 0 1 0 0 18c1.4 0 2-1 2-1.8 0-.5-.3-.9-.6-1.2-.3-.4-.6-.7-.6-1.2 0-.8.7-1.3 1.5-1.3H16a5 5 0 0 0 5-5c0-4.4-4-7.5-9-7.5Z"/><circle cx="7.5" cy="11" r="1"/><circle cx="11" cy="7.5" r="1"/><circle cx="15.5" cy="8.5" r="1"/>',
      sliders:'<path d="M5 5v6M5 15v4M12 5v3M12 12v7M19 5v8M19 17v2"/><circle cx="5" cy="13" r="2"/><circle cx="12" cy="10" r="2"/><circle cx="19" cy="15" r="2"/>',
      key:'<circle cx="8" cy="14" r="4"/><path d="m11 11 8-8M16 6l3 3M14 8l2 2"/>',
      send:'<path d="M4 12 20 4l-6 16-3-7-7-1Z"/>',
      play:'<path d="M7 5v14l12-7L7 5Z"/>',
      globe:'<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.4 2.5 14.6 0 17M12 3.5c-2.5 2.4-2.5 14.6 0 17"/>',
      link:'<path d="M10 14a4 4 0 0 0 5.7 0l2.8-2.8a4 4 0 1 0-5.7-5.7L11 7.3"/><path d="M14 10a4 4 0 0 0-5.7 0L5.5 12.8a4 4 0 1 0 5.7 5.7L13 16.7"/>',
      cloud:'<path d="M7 18a4 4 0 0 1-.5-8A6 6 0 0 1 18 9.5 3.5 3.5 0 0 1 17.5 18H7Z"/>',
      robot:'<rect x="5" y="8" width="14" height="11" rx="2"/><path d="M12 4v4M9 13h.01M15 13h.01"/><path d="M9 16h6"/><path d="M3 12v3M21 12v3"/>',
      x:'<path d="m6 6 12 12M18 6 6 18"/>',
      copy:'<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
    });
  };

  /* ---- plugin identity (read by the host) --------------------------------- */
  SW.meta = {
    id: 'sitewise', name: 'Sitewise', by: 'Folium Studio', mark: 'S',
    tagline: 'Grounded on-page chat', version: 'v1.0.0',
    activeChip: '<span class="fl-dot"></span> Live · SaaS',
    searchPlaceholder: 'Filter Sitewise…',
  };

  /* ---- state -------------------------------------------------------------- */
  const state = (SW.state = {
    section: 'dashboard',
    dirty: false, saved: 0,
    provider: 'workersai',          // workersai | haiku
    retrieval: 'auto',              // auto | stuff | rag
    answerLen: 'Short · 2–4 sentences',
    strictCorpus: true, redirectContact: true,
    rebuilding: false,
    revealKey: {},
    widget: {
      accent: '#1f7a3c', pos: 'br', shape: 'circle', theme: 'light',
      botName: 'Ask the studio', subtitle: 'Typically replies instantly',
      opening: 'Hi! I can answer questions about our services, venues and coverage. What would you like to know?',
      placeholder: 'Ask a question…', powered: true,
      autoInject: true, frontendMode: 'callback',
    },
  });

  /* ---- demo data ---------------------------------------------------------- */
  SW.crawlRows = [
    { path:'/', title:'Home', status:'ok', tokens:980, synced:'6m ago' },
    { path:'/services', title:'Services', status:'ok', tokens:1840, synced:'6m ago' },
    { path:'/services/weddings', title:'Wedding photography', status:'ok', tokens:2210, synced:'6m ago' },
    { path:'/venues', title:'Venues we cover', status:'ok', tokens:1620, synced:'6m ago' },
    { path:'/coverage/lake-district', title:'Lake District coverage', status:'sync', tokens:0, synced:'now' },
    { path:'/about', title:'About the studio', status:'ok', tokens:1130, synced:'6m ago' },
    { path:'/pricing', title:'Pricing & packages', status:'ok', tokens:1490, synced:'6m ago' },
    { path:'/notebook/golden-hour', title:'Notebook: golden hour', status:'ok', tokens:870, synced:'1h ago' },
    { path:'/contact', title:'Contact', status:'ok', tokens:240, synced:'6m ago' },
    { path:'/gallery', title:'Gallery', status:'skip', tokens:0, synced:'—', note:'noindex' },
    { path:'/cart', title:'Cart', status:'skip', tokens:0, synced:'—', note:'excluded' },
    { path:'/account/login', title:'Account', status:'err', tokens:0, synced:'failed', note:'403' },
  ];

  SW.logRows = [
    { q:'Do you photograph destination weddings?', kind:'answered', t:'2m ago' },
    { q:'What\u2019s included in the full-day package?', kind:'answered', t:'14m ago' },
    { q:'Can I see your Lake District venues?', kind:'answered', t:'31m ago' },
    { q:'How much for a 200-guest wedding in June?', kind:'lead', t:'1h ago' },
    { q:'Do you offer refunds?', kind:'deflected', t:'2h ago' },
    { q:'Are you available on 2026-08-15?', kind:'lead', t:'3h ago' },
  ];

  /* ---- nav ---------------------------------------------------------------- */
  SW.NAV = [
    { id:'dashboard',   label:'Dashboard',   icon:'gauge' },
    { id:'corpus',      label:'Knowledge',   icon:'book' },
    { id:'widget',      label:'Widget',      icon:'palette' },
    { id:'behavior',    label:'Bot behavior',icon:'sliders' },
    { id:'connections', label:'Connections', icon:'link' },
    { id:'tools',       label:'Tools',       icon:'tools' },
  ];

  /* ---- small builders ----------------------------------------------------- */
  const sectionHead = (num, eyebrow, title, lead, metaHTML) => `
    <div class="wpd-section-head">
      <div class="fl-stack" style="gap:7px">
        <span class="fl-eyebrow"><span class="fl-num">${num}</span> — ${eyebrow}</span>
        <h2 class="fl-h1" style="font-size:24px">${title}</h2>
        <p class="fl-lead" style="max-width:660px">${lead}</p>
      </div>
      ${metaHTML ? `<div class="wpd-section-meta">${metaHTML}</div>` : ''}
    </div>`;

  const toggleRow = (key, title, desc, on, fieldHTML) => `
    <div class="fl-row ${on ? 'is-on' : ''}">
      <div class="fl-row-main">
        <div class="fl-row-title">${title}</div>
        <div class="fl-row-desc">${desc}</div>
      </div>
      <div class="fl-row-ctrl">${fieldHTML || ''}<label class="fl-switch"><input type="checkbox" data-swset="${key}" ${on ? 'checked' : ''}/><span class="fl-track"></span><span class="fl-thumb"></span></label></div>
    </div>`;

  /* ====================================================================== */
  /*  DASHBOARD                                                             */
  /* ====================================================================== */
  function dashboard() {
    const R = SW.real || {};
    const tokens = SW.crawlRows.reduce((a, r) => a + r.tokens, 0);
    const pages = SW.crawlRows.filter(r => r.status === 'ok').length;
    const failed = SW.crawlRows.filter(r => r.status === 'err').length;
    const skipped = SW.crawlRows.filter(r => r.status === 'skip').length;
    const logPill = (k) => k === 'answered'
      ? '<span class="fl-pill fl-pill--good"><span class="fl-dot"></span> Answered</span>'
      : k === 'lead' ? '<span class="fl-pill fl-pill--info"><span class="fl-dot"></span> Lead</span>'
      : '<span class="fl-pill fl-pill--warn"><span class="fl-dot"></span> Deflected</span>';

    return `<div class="wpd-section" data-screen-label="Dashboard">
      <div class="wpd-hero-card sw-hero fl-card">
        <div class="wpd-hero-copy">
          <span class="fl-eyebrow"><span class="fl-num">00</span> — SITEWISE</span>
          <h2 class="fl-h1">Grounded answers from your own site.</h2>
          <p class="fl-lead">A live read on what Sitewise is answering, what it knows, and how the corpus is syncing.</p>
          <span class="fl-pill fl-pill--good"><span class="fl-dot"></span> Updated live</span>
        </div>
        <div class="wpd-hero-mark sw-hero-mark">S</div>
      </div>

      <div class="sw-metrics">
        <div class="fl-metric">
          <div class="fl-metric-top"><span class="fl-metric-label">Messages this month</span></div>
          <div class="fl-metric-value" style="color:var(--fl-ink-3)">—</div>
          <div class="fl-metric-foot">No message log yet</div>
        </div>
        <div class="fl-metric">
          <div class="fl-metric-top"><span class="fl-metric-label">Pages indexed</span></div>
          <div class="fl-metric-value">${pages}<span class="fl-unit">/ ${SW.crawlRows.length}</span></div>
          <div class="fl-metric-foot">${failed} failed · ${skipped} skipped</div>
        </div>
        <div class="fl-metric">
          <div class="fl-metric-top"><span class="fl-metric-label">Corpus size</span></div>
          <div class="fl-metric-value">${(tokens/1000).toFixed(1)}<span class="fl-unit">K tok</span></div>
          <div class="fl-meter"><span style="width:${Math.min(100, tokens/300)}%"></span></div>
        </div>
        <div class="fl-metric" style="flex-direction:row;align-items:center;gap:16px">
          <div class="fl-ring" style="--_v:0"><b style="color:var(--fl-ink-3)">—</b></div>
          <div class="fl-stack" style="gap:3px">
            <span class="fl-metric-label">From corpus</span>
            <span style="font-size:12px;color:var(--fl-ink-2)">Answer-rate logging coming soon</span>
          </div>
        </div>
      </div>

      <div class="sw-statusbar" style="margin-top:14px">
        <div class="it"><span class="k">Worker</span><span class="v"><span class="sw-cstat ${R.workerOk ? 'ok' : 'skip'}"><span class="d"></span></span> ${R.workerStatus || 'Not connected'}</span></div>
        <div class="sep"></div>
        <div class="it"><span class="k">Site key</span><span class="v fl-mono" style="font-size:12px">${R.siteKey || '—'}</span></div>
        <div class="sep"></div>
        <div class="it"><span class="k">Model</span><span class="v">${R.model || 'Llama 3.1 8B'} <span class="fl-pill" style="margin-left:2px"><span class="fl-dot"></span> Stuff</span></span></div>
        <div class="sep"></div>
        <div class="it"><span class="k">Last sync</span><span class="v">${R.lastSync || 'never'}</span></div>
        <div style="margin-left:auto"><button class="fl-btn fl-btn--sm" data-swact="rebuild"><span class="fl-i" data-ic="refresh"></span> Sync now</button></div>
      </div>

      <div class="sw-two-wide" style="margin-top:20px">
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">RECENT CONVERSATIONS</span></div><a class="fl-link" data-swnav="behavior" style="font-size:11.5px;cursor:pointer">Tune answers →</a></div>
          <div class="sw-log">
            ${SW.logRows.map(r => `
              <div class="sw-log-row">
                <div class="sw-log-q"><span class="fl-i qi" data-ic="comment"></span><b>${r.q}</b></div>
                ${logPill(r.kind)}
                <span class="sw-log-time">${r.t}</span>
              </div>`).join('')}
          </div>
        </div>

        <div class="fl-stack" style="gap:14px">
          <div class="fl-banner fl-banner--warn">
            <span class="fl-i" data-ic="warn" style="color:var(--fl-warn)"></span>
            <div class="fl-banner-body"><div class="fl-banner-title">1 page failed to crawl</div><div class="fl-banner-desc"><code>/account/login</code> returned 403. It\u2019s excluded from the corpus.</div></div>
            <button class="fl-btn fl-btn--sm" data-swnav="corpus">Review</button>
          </div>
          <div class="fl-banner fl-banner--accent">
            <span class="fl-i" data-ic="info" style="color:var(--fl-accent)"></span>
            <div class="fl-banner-body"><div class="fl-banner-title">Corpus at ${(tokens/1000).toFixed(1)}K tokens</div><div class="fl-banner-desc">Well under the 30K stuff-the-prompt limit — RAG isn\u2019t needed yet.</div></div>
          </div>
          <div class="fl-card sw-card-pad">
            <div class="fl-stack" style="gap:4px">
              <span class="fl-metric-label">Plan</span>
              <div class="fl-row-flex" style="gap:10px;margin-top:4px"><span class="fl-pill fl-pill--solid">BYO</span><span class="fl-meta">Self-hosted Cloudflare Worker · no message cap</span></div>
              <div class="fl-meter" style="margin-top:8px"><span style="width:26%"></span></div>
            </div>
          </div>
        </div>
      </div>
    </div>`;
  }

  /* ====================================================================== */
  /*  KNOWLEDGE / CORPUS                                                     */
  /* ====================================================================== */
  function corpus() {
    const tokens = SW.crawlRows.reduce((a, r) => a + r.tokens, 0);
    const pages = SW.crawlRows.filter(r => r.status === 'ok').length;
    const cstat = (s, note) => {
      const map = { ok:['ok','Synced'], sync:['sync','Crawling'], skip:['skip', note ? note : 'Skipped'], err:['err', note ? 'Error '+note : 'Failed'] };
      const [cls, lab] = map[s] || map.ok;
      return `<span class="sw-cstat ${cls}"><span class="d"></span> ${lab}</span>`;
    };

    const rebuildBar = state.rebuilding ? `
      <div class="sw-rebuild">
        <span class="sw-cstat sync"><span class="d"></span></span>
        <span class="fl-row-desc" style="flex:0 0 auto">Crawling with Firecrawl…</span>
        <div class="fl-meter" id="sw-rebuild-meter"><span style="width:8%"></span></div>
        <span class="fl-meta" id="sw-rebuild-pct">8%</span>
      </div>` : '';

    return `<div class="wpd-section" data-screen-label="Knowledge">
      ${sectionHead('01','KNOWLEDGE','Knowledge corpus',
        'Sitewise compiles your site into two files — a short map (<code>llms.txt</code>) and a full corpus (<code>llms-full.txt</code>) — and keeps them in sync as you publish. The bot answers only from this.',
        `<div class="fl-stack" style="align-items:flex-end;gap:6px"><span class="fl-metric-value" style="font-size:24px">${pages}<span class="fl-unit">pages</span></span><span class="fl-meta">${(tokens/1000).toFixed(1)}K TOKENS</span></div>`)}

      <div class="sw-two" style="margin-bottom:18px">
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">SOURCES &amp; DISCOVERY</span></div></div>
          <div class="fl-rows">
            ${toggleRow('src_pages','Pages','Static pages — about, services, contact.', true)}
            ${toggleRow('src_posts','Posts','Blog &amp; notebook entries.', true)}
            ${toggleRow('src_products','Products','WooCommerce product titles &amp; descriptions.', false, '<span class="fl-pill"><span class="fl-dot"></span> v2</span>')}
            ${toggleRow('src_gsc','Discover URLs via Search Console','Pull the indexed URL list from your GSC property.', true)}
            ${toggleRow('src_noindex','Skip noindex &amp; password-protected','Honour <code>noindex</code> and never crawl protected posts.', true)}
          </div>
        </div>
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">HAND-CURATION</span></div><span class="fl-meta">prepended to every build</span></div>
          <div class="sw-card-pad sw-stack-4">
            <div class="fl-field">
              <span class="fl-label">Studio orientation</span>
              <textarea class="fl-textarea" data-swset="orientation" style="min-height:88px">Shine Pictures is a two-person wedding & portrait studio based in the Lake District, shooting across the North of England. House style is unobtrusive, documentary, warm. We do not shoot corporate events.</textarea>
              <span class="fl-hint">Sets the bot\u2019s frame before any page content.</span>
            </div>
            <div class="fl-field">
              <span class="fl-label">FAQ block</span>
              <textarea class="fl-textarea" data-swset="faq" style="min-height:78px">Q: Do you travel? A: Yes, UK-wide; travel beyond 2hrs is quoted per booking.
Q: Deposit? A: 25% to hold a date, balance due 14 days before.</textarea>
              <span class="fl-hint">Often the most valuable content the bot has.</span>
            </div>
          </div>
        </div>
      </div>

      <div class="fl-card" style="overflow:hidden;margin-bottom:18px">
        <div class="sw-crawlhead">
          <div class="sw-crawl-stat"><span class="k">Pages</span><span class="v">${SW.crawlRows.length}</span></div>
          <div class="sw-crawl-stat"><span class="k">Tokens</span><span class="v">${(tokens/1000).toFixed(1)}K</span></div>
          <div class="sw-crawl-stat"><span class="k">Crawler</span><span class="v" style="font-weight:560"><span class="sw-cstat ok"><span class="d"></span></span> Firecrawl</span></div>
          <div class="sw-crawl-stat"><span class="k">Last sync</span><span class="v" style="font-weight:560">6m ago</span></div>
          <div class="grow"></div>
          <button class="fl-btn fl-btn--sm" data-swact="export-corpus"><span class="fl-i" data-ic="external"></span> View llms.txt</button>
          <button class="fl-btn fl-btn--primary fl-btn--sm" data-swact="rebuild" ${state.rebuilding ? 'disabled' : ''}><span class="fl-i" data-ic="refresh"></span> ${state.rebuilding ? 'Rebuilding…' : 'Rebuild all'}</button>
        </div>
        ${rebuildBar}
        <table class="sw-table">
          <thead><tr><th>Page</th><th>Status</th><th class="r">Tokens</th><th class="r">Last synced</th></tr></thead>
          <tbody>
            ${SW.crawlRows.map(r => `
              <tr>
                <td><div class="sw-url"><span class="fl-i pi" data-ic="${r.status==='err'?'warn':'feed'}"></span><div class="fl-stack" style="gap:1px;min-width:0"><span class="path">${r.path}</span><span class="ptitle">${r.title}</span></div></div></td>
                <td>${cstat(r.status, r.note)}</td>
                <td class="r num">${r.tokens ? r.tokens.toLocaleString() : '—'}</td>
                <td class="r"><span class="fl-meta">${r.synced}</span></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>

      <div class="fl-card">
        <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">GENERATED · llms.txt</span></div><span class="fl-meta">public · /wp-content/uploads/sitewise/</span></div>
        <div class="sw-card-pad">
          <div class="sw-corpus-prev"><span class="c"># Shine Pictures — llms.txt</span>
<span class="c"># Auto-generated by Sitewise · 12 pages · 10.4K tokens</span>

<span class="h">## Services</span>
- <span class="h">Wedding photography</span> — <span class="u">/services/weddings</span>
  Full-day documentary coverage across the North of England.
- <span class="h">Portraits</span> — <span class="u">/services/portraits</span>
  Studio and on-location family and individual sessions.

<span class="h">## Venues</span>
- <span class="h">Venues we cover</span> — <span class="u">/venues</span>
  Lake District, Yorkshire Dales, and surrounding estates.

<span class="h">## FAQ</span>
- Travel is UK-wide; beyond 2hrs quoted per booking.
- 25% deposit holds a date; balance due 14 days before.</div>
        </div>
      </div>
    </div>`;
  }

  /* ====================================================================== */
  /*  BOT BEHAVIOR                                                           */
  /* ====================================================================== */
  function behavior() {
    const tokens = SW.crawlRows.reduce((a, r) => a + r.tokens, 0);
    const modePct = Math.min(96, (tokens / 30000) * 100);
    const provider = (id, logo, name, sub, desc, footL, footR) => `
      <div class="sw-provider ${state.provider === id ? 'is-sel' : ''}" data-swprovider="${id}">
        <span class="sw-pick"></span>
        <div class="sw-provider-top">
          <span class="sw-provider-logo">${logo}</span>
          <div class="fl-stack" style="gap:2px"><span class="sw-provider-name">${name}</span><span class="sw-provider-sub">${sub}</span></div>
        </div>
        <p class="sw-provider-desc">${desc}</p>
        <div class="sw-provider-foot"><span class="fl-meta">${footL}</span>${footR}</div>
      </div>`;

    const lenOpts = ['Short · 2–4 sentences','Medium · a paragraph','Detailed · multi-paragraph'];

    return `<div class="wpd-section" data-screen-label="Bot behavior">
      ${sectionHead('03','BOT BEHAVIOR','How the bot answers',
        'Pick the model, control how much corpus reaches the prompt, and write the system instruction. The default keeps answers short and routes anything off-corpus to your contact form.',
        '')}

      <div class="wpd-group">
        <div class="wpd-group-head"><span class="fl-eyebrow">MODEL PROVIDER</span><span class="fl-meta">per-site</span></div>
        <div class="sw-provider-grid">
          ${provider('workersai','<span class="fl-i" data-ic="bolt"></span>','Workers AI','@cf/meta/llama-3.1-8b',
            'Runs on Cloudflare\u2019s edge. Cheapest option, fast, ideal for FAQ-style sites. Weaker on nuanced reasoning.',
            'INCLUDED', '<span class="fl-pill fl-pill--good"><span class="fl-dot"></span> Free tier</span>')}
          ${provider('haiku','<span class="fl-i" data-ic="sparkle"></span>','Claude Haiku','anthropic · via proxy',
            'Stronger comprehension and tone. Best for higher-value verticals — legal, professional services. Costs scale with traffic.',
            'USAGE-BASED', '<span class="fl-pill fl-pill--solid">Pro</span>')}
        </div>
      </div>

      <div class="sw-two" style="margin-bottom:22px">
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">RETRIEVAL MODE</span></div></div>
          <div class="sw-card-pad sw-stack-4">
            <div class="fl-seg" id="sw-retrieval" style="align-self:flex-start">
              <button data-swseg="auto" aria-selected="${state.retrieval==='auto'}">Auto</button>
              <button data-swseg="stuff" aria-selected="${state.retrieval==='stuff'}">Stuff prompt</button>
              <button data-swseg="rag" aria-selected="${state.retrieval==='rag'}">RAG</button>
            </div>
            <div class="sw-modemeter">
              <div class="lbls"><span>0</span><span style="color:var(--fl-bad)">30K crossover</span><span>50K</span></div>
              <div class="track"><span style="width:${(tokens/50000)*100}%"></span><span class="mark" style="left:60%"></span></div>
              <span class="fl-hint">Corpus is <b style="color:var(--fl-ink)">${(tokens/1000).toFixed(1)}K tokens</b> — under the crossover, so Sitewise stuffs the whole corpus into every prompt. No embeddings needed.</span>
            </div>
          </div>
        </div>
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">ANSWER STYLE</span></div></div>
          <div class="fl-rows">
            <div class="fl-row">
              <div class="fl-row-main"><div class="fl-row-title">Answer length</div><div class="fl-row-desc">How long replies run before the visitor asks for more.</div></div>
              <div class="fl-row-ctrl"><select class="fl-select" data-swset="answerLen" style="width:auto">${lenOpts.map(o => `<option ${o===state.answerLen?'selected':''}>${o}</option>`).join('')}</select></div>
            </div>
            ${toggleRow('strictCorpus','Answer only from corpus','Never invent details. Say so plainly when something isn\u2019t covered.', state.strictCorpus)}
            ${toggleRow('redirectContact','Route ambiguity to contact','When unsure, direct the visitor to the contact form instead of guessing.', state.redirectContact)}
          </div>
        </div>
      </div>

      <div class="fl-card" style="margin-bottom:22px">
        <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow">SYSTEM PROMPT</span></div><div class="sw-tokens"><span class="sw-token" data-swtoken="{site_name}">{site_name}</span><span class="sw-token" data-swtoken="{contact_url}">{contact_url}</span><span class="sw-token" data-swtoken="{corpus}">{corpus}</span></div></div>
        <div class="sw-card-pad">
          <textarea class="fl-textarea" id="sw-prompt" data-swset="prompt" style="min-height:150px">You are the on-site assistant for {site_name}. Answer only from the corpus below. If a question is not covered, say so plainly and direct the visitor to {contact_url}. Do not invent details. Do not compare {site_name} to competitors. Keep answers short (2–4 sentences) unless explicitly asked for more.

CORPUS:
{corpus}</textarea>
          <span class="fl-hint" style="margin-top:8px;display:block">Click a token above to insert it at the cursor. <code>{corpus}</code> is replaced at request time with the full text or retrieved chunks.</span>
        </div>
      </div>

      <div class="fl-card">
        <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow"><span class="fl-i" data-ic="shield" style="width:13px;height:13px"></span> ABUSE PROTECTION</span></div></div>
        <div class="fl-rows">
          ${toggleRow('rateLimitOn','Rate limit by IP','Cap messages per visitor per hour.', true, '<input class="fl-input fl-input--mono" data-swset="rateLimit" type="number" value="20" style="width:78px"/> <span class="fl-meta">/ hr</span>')}
          ${toggleRow('turnstileOn','Turnstile challenge','Show a Cloudflare Turnstile after a few messages from one visitor.', true, '<span class="fl-meta">after</span> <input class="fl-input fl-input--mono" data-swset="turnstileAfter" type="number" value="3" style="width:62px"/>')}
          ${toggleRow('maxLenOn','Cap message length','Refuse very long messages — blocks prompt-injection scrapers.', true, '<input class="fl-input fl-input--mono" data-swset="maxLen" type="number" value="1200" style="width:84px"/> <span class="fl-meta">chars</span>')}
        </div>
      </div>
    </div>`;
  }

  /* ====================================================================== */
  /*  CONNECTIONS                                                            */
  /* ====================================================================== */
  function secretField(label, key, value, hint) {
    const shown = state.revealKey[key];
    return `<div class="fl-field sw-secret" style="flex:1">
      <span class="fl-label">${label}</span>
      <input class="fl-input fl-input--mono" type="${shown ? 'text' : 'password'}" value="${value}" data-swkey="${key}" ${shown ? '' : 'readonly'}/>
      <button class="fl-btn fl-btn--ghost fl-btn--sm rev" data-swact="reveal" data-key="${key}"><span class="fl-i" data-ic="eye"></span> ${shown ? 'Hide' : 'Show'}</button>
      ${hint ? `<span class="fl-hint">${hint}</span>` : ''}
    </div>`;
  }

  function connCard({ cls, logoIcon, logoText, name, sub, statusPill, fields, meta, quota, actions }) {
    return `<div class="sw-conn">
      <div class="sw-conn-head">
        <span class="sw-conn-logo ${cls}">${logoIcon ? `<span class="fl-i" data-ic="${logoIcon}"></span>` : logoText}</span>
        <div class="sw-conn-titles"><b>${name}</b><span>${sub}</span></div>
        ${statusPill}
      </div>
      <div class="sw-conn-body">
        ${fields || ''}
        ${quota || ''}
        ${meta ? `<div class="sw-conn-meta">${meta}</div>` : ''}
        ${actions ? `<div class="fl-row-flex" style="gap:8px">${actions}</div>` : ''}
      </div>
    </div>`;
  }

  function connections() {
    const okPill = '<span class="fl-pill fl-pill--good"><span class="fl-dot"></span> Connected</span>';
    const quota = (lab, val, pct, tone) => `
      <div class="sw-quota">
        <div class="sw-quota-top"><span class="lab">${lab}</span><span class="val">${val}</span></div>
        <div class="fl-meter"><span style="width:${pct}%;${tone==='warn'?'background:var(--fl-warn)':''}"></span></div>
      </div>`;
    const metaItem = (k, v) => `<div class="m"><span class="k">${k}</span><span class="v">${v}</span></div>`;
    const testBtn = (svc) => `<button class="fl-btn fl-btn--sm" data-swact="test" data-svc="${svc}"><span class="fl-i" data-ic="bolt"></span> Test connection</button>`;

    return `<div class="wpd-section" data-screen-label="Connections">
      ${sectionHead('04','CONNECTIONS','API keys &amp; services',
        'Sitewise is hosted — we run the Worker, you bring the keys that feed it. Firecrawl crawls your pages, Search Console discovers URLs, and Cloudflare runs inference and storage.',
        '<span class="fl-pill fl-pill--good"><span class="fl-dot"></span> 3 / 3 connected</span>')}

      <div class="fl-card sw-card-pad" style="margin-bottom:16px;background:linear-gradient(0deg,var(--fl-accent-soft),var(--fl-accent-soft)),var(--fl-surface);border-color:var(--fl-accent-line)">
        <div class="sw-conn-head" style="border:0;padding:0">
          <span class="sw-conn-logo sitewise">S</span>
          <div class="sw-conn-titles"><b>Sitewise hosted Worker</b><span>SaaS mode · we run inference and storage for this site</span></div>
          <span class="fl-pill fl-pill--solid">Pro</span>
        </div>
        <div class="sw-conn-meta" style="margin-top:16px">
          ${metaItem('Site key','<span class="fl-mono">sw_live_a91f…7c2d</span> <button class="fl-btn fl-btn--ghost fl-btn--sm" data-swact="copykey" style="padding:2px 6px"><span class="fl-i" data-ic="copy"></span></button>')}
          ${metaItem('Endpoint','<span class="fl-mono">chat.sitewise.app</span>')}
          ${metaItem('Region','Western Europe (WEUR)')}
          ${metaItem('Status','<span class="sw-cstat ok"><span class="d"></span> Healthy · 42ms</span>')}
        </div>
      </div>

      <div class="sw-two">
        ${connCard({
          cls:'firecrawl', logoIcon:'feed', name:'Firecrawl', sub:'firecrawl.dev · page crawler', statusPill: okPill,
          fields: `<div class="sw-conn-keyrow">${secretField('API key','firecrawl','fc-9c4e1a77b0d24f3e8a51c6920bb74e3d','Used to crawl and clean your pages into prose.')}</div>`,
          quota: quota('Credits this month', '2,140 / 5,000', 43),
          meta: metaItem('Last checked','4m ago') + metaItem('Plan','Hobby'),
          actions: testBtn('Firecrawl') + '<button class="fl-btn fl-btn--ghost fl-btn--sm">Docs <span class="fl-i" data-ic="external"></span></button>',
        })}
        ${connCard({
          cls:'gsc', logoIcon:'search', name:'Google Search Console', sub:'URL discovery &amp; coverage', statusPill: okPill,
          fields: `<div class="fl-field"><span class="fl-label">Property</span><select class="fl-select"><option>sc-domain:shinepics.com</option><option>https://shinepics.com/</option></select></div>`,
          quota: quota('URLs discovered', '12 indexed', 100),
          meta: metaItem('Connected as','studio@shinepics.com') + metaItem('Last sync','6m ago'),
          actions: testBtn('Search Console') + '<button class="fl-btn fl-btn--ghost fl-btn--sm"><span class="fl-i" data-ic="refresh"></span> Re-auth</button>',
        })}
      </div>

      <div style="margin-top:16px">
        ${connCard({
          cls:'cloudflare', logoIcon:'cloud', name:'Cloudflare', sub:'Workers AI · Vectorize · R2 · Turnstile', statusPill:'<span class="fl-pill fl-pill--good"><span class="fl-dot"></span> Deployed</span>',
          fields: `<div class="sw-conn-keyrow">
              <div class="fl-field"><span class="fl-label">Account ID</span><input class="fl-input fl-input--mono" value="a1b2c3d4e5f60718293a4b5c6d7e8f90" readonly/></div>
              ${secretField('API token','cloudflare','cf-tok-Xy7Qm2…','Scoped to Workers, Vectorize, R2 and Turnstile.')}
            </div>`,
          quota: quota('Worker requests this month', '18,420 / 100,000', 18),
          meta: metaItem('Worker','sitewise-chat.workers.dev') + metaItem('Vectorize','<span class="sw-cstat skip"><span class="d"></span> Idle · stuff mode</span>') + metaItem('Turnstile','<span class="sw-cstat ok"><span class="d"></span> Active</span>'),
          actions: testBtn('Cloudflare') + '<button class="fl-btn fl-btn--ghost fl-btn--sm">Open dashboard <span class="fl-i" data-ic="external"></span></button>',
        })}
      </div>
    </div>`;
  }

  /* ====================================================================== */
  /*  TOOLS                                                                  */
  /* ====================================================================== */
  function tools() {
    const cfg = JSON.stringify({
      plugin:'sitewise', version:'1.0.0', mode:'hosted',
      provider: state.provider, retrieval: state.retrieval,
      widget: state.widget,
    }, null, 2);
    return `<div class="wpd-section" data-screen-label="Tools">
      ${sectionHead('05','TOOLS','Import, export &amp; reset',
        'Move a Sitewise configuration between sites, force a full re-crawl, or roll everything back to defaults.', '')}
      <div class="wpd-tools-grid">
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow"><span class="fl-i" data-ic="download" style="width:13px;height:13px"></span> EXPORT</span></div><button class="fl-btn fl-btn--sm" data-swact="copy"><span class="fl-i" data-ic="code"></span> Copy</button></div>
          <div class="fl-card-pad"><p class="fl-row-desc" style="margin:0 0 10px">Settings &amp; widget skin as portable JSON (keys excluded).</p><textarea class="fl-textarea" id="sw-export" readonly style="min-height:150px">${cfg}</textarea></div>
        </div>
        <div class="fl-card">
          <div class="fl-card-head"><div class="fl-card-title"><span class="fl-eyebrow"><span class="fl-i" data-ic="upload" style="width:13px;height:13px"></span> IMPORT</span></div></div>
          <div class="fl-card-pad"><p class="fl-row-desc" style="margin:0 0 10px">Paste a Sitewise configuration and apply it.</p><textarea class="fl-textarea" placeholder="Paste Sitewise JSON…" style="min-height:150px"></textarea><div class="demo" style="margin-top:12px"><button class="fl-btn fl-btn--primary fl-btn--sm" data-swact="import"><span class="fl-i" data-ic="upload"></span> Apply import</button></div></div>
        </div>
      </div>
      <div class="fl-banner fl-banner--accent" style="margin-top:16px">
        <span class="fl-i" data-ic="refresh" style="color:var(--fl-accent)"></span>
        <div class="fl-banner-body"><div class="fl-banner-title">Force a full re-crawl</div><div class="fl-banner-desc">Re-fetch every page with Firecrawl and rebuild the corpus from scratch.</div></div>
        <button class="fl-btn fl-btn--sm" data-swact="rebuild"><span class="fl-i" data-ic="refresh"></span> Rebuild corpus</button>
      </div>
      <div class="fl-banner fl-banner--warn" style="margin-top:12px">
        <span class="fl-i" data-ic="warn" style="color:var(--fl-warn)"></span>
        <div class="fl-banner-body"><div class="fl-banner-title">Reset all settings</div><div class="fl-banner-desc">Restores Sitewise options and widget skin to defaults. Connections are kept.</div></div>
        <button class="fl-btn fl-btn--danger fl-btn--sm" data-swact="reset"><span class="fl-i" data-ic="refresh"></span> Reset to defaults</button>
      </div>
    </div>`;
  }

  /* ---- section dispatch --------------------------------------------------- */
  SW.renderSection = function (id) {
    if (id === 'dashboard') return dashboard();
    if (id === 'corpus') return corpus();
    if (id === 'widget') return SW.widgetSection ? SW.widgetSection() : '<div class="wpd-section">Widget module not loaded.</div>';
    if (id === 'behavior') return behavior();
    if (id === 'connections') return connections();
    if (id === 'tools') return tools();
    return dashboard();
  };

  function tabsHTML() {
    return `<div class="fl-tabs">${SW.NAV.map(s =>
      `<button class="fl-tab" data-swnav="${s.id}" aria-selected="${state.section === s.id}"><span class="fl-i" data-ic="${s.icon}" style="width:14px;height:14px"></span> ${s.label}</button>`
    ).join('')}</div>`;
  }

  SW.paintIcons = function (root) {
    (root || document).querySelectorAll('[data-ic]').forEach(el => { el.innerHTML = ICON(el.getAttribute('data-ic')); });
  };

  /* ---- mount / render ----------------------------------------------------- */
  SW.mount = function () {
    SW.addIcons();
    const root = $('#wpd');
    root.setAttribute('data-accent', 'green');
    $('#wpd-nav').hidden = true;
    const tabs = $('#wpd-tabsbar'); tabs.hidden = false;
    tabs.innerHTML = tabsHTML();
    SW.render();
    SW.wireOnce();
  };

  SW.render = function () {
    const main = $('#wpd-main');
    main.innerHTML = SW.renderSection(state.section);
    $('#wpd-tabsbar').innerHTML = tabsHTML();
    SW.paintIcons($('#wpd'));
    if (state.section === 'widget' && SW.wireWidget) SW.wireWidget();
    SW.updateBar();
  };

  SW.goTo = function (id) { state.section = id; SW.render(); $('#wpd-main').scrollTop = 0; };

  /* ---- top-bar indicators ------------------------------------------------- */
  SW.updateBar = function () {
    const dirty = state.dirty;
    const d = $('#wpd-dirty'); if (d) d.hidden = !dirty;
    const save = $('#wpd-save'); if (save) save.disabled = !dirty;
    const note = $('#wpd-saved-note'); if (note) note.hidden = dirty || !state.saved;
  };
  SW.markDirty = function () { state.dirty = true; SW.updateBar(); };
  SW.save = function () { state.dirty = false; state.saved = Date.now(); SW.updateBar(); window.WPD && WPD.toast && WPD.toast('Sitewise settings saved · Worker updated'); };
  SW.resetAll = function () { window.WPD && WPD.toast && WPD.toast('Sitewise reset to defaults'); state.dirty = false; SW.updateBar(); };

  /* ---- rebuild animation -------------------------------------------------- */
  SW.runRebuild = function () {
    if (state.rebuilding) return;
    state.rebuilding = true;
    if (state.section === 'corpus') SW.render();
    window.WPD && WPD.toast && WPD.toast('Rebuilding corpus with Firecrawl…');
    let pct = 8;
    const tick = setInterval(() => {
      pct = Math.min(100, pct + Math.round(6 + Math.random() * 14));
      const meter = $('#sw-rebuild-meter span'); const lab = $('#sw-rebuild-pct');
      if (meter) meter.style.width = pct + '%';
      if (lab) lab.textContent = pct + '%';
      if (pct >= 100) {
        clearInterval(tick);
        setTimeout(() => { state.rebuilding = false; if (state.section === 'corpus') SW.render(); window.WPD && WPD.toast && WPD.toast('Corpus rebuilt · 12 pages · 10.4K tokens'); }, 450);
      }
    }, 420);
  };

  /* ---- wiring (once; gated to active plugin) ------------------------------ */
  SW.wireOnce = function () {
    if (SW._wired) return; SW._wired = true;
    const root = $('#wpd');
    const active = () => window.__activePlugin === 'sitewise';

    root.addEventListener('click', (e) => {
      if (!active()) return;
      const nav = e.target.closest('[data-swnav]'); if (nav) { SW.goTo(nav.getAttribute('data-swnav')); return; }
      const tok = e.target.closest('[data-swtoken]'); if (tok) { insertToken(tok.getAttribute('data-swtoken')); return; }
      const prov = e.target.closest('[data-swprovider]'); if (prov) { state.provider = prov.getAttribute('data-swprovider'); SW.markDirty(); SW.render(); return; }
      const seg = e.target.closest('[data-swseg]'); if (seg) { state.retrieval = seg.getAttribute('data-swseg'); SW.markDirty(); SW.render(); return; }
      const act = e.target.closest('[data-swact]'); if (act) { doAct(act.getAttribute('data-swact'), act); return; }
    });

    root.addEventListener('change', (e) => {
      if (!active()) return;
      const t = e.target;
      if (t.matches('[data-swset]')) {
        const k = t.getAttribute('data-swset');
        state[k] = t.type === 'checkbox' ? t.checked : t.value;
        if (k === 'answerLen') { SW.markDirty(); return; }
        SW.markDirty();
        // re-render toggle rows so is-on class tracks
        if (t.type === 'checkbox' && t.closest('.fl-row')) t.closest('.fl-row').classList.toggle('is-on', t.checked);
      }
    });
    root.addEventListener('input', (e) => {
      if (!active()) return;
      if (e.target.matches('[data-swset]')) SW.markDirty();
    });
  };

  function insertToken(tok) {
    const ta = $('#sw-prompt'); if (!ta) return;
    const s = ta.selectionStart || ta.value.length, en = ta.selectionEnd || s;
    ta.value = ta.value.slice(0, s) + tok + ta.value.slice(en);
    ta.focus(); ta.selectionStart = ta.selectionEnd = s + tok.length;
    SW.markDirty();
  }

  function doAct(a, el) {
    const T = (m) => window.WPD && WPD.toast && WPD.toast(m);
    if (a === 'rebuild') return SW.runRebuild();
    if (a === 'reveal') { const k = el.getAttribute('data-key'); state.revealKey[k] = !state.revealKey[k]; SW.render(); return; }
    if (a === 'test') { const svc = el.getAttribute('data-svc'); T('Testing ' + svc + '… connection OK'); return; }
    if (a === 'copy') { const ta = $('#sw-export'); if (ta) { ta.select(); try { navigator.clipboard.writeText(ta.value); } catch (e) {} } T('Configuration copied'); return; }
    if (a === 'copykey') { try { navigator.clipboard.writeText('sw_live_a91f7c2d'); } catch (e) {} T('Site key copied'); return; }
    if (a === 'export-corpus') { T('Opening llms.txt in a new tab…'); return; }
    if (a === 'import') { T('Import applied — review and save'); SW.markDirty(); return; }
    if (a === 'reset') { SW.resetAll(); SW.render(); return; }
  }
})();

/* ===== design bundle: widget section (fda1df8c) ===== */
/* ============================================================================
   SITEWISE — Widget screen.  Skinning controls (left) + live, clickable
   preview (right). The launcher opens the panel and a canned conversation
   plays. Attaches to window.SW. Load after sitewise.js.
   ============================================================================ */
(function () {
  const SW = (window.SW = window.SW || {});
  const $ = (s, r) => (r || document).querySelector(s);

  const ACCENTS = [
    { name:'Forest',     hex:'#1f7a3c' },
    { name:'Ocean',      hex:'#1f6f8b' },
    { name:'Indigo',     hex:'#4457c9' },
    { name:'Plum',       hex:'#7a3e74' },
    { name:'Terracotta', hex:'#b85c38' },
    { name:'Slate',      hex:'#475569' },
  ];

  // canned conversation (grounded answers + the "route to contact" pattern)
  const SCRIPT = [
    { who:'user', text:'Do you photograph destination weddings?' },
    { who:'bot',  text:'Yes — we shoot UK-wide and travel for destination weddings. Anything beyond about two hours from the Lake District is quoted per booking to cover travel and an overnight stay.' },
    { who:'user', text:'How much for a 200-guest wedding in June?' },
    { who:'bot',  text:'Pricing depends on hours of coverage and travel, so I can\u2019t give an exact figure here. The quickest way to get an accurate quote is to send your date and venue through our contact form.', cta:'Get a quote' },
  ];

  function esc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  /* ---- the widget markup (rebuilt on theme/skin change) ------------------- */
  SW.widgetMarkup = function () {
    const w = SW.state.widget;
    const wtheme = w.theme === 'auto' ? (document.documentElement.getAttribute('data-theme') || 'light') : w.theme;
    return `<div class="sww-root" id="sww" data-pos="${w.pos}" data-shape="${w.shape}" data-wtheme="${wtheme}" style="--sww-accent:${w.accent}">
      <div class="sww-panel">
        <div class="sww-head">
          <span class="sww-ava"><span class="fl-i" data-ic="comment"></span></span>
          <div class="sww-head-t"><b id="sww-name">${esc(w.botName)}</b><span><span class="d"></span> ${esc(w.subtitle)}</span></div>
          <button class="sww-head x" data-sww="close"><span class="fl-i" data-ic="x"></span></button>
        </div>
        <div class="sww-thread" id="sww-thread"></div>
        <div class="sww-composer">
          <input id="sww-input" placeholder="${esc(w.placeholder)}" />
          <button class="sww-send" data-sww="send"><span class="fl-i" data-ic="send"></span></button>
        </div>
        ${w.powered ? '<div class="sww-powered">Powered by <b>Sitewise</b></div>' : ''}
      </div>
      <button class="sww-launch" data-sww="toggle">
        <span class="fl-i ic-chat" data-ic="comment"></span>
        <span class="fl-i ic-close" data-ic="x"></span>
        <span class="lab">Ask a question</span>
      </button>
    </div>`;
  };

  /* ---- screen ------------------------------------------------------------- */
  SW.widgetSection = function () {
    const w = SW.state.widget;
    const swatches = ACCENTS.map(a =>
      `<button class="sw-swatch ${a.accent === w.accent || a.hex === w.accent ? 'is-sel' : ''}" data-sww-accent="${a.hex}" title="${a.name}" style="background:${a.hex}"></button>`
    ).join('');
    const corner = ['tl','tr','bl','br'].map(p =>
      `<button data-sww-pos="${p}" class="${w.pos === p ? 'is-sel' : ''}" aria-label="${p}"></button>`
    ).join('');
    const shapes = ['circle','rounded','pill'].map(s =>
      `<button class="sw-shape ${w.shape === s ? 'is-sel' : ''}" data-sww-shape="${s}"><span class="g"></span></button>`
    ).join('');
    const themeSeg = ['light','dark','auto'].map(t =>
      `<button data-sww-theme="${t}" aria-selected="${w.theme === t}">${t[0].toUpperCase() + t.slice(1)}</button>`
    ).join('');

    return `<div class="wpd-section" data-screen-label="Widget" style="max-width:1120px">
      <div class="wpd-section-head">
        <div class="fl-stack" style="gap:7px">
          <span class="fl-eyebrow"><span class="fl-num">02</span> — WIDGET</span>
          <h2 class="fl-h1" style="font-size:24px">Chat widget</h2>
          <p class="fl-lead" style="max-width:560px">Skin the embeddable widget and watch it update live. One vanilla-JS file, ~10&nbsp;KB, dropped in by block or <code>[sitewise]</code> shortcode.</p>
        </div>
        <div class="wpd-section-meta"><span class="fl-pill"><span class="fl-dot" style="background:var(--fl-accent)"></span> Embedded · 1 site</span></div>
      </div>

      <div class="sw-widget-grid">
        <!-- CONTROLS -->
        <div class="sw-widget-controls">
          <div class="sw-ctl">
            <div class="sw-ctl-head"><span class="fl-eyebrow">DISPLAY</span></div>
            <div class="sw-ctl-body">
              <div class="sw-ctl-row">
                <span class="fl-label">Front-end widget</span>
                <div class="fl-seg" id="sww-mode" style="align-self:flex-start">
                  <button data-sww-mode="callback" aria-selected="${w.frontendMode === 'callback'}">Call-back form</button>
                  <button data-sww-mode="chat" aria-selected="${w.frontendMode === 'chat'}">Chat assistant</button>
                </div>
                <span class="fl-hint">Chat needs a connected Worker (Connections tab). Until then, keep Call-back.</span>
              </div>
              <div class="fl-row-flex" style="justify-content:space-between;gap:12px;margin-top:6px">
                <div class="fl-row-main"><div class="fl-row-title">Show floating launcher site-wide</div><div class="fl-row-desc">Auto-adds the bubble to every page. Off = it only appears where you place the <code>[sitewise]</code> shortcode.</div></div>
                <label class="fl-switch"><input type="checkbox" id="sww-f-autoinject" ${w.autoInject ? 'checked' : ''}/><span class="fl-track"></span><span class="fl-thumb"></span></label>
              </div>
            </div>
          </div>

          <div class="sw-ctl">
            <div class="sw-ctl-head"><span class="fl-eyebrow">BRAND COLOUR</span></div>
            <div class="sw-ctl-body">
              <div class="sw-ctl-row"><span class="fl-label">Accent</span><div class="sw-swatches">${swatches}</div></div>
            </div>
          </div>

          <div class="sw-ctl">
            <div class="sw-ctl-head"><span class="fl-eyebrow">LAUNCHER</span></div>
            <div class="sw-ctl-body">
              <div class="fl-row-flex" style="gap:26px;align-items:flex-start;flex-wrap:wrap">
                <div class="sw-ctl-row"><span class="fl-label">Position</span><div class="sw-corner" id="sww-corner">${corner}</div></div>
                <div class="sw-ctl-row"><span class="fl-label">Shape</span><div class="sw-shapes">${shapes}</div></div>
              </div>
            </div>
          </div>

          <div class="sw-ctl">
            <div class="sw-ctl-head"><span class="fl-eyebrow">THEME</span></div>
            <div class="sw-ctl-body">
              <div class="sw-ctl-row"><span class="fl-label">Appearance</span><div class="fl-seg" id="sww-theme" style="align-self:flex-start">${themeSeg}</div><span class="fl-hint">Auto follows the visitor\u2019s system preference.</span></div>
            </div>
          </div>

          <div class="sw-ctl">
            <div class="sw-ctl-head"><span class="fl-eyebrow">COPY</span></div>
            <div class="sw-ctl-body">
              <div class="fl-field"><span class="fl-label">Bot name</span><input class="fl-input" id="sww-f-botName" value="${esc(w.botName)}" /></div>
              <div class="fl-field"><span class="fl-label">Header subtitle</span><input class="fl-input" id="sww-f-subtitle" value="${esc(w.subtitle)}" /></div>
              <div class="fl-field"><span class="fl-label">Opening message</span><textarea class="fl-textarea" id="sww-f-opening" style="min-height:70px;font-family:var(--fl-sans);font-size:12.5px">${esc(w.opening)}</textarea></div>
              <div class="fl-field"><span class="fl-label">Input placeholder</span><input class="fl-input" id="sww-f-placeholder" value="${esc(w.placeholder)}" /></div>
              <div class="fl-row-flex" style="justify-content:space-between;gap:12px">
                <div class="fl-row-main"><div class="fl-row-title">Show “Powered by Sitewise”</div></div>
                <label class="fl-switch"><input type="checkbox" id="sww-f-powered" ${w.powered ? 'checked' : ''}/><span class="fl-track"></span><span class="fl-thumb"></span></label>
              </div>
            </div>
          </div>
        </div>

        <!-- PREVIEW -->
        <div class="sw-preview-col">
          <div class="sw-preview-bar">
            <span class="fl-eyebrow">LIVE PREVIEW</span>
            <span class="fl-meta">click the launcher</span>
            <div class="grow"></div>
            <button class="fl-btn fl-btn--ghost fl-btn--sm" data-sww="replay"><span class="fl-i" data-ic="refresh"></span> Replay</button>
          </div>
          <div class="swp-frame" id="swp-frame">
            <div class="swp-chrome"><span class="swp-dots"><i></i><i></i><i></i></span><span class="swp-addr">shinepics.com</span></div>
            <div class="swp-page">
              <div class="swp-hero"><span>hero image</span></div>
              <div class="swp-lines"><i></i><i></i><i></i><i></i><i></i></div>
              <div class="swp-grid"><i></i><i></i><i></i></div>
            </div>
            ${SW.widgetMarkup()}
          </div>
        </div>
      </div>
    </div>`;
  };

  /* ---- live wiring -------------------------------------------------------- */
  let playTimers = [];
  function clearPlay() { playTimers.forEach(clearTimeout); playTimers = []; }

  function rebuildWidget() {
    const frame = $('#swp-frame'); if (!frame) return;
    const old = $('#sww'); const wasOpen = old && old.classList.contains('is-open');
    if (old) old.remove();
    frame.insertAdjacentHTML('beforeend', SW.widgetMarkup());
    SW.paintIcons(frame);
    if (wasOpen) { $('#sww').classList.add('is-open'); seedThread(); }
  }

  function seedThread() {
    const thread = $('#sww-thread'); if (!thread) return;
    thread.innerHTML = `<div class="sww-msg bot">${esc(SW.state.widget.opening)}</div>`;
  }

  function addMsg(m) {
    const thread = $('#sww-thread'); if (!thread) return;
    const cta = m.cta ? `<a class="cta" href="#">${esc(m.cta)} <span class="fl-i" data-ic="send"></span></a>` : '';
    const el = document.createElement('div');
    el.className = 'sww-msg ' + (m.who === 'user' ? 'user' : 'bot');
    el.innerHTML = esc(m.text) + cta;
    thread.appendChild(el);
    SW.paintIcons(el);
    thread.scrollTop = thread.scrollHeight;
  }

  function showTyping() {
    const thread = $('#sww-thread'); if (!thread) return null;
    const t = document.createElement('div');
    t.className = 'sww-typing'; t.innerHTML = '<i></i><i></i><i></i>';
    thread.appendChild(t); thread.scrollTop = thread.scrollHeight;
    return t;
  }

  function playConversation() {
    clearPlay();
    seedThread();
    let delay = 650;
    SCRIPT.forEach((m) => {
      if (m.who === 'user') {
        playTimers.push(setTimeout(() => addMsg(m), delay));
        delay += 850;
      } else {
        const typing = delay;
        playTimers.push(setTimeout(() => { const t = showTyping(); playTimers.push(setTimeout(() => { if (t) t.remove(); addMsg(m); }, 1150)); }, typing));
        delay += 1150 + 1400;
      }
    });
  }

  function openWidget() {
    const root = $('#sww'); if (!root) return;
    root.classList.add('is-open');
    playConversation();
  }
  function closeWidget() {
    const root = $('#sww'); if (!root) return;
    root.classList.remove('is-open');
    clearPlay();
  }

  SW.wireWidget = function () {
    if (SW._widgetWired) { return; }
    SW._widgetWired = true;
    const active = () => window.__activePlugin === 'sitewise' && SW.state.section === 'widget';
    const root = $('#wpd');

    root.addEventListener('click', (e) => {
      if (!active()) return;
      const sww = e.target.closest('[data-sww]');
      if (sww) {
        const a = sww.getAttribute('data-sww');
        if (a === 'toggle') { $('#sww').classList.contains('is-open') ? closeWidget() : openWidget(); }
        else if (a === 'close') closeWidget();
        else if (a === 'replay') { openWidget(); }
        else if (a === 'send') { /* canned: nudge replay */ openWidget(); }
        return;
      }
      const acc = e.target.closest('[data-sww-accent]');
      if (acc) { SW.state.widget.accent = acc.getAttribute('data-sww-accent'); markSkin(); document.querySelectorAll('[data-sww-accent]').forEach(b => b.classList.toggle('is-sel', b === acc)); applyAccent(); return; }
      const pos = e.target.closest('[data-sww-pos]');
      if (pos) { SW.state.widget.pos = pos.getAttribute('data-sww-pos'); markSkin(); document.querySelectorAll('[data-sww-pos]').forEach(b => b.classList.toggle('is-sel', b === pos)); const r = $('#sww'); if (r) r.setAttribute('data-pos', SW.state.widget.pos); return; }
      const shp = e.target.closest('[data-sww-shape]');
      if (shp) { SW.state.widget.shape = shp.getAttribute('data-sww-shape'); markSkin(); document.querySelectorAll('[data-sww-shape]').forEach(b => b.classList.toggle('is-sel', b === shp)); const r = $('#sww'); if (r) r.setAttribute('data-shape', SW.state.widget.shape); return; }
      const th = e.target.closest('[data-sww-theme]');
      if (th) { SW.state.widget.theme = th.getAttribute('data-sww-theme'); markSkin(); document.querySelectorAll('[data-sww-theme]').forEach(b => b.setAttribute('aria-selected', b === th)); rebuildWidget(); return; }
      const md = e.target.closest('[data-sww-mode]');
      if (md) { SW.state.widget.frontendMode = md.getAttribute('data-sww-mode'); markSkin(); document.querySelectorAll('[data-sww-mode]').forEach(b => b.setAttribute('aria-selected', b === md)); return; }
    });

    root.addEventListener('input', (e) => {
      if (!active()) return;
      const id = e.target.id;
      if (id === 'sww-f-botName') { SW.state.widget.botName = e.target.value; const n = $('#sww-name'); if (n) n.textContent = e.target.value; markSkin(); }
      else if (id === 'sww-f-subtitle') { SW.state.widget.subtitle = e.target.value; const s = $('#sww .sww-head-t span'); if (s) s.innerHTML = '<span class="d"></span> ' + esc(e.target.value); markSkin(); }
      else if (id === 'sww-f-opening') { SW.state.widget.opening = e.target.value; markSkin(); const open = $('#sww') && $('#sww').classList.contains('is-open'); if (!open) seedThread(); }
      else if (id === 'sww-f-placeholder') { SW.state.widget.placeholder = e.target.value; const inp = $('#sww-input'); if (inp) inp.placeholder = e.target.value; markSkin(); }
    });
    root.addEventListener('change', (e) => {
      if (!active()) return;
      if (e.target.id === 'sww-f-powered') { SW.state.widget.powered = e.target.checked; markSkin(); rebuildWidget(); }
      else if (e.target.id === 'sww-f-autoinject') { SW.state.widget.autoInject = e.target.checked; markSkin(); }
    });
  };

  function applyAccent() { const r = $('#sww'); if (r) r.style.setProperty('--sww-accent', SW.state.widget.accent); }
  function markSkin() { SW.markDirty && SW.markDirty(); }

  // seed the closed thread once when the screen first renders
  const origRender = SW.render;
  SW.render = function () {
    origRender.apply(this, arguments);
    if (SW.state.section === 'widget') { SW.paintIcons($('#swp-frame')); seedThread(); }
  };
})();

/* ===== boot shim: real data + WP wiring + frame registration ===== */
(function () {
  if (!window.SW) return;
  var D = window.SitewiseData || {};
  SW.real = D;
  if (Array.isArray(D.crawl)) SW.crawlRows = D.crawl;
  SW.logRows = Array.isArray(D.logRows) ? D.logRows : [];          // honest empty until logging exists
  if (D.state && typeof D.state === 'object') Object.assign(SW.state, D.state);

  function post(action, payload, cb) {
    if (!D.ajaxUrl || !action) { cb && cb({ success: false }); return; }
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', D.nonce || '');
    if (payload) body.append('data', JSON.stringify(payload));
    fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb && cb(j); })
      .catch(function () { cb && cb({ success: false }); });
  }

  var A = D.actions || {};

  SW.save = function () {
    post(A.save, SW.state, function (j) {
      if (j && j.success) {
        // No toast — the Save button greys out (disabled) and the "✓ Saved"
        // chip appears via updateBar; the button itself is the confirmation.
        SW.state.dirty = false; SW.state.saved = Date.now(); SW.updateBar();
      } else {
        // Keep it dirty so the (green, clickable) button invites a retry.
        window.WPD.toast('Save failed — please try again');
      }
    });
  };

  SW.resetAll = function () {
    post(A.reset, null, function (j) {
      window.WPD.toast(j && j.success ? 'Reset to defaults' : 'Reset failed');
      if (j && j.success) { setTimeout(function () { location.reload(); }, 600); }
    });
  };

  SW.runRebuild = function () {
    if (SW.state.rebuilding) return;
    SW.state.rebuilding = true;
    if (SW.state.section === 'corpus') SW.render();
    window.WPD.toast('Rebuilding corpus…');
    post(A.rebuild, null, function (j) {
      SW.state.rebuilding = false;
      if (j && j.real) { SW.real = j.real; if (Array.isArray(j.real.crawl)) SW.crawlRows = j.real.crawl; }
      if (SW.state.section === 'corpus' || SW.state.section === 'dashboard') SW.render();
      window.WPD.toast(j && j.success ? 'Corpus rebuilt' : 'Rebuild failed');
    });
  };

  if (window.Folium && window.Folium.registerApp) {
    window.Folium.registerApp('sitewise', {
      mount: function () { SW.mount(); },
      save:  function () { SW.save(); },
      reset: function () { SW.resetAll(); }
    });
  }
})();
