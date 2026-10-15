/* GA4 Consolidated Web Properties View — front end (no dependencies). MIT License. */
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const app = $('#app');
  const tip = $('#tip');
  const REFRESH = (parseInt(document.body.dataset.refresh, 10) || 30) * 1000;

  const S = {
    days: 28, route: { view: 'all' }, props: [],
    rt: null, sum: null, prop: null, demo: false,
    metric: 'activeUsers', hourMetric: 'users',
    sort: { key: 'activeUsers', asc: false },
    lastOk: 0, timers: [], charts: [],
  };
  try { S.days = parseInt(localStorage.getItem('gadash.days'), 10) || 28; } catch (e) {}

  /* ------------------------------------------------------------ format */
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = n => Math.round(n || 0).toLocaleString();
  const compact = n => {
    n = Math.round(n || 0);
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1) + 'M';
    if (Math.abs(n) >= 1e4) return (n / 1e3).toFixed(n >= 1e5 ? 0 : 1) + 'K';
    return n.toLocaleString();
  };
  const pct = r => ((r || 0) * 100).toFixed(1) + '%';
  const dur = s => { s = Math.round(s || 0); const m = Math.floor(s / 60); return m ? `${m}m ${String(s % 60).padStart(2, '0')}s` : `${s}s`; };
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const dlabel = d => { const s = String(d); return `${MONTHS[+s.slice(4, 6) - 1]} ${+s.slice(6, 8)}`; };
  const hlabel = h => (h % 12 || 12) + (h < 12 ? 'am' : 'pm');
  const delta = (cur, prev) => {
    if (!prev) return '<span class="muted">—</span>';
    const d = (cur - prev) / prev;
    if (Math.abs(d) < 0.0005) return '<span class="muted">0.0%</span>';
    return `<span class="${d > 0 ? 'up' : 'down'}">${d > 0 ? '↑' : '↓'} ${Math.abs(d * 100).toFixed(1)}%</span>`;
  };
  const METRICS = {
    activeUsers: { label: 'Users', f: num },
    newUsers: { label: 'New users', f: num },
    sessions: { label: 'Sessions', f: num },
    screenPageViews: { label: 'Views', f: num },
    engagementRate: { label: 'Engagement rate', f: pct },
    avgEngagementTime: { label: 'Avg engagement time', f: dur },
  };
  const gaLink = id => `https://analytics.google.com/analytics/web/#/p${encodeURIComponent(id)}/reports/intelligenthome`;
  const site = name => esc(String(name).toLowerCase());

  /* ------------------------------------------------------------ api */
  async function api(action, params = {}) {
    const q = new URLSearchParams({ action, ...params });
    const r = await fetch('api.php?' + q, { credentials: 'same-origin', cache: 'no-store' });
    if (r.status === 401) { location.reload(); throw new Error('signed out'); }
    const j = await r.json().catch(() => ({ error: 'Bad response from server (HTTP ' + r.status + ')' }));
    if (!r.ok || j.error) throw new Error(j.error || 'HTTP ' + r.status);
    S.demo = !!(j.meta && j.meta.demo);
    return j;
  }

  /* ------------------------------------------------------------ charts */
  const svgNS = 'http://www.w3.org/2000/svg';
  function niceMax(v) {
    if (v <= 0) return 4;
    const raw = v / 4, p = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map(m => m * p).find(s => s >= raw);
    return step * 4;
  }
  function barPath(x, y, w, h, r) {
    r = Math.min(r, w / 2, h);
    if (h <= 0) return '';
    return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
  }

  /**
   * opts: { type:'line'|'bar', labels:[], tipLabels:[], series:[{name, values, color, dashed, area}],
   *         fmt, height, xEvery }
   */
  function drawChart(el, opts) {
    const draw = () => {
      const W = Math.max(240, el.clientWidth), H = opts.height || 220;
      const L = 44, R = 8, T = 8, B = 24;
      const iw = W - L - R, ih = H - T - B;
      const n = opts.labels.length;
      const all = opts.series.flatMap(s => s.values.filter(v => v != null));
      const max = niceMax(Math.max(0, ...all));
      const y = v => T + ih - (v / max) * ih;
      const band = iw / Math.max(1, n);
      const xc = i => opts.type === 'bar' ? L + band * i + band / 2 : L + (n <= 1 ? iw / 2 : (iw * i) / (n - 1));
      const fmt = opts.fmt || num;
      let g = '';
      for (let k = 0; k <= 4; k++) {
        const v = (max / 4) * k, yy = y(v);
        g += `<line class="${k ? 'gl' : 'bl'}" x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}"/>`;
        g += `<text x="${L - 8}" y="${yy + 3.5}" text-anchor="end">${esc(opts.yFmt ? opts.yFmt(v) : compact(v))}</text>`;
      }
      const auto = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 56))));
      const every = opts.xEvery ? opts.xEvery * Math.ceil(auto / opts.xEvery) : auto;
      for (let i = 0; i < n; i++) {
        if (i % every !== 0 && i !== n - 1) continue;
        if (i !== n - 1 && n - 1 - i < every * 0.6) continue; // avoid collisions with last label
        const anchor = i === 0 && opts.type !== 'bar' ? 'start' : i === n - 1 && opts.type !== 'bar' ? 'end' : 'middle';
        g += `<text x="${xc(i)}" y="${H - 6}" text-anchor="${anchor}">${esc(opts.labels[i])}</text>`;
      }
      let marks = '';
      opts.series.forEach((s, si) => {
        if (opts.type === 'bar') {
          const bw = Math.max(2, Math.min(18, band - 2));
          s.values.forEach((v, i) => {
            if (!v) return;
            marks += `<path class="bar" data-i="${i}" d="${barPath(xc(i) - bw / 2, y(v), bw, T + ih - y(v), 4)}"/>`;
          });
        } else {
          let d = '', open = false, area = '', first = null, last = null;
          s.values.forEach((v, i) => {
            if (v == null) { open = false; return; }
            d += (open ? 'L' : 'M') + xc(i).toFixed(1) + ',' + y(v).toFixed(1);
            if (first == null) first = i; last = i; open = true;
          });
          if (s.area && first != null) {
            area = `<path class="area" fill="var(--s1-soft)" d="${d}L${xc(last)},${T + ih}L${xc(first)},${T + ih}Z"/>`;
          }
          marks += area + `<path class="ln" stroke="${s.color}" ${s.dashed ? 'stroke-dasharray="5 4"' : ''} d="${d}"/>`;
        }
      });
      el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" height="${H}" role="img" aria-label="${esc(opts.aria || 'chart')}">${g}${marks}<g class="hover"></g><rect x="${L}" y="${T}" width="${iw}" height="${ih}" fill="transparent"/></svg>`;
      const svg = el.firstChild, hov = svg.querySelector('.hover'), hit = svg.lastChild;
      const move = ev => {
        const r = svg.getBoundingClientRect();
        const px = (ev.clientX - r.left) * (W / r.width);
        let i = opts.type === 'bar' ? Math.floor((px - L) / band) : Math.round(((px - L) / iw) * (n - 1));
        i = Math.max(0, Math.min(n - 1, i));
        let h = `<line class="xh" x1="${xc(i)}" x2="${xc(i)}" y1="${T}" y2="${T + ih}"/>`;
        if (opts.type !== 'bar') opts.series.forEach(s => {
          const v = s.values[i];
          if (v != null) h += `<circle class="pt" cx="${xc(i)}" cy="${y(v)}" r="4" fill="${s.color}"/>`;
        });
        hov.innerHTML = h;
        svg.querySelectorAll('.bar').forEach(b => b.classList.toggle('dim', +b.dataset.i !== i));
        const rows = (opts.tipRows ? opts.tipRows(i) : opts.series.map(s => [s.name, s.values[i] == null ? '—' : fmt(s.values[i]), s.color]))
          .map(([k, v, c]) => `<div class="r"><span>${c ? `<i class="sw" style="background:${c}"></i>` : ''}${esc(k)}</span><b>${esc(v)}</b></div>`).join('');
        showTip(ev, `<div class="t">${esc((opts.tipLabels || opts.labels)[i])}</div>${rows}`);
      };
      const leave = () => { hov.innerHTML = ''; hideTip(); svg.querySelectorAll('.bar').forEach(b => b.classList.remove('dim')); };
      hit.addEventListener('mousemove', move);
      hit.addEventListener('mouseleave', leave);
      hit.addEventListener('touchstart', e => move(e.touches[0]), { passive: true });
      hit.addEventListener('touchend', leave);
    };
    draw();
    S.charts.push(draw);
  }

  function spark(values, w = 96, h = 22) {
    const v = values.map(x => x || 0), max = Math.max(1, ...v), n = v.length;
    if (!n) return '';
    const d = v.map((x, i) => `${i ? 'L' : 'M'}${((i / Math.max(1, n - 1)) * (w - 2) + 1).toFixed(1)},${(h - 2 - (x / max) * (h - 4)).toFixed(1)}`).join('');
    return `<svg class="spark" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="${d}"/></svg>`;
  }

  function showTip(ev, html) {
    tip.innerHTML = html; tip.hidden = false;
    const pad = 14, tw = tip.offsetWidth, th = tip.offsetHeight;
    let x = ev.clientX + pad, yy = ev.clientY + pad;
    if (x + tw > innerWidth - 8) x = ev.clientX - tw - pad;
    if (yy + th > innerHeight - 8) yy = ev.clientY - th - pad;
    tip.style.left = Math.max(8, x) + 'px'; tip.style.top = Math.max(8, yy) + 'px';
  }
  const hideTip = () => { tip.hidden = true; };
  let rz;
  addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => S.charts.forEach(f => f()), 120); });

  /* ------------------------------------------------------------ pieces */
  const section = (title, sub = '', right = '') =>
    `<div class="section-title"><div><h2>${title}</h2>${sub ? `<div class="sub">${sub}</div>` : ''}</div>${right}</div>`;

  const hlist = (items, valKey, nameFn, valFn) => {
    if (!items || !items.length) return '<div class="empty">No data</div>';
    const max = Math.max(1, ...items.map(x => x[valKey] || 0));
    return '<ul class="hlist">' + items.map(x =>
      `<li><span class="n" title="${esc(nameFn(x))}">${esc(nameFn(x))}</span><b>${(valFn || num)(x[valKey])}</b><span class="b"><span style="width:${((x[valKey] || 0) / max) * 100}%"></span></span></li>`).join('') + '</ul>';
  };

  const DEV_COLORS = ['var(--s1)', '#eb6834', '#1baf7a'];
  const devices = list => {
    if (!list || !list.length) return '<div class="empty">No data</div>';
    const tot = list.reduce((a, b) => a + (b.users || 0), 0) || 1;
    const top = list.slice(0, 3);
    return `<div class="devices">${top.map(d => `<span style="flex:${d.users}"></span>`).join('')}</div>
      <div class="devkeys">${top.map((d, i) => `<span><i style="background:${DEV_COLORS[i]}"></i>${esc(d.name)} <b>${pct(d.users / tot)}</b></span>`).join('')}</div>`;
  };

  function realtimeBlock(rt, single) {
    const t = rt.totals;
    const live = rt.sites.filter(s => s.users30 > 0).length;
    const minuteLabels = rt.minutes.map((_, i) => (29 - i) === 0 ? 'now' : `-${29 - i}m`);
    const html = `
      ${section('Real time', 'Last 30 minutes · refreshes every ' + REFRESH / 1000 + 's')}
      <div class="grid g-rt">
        <div class="card">
          <div class="hero">
            <div><div class="big">${num(t.users30)}</div><div class="lbl">Active users · last 30 min</div></div>
            <div><div class="mid">${num(t.users5)}</div><div class="lbl">Last 5 min</div></div>
            <div><div class="mid">${num(t.views30)}</div><div class="lbl">Views · last 30 min</div></div>
            ${single ? '' : `<div><div class="mid">${live} <span class="muted" style="font-size:15px">/ ${rt.sites.length}</span></div><div class="lbl">Sites with visitors</div></div>`}
          </div>
          <h3>Active users per minute</h3>
          <div class="chart" id="c-min"></div>
        </div>
        <div class="card">
          <h3>Devices</h3>${devices(rt.devices)}
          <h3 style="margin-top:16px">Top countries</h3>${hlist(rt.countries.slice(0, 8), 'users', x => x.name)}
        </div>
      </div>`;
    const after = () => drawChart($('#c-min'), {
      type: 'bar', labels: minuteLabels, height: 170, xEvery: 5,
      tipLabels: rt.minutes.map((_, i) => (29 - i) === 0 ? 'This minute' : `${29 - i} min ago`),
      series: [{ name: 'Active users', values: rt.minutes.map(m => m.users), color: 'var(--s1)' }],
      tipRows: i => [['Active users', num(rt.minutes[i].users), 'var(--s1)'], ['Views', num(rt.minutes[i].views)]],
      aria: 'Active users per minute, last 30 minutes',
    });
    return [html, after];
  }

  function todayBlock(d) {
    const T = d.today.today, Y = d.today.yest;
    const tile = (label, cur, prev, f) => `<div class="kpi"><div class="k">${label}</div><div class="v">${f(cur)}</div><div class="d">Yesterday ${f(prev)}</div></div>`;
    const hm = S.hourMetric;
    const tabs = `<div class="seg tabs" id="hour-tabs"><button data-m="users" class="${hm === 'users' ? 'on' : ''}">Users</button><button data-m="views" class="${hm === 'views' ? 'on' : ''}">Views</button></div>`;
    const html = `
      ${section('Today', 'So far today vs. all of yesterday (each property\'s own time zone)')}
      <div class="kpis">
        ${tile('Users', T.activeUsers, Y.activeUsers, num)}
        ${tile('Views', T.screenPageViews, Y.screenPageViews, num)}
        ${tile('Sessions', T.sessions, Y.sessions, num)}
        ${tile('Engagement rate', T.engagementRate, Y.engagementRate, pct)}
        ${tile('Avg engagement time', T.avgEngagementTime, Y.avgEngagementTime, dur)}
      </div>
      <div class="card" style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <div class="legend"><span><i style="background:var(--s1)"></i>Today</span><span><i class="dash"></i>Yesterday</span></div>${tabs}
        </div>
        <div class="chart" id="c-hour"></div>
      </div>`;
    const after = () => {
      const src = hm === 'users' ? d.hourly.users : d.hourly.views;
      const labels = [...Array(24).keys()].map(hlabel);
      drawChart($('#c-hour'), {
        type: 'line', labels, height: 200, xEvery: 3,
        series: [
          { name: 'Today', values: src.today, color: 'var(--s1)', area: true },
          { name: 'Yesterday', values: src.yest, color: 'var(--cmp)', dashed: true },
        ],
        aria: (hm === 'users' ? 'Users' : 'Views') + ' by hour, today vs yesterday',
      });
      document.querySelectorAll('#hour-tabs button').forEach(b => b.onclick = () => { S.hourMetric = b.dataset.m; render(); });
    };
    return [html, after];
  }

  function periodBlock(d) {
    const C = d.period.cur, P = d.period.prev;
    const tiles = Object.entries(METRICS).map(([k, m]) =>
      `<div class="kpi pick ${S.metric === k ? 'on' : ''}" data-metric="${k}" role="button" tabindex="0">
        <div class="k">${m.label}</div><div class="v">${m.f(C[k])}</div><div class="d">${delta(C[k], P[k])} <span class="muted">vs prev.</span></div></div>`).join('');
    const label = S.days === 365 ? 'Last 12 months' : `Last ${S.days} days`;
    const html = `
      ${section(label, `Full days ending yesterday · compared with the ${S.days} days before`)}
      <div class="kpis">${tiles}</div>
      <div class="card" style="margin-top:12px">
        <h3>${METRICS[S.metric].label} per day</h3>
        <div class="chart" id="c-days"></div>
      </div>`;
    const after = () => {
      const m = METRICS[S.metric];
      drawChart($('#c-days'), {
        type: 'line', height: 230,
        labels: d.series.map(r => dlabel(r.date)),
        tipLabels: d.series.map(r => { r = { date: String(r.date) }; const t = new Date(+r.date.slice(0, 4), +r.date.slice(4, 6) - 1, +r.date.slice(6, 8)); return t.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }); }),
        series: [{ name: m.label, values: d.series.map(r => r[S.metric]), color: 'var(--s1)', area: true }],
        fmt: m.f, yFmt: S.metric === 'engagementRate' ? v => Math.round(v * 100) + '%' : S.metric === 'avgEngagementTime' ? dur : null,
        aria: m.label + ' per day',
      });
      document.querySelectorAll('.kpi.pick').forEach(k => {
        const go = () => { S.metric = k.dataset.metric; render(); };
        k.onclick = go;
        k.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } };
      });
    };
    return [html, after];
  }

  /* ------------------------------------------------------------ views */
  function renderAll() {
    const rt = S.rt, sum = S.sum;
    const parts = [];
    if (rt) {
      parts.push(realtimeBlock(rt, false));
      const rows = rt.sites.map(s => `
        <tr class="link ${s.users30 ? '' : 'zero'}" data-id="${esc(s.id)}">
          <td class="site">${site(s.name)}</td><td><b>${num(s.users30)}</b></td><td>${num(s.users5)}</td><td>${num(s.views30)}</td>
          <td>${spark(s.minutes)}</td></tr>`).join('');
      const pages = rt.pages.length ? rt.pages.map(p => `
        <tr class="link" data-id="${esc(p.siteId)}"><td class="wrap">${esc(p.title)}</td><td class="small" style="text-align:left">${site(p.site)}</td><td>${num(p.views)}</td><td>${num(p.users)}</td></tr>`).join('')
        : '<tr><td colspan="4" class="empty">No page views in the last 30 minutes</td></tr>';
      parts.push([`
        <div class="grid g-2" style="margin-top:14px">
          <div class="card"><h3>Right now, by site</h3><div class="tbl-wrap"><table>
            <thead><tr><th>Site</th><th>Active 30m</th><th>Last 5m</th><th>Views 30m</th><th>Per minute</th></tr></thead>
            <tbody>${rows}</tbody></table></div></div>
          <div class="card"><h3>Top pages right now · all sites</h3><div class="tbl-wrap"><table>
            <thead><tr><th>Page title</th><th style="text-align:left">Site</th><th>Views</th><th>Users</th></tr></thead>
            <tbody>${pages}</tbody></table></div></div>
        </div>`, null]);
    }
    if (sum) {
      parts.push(todayBlock(sum));
      parts.push(periodBlock(sum));
      parts.push(sitesTable(sum));
    }
    return parts;
  }

  function sitesTable(sum) {
    const cols = [
      ['name', 'Site'], ['activeUsers', 'Users'], ['delta', 'Change'], ['share', 'Share of users'], ['newUsers', 'New users'],
      ['sessions', 'Sessions'], ['screenPageViews', 'Views'], ['engagementRate', 'Eng. rate'], ['avgEngagementTime', 'Avg eng. time'], ['today', 'Today'], ['spark', 'Daily users'],
    ];
    const total = sum.period.cur.activeUsers || 1;
    const val = (s, k) => k === 'name' ? s.name.toLowerCase() : k === 'delta' ? (s.prev.activeUsers ? (s.cur.activeUsers - s.prev.activeUsers) / s.prev.activeUsers : -Infinity)
      : k === 'share' ? s.cur.activeUsers : k === 'today' ? s.today.activeUsers : s.cur[k];
    const { key, asc } = S.sort;
    const sites = [...sum.sites].sort((a, b) => { const x = val(a, key), y = val(b, key); return (x < y ? -1 : x > y ? 1 : 0) * (asc ? 1 : -1); });
    const head = cols.map(([k, l]) => k === 'spark'
      ? `<th>${l}</th>` : `<th class="sortable ${key === k ? 'sorted' + (asc ? ' asc' : '') : ''} ${k === 'share' ? 'share-cell' : ''}" data-k="${k}">${l}</th>`).join('');
    const body = sites.map(s => `
      <tr class="link ${s.cur.activeUsers ? '' : 'zero'}" data-id="${esc(s.id)}">
        <td class="site">${site(s.name)}</td><td><b>${num(s.cur.activeUsers)}</b></td><td>${delta(s.cur.activeUsers, s.prev.activeUsers)}</td>
        <td class="share-cell"><span class="share" style="width:${(s.cur.activeUsers / total) * 100}%"></span> <span class="small">${pct(s.cur.activeUsers / total)}</span></td>
        <td>${num(s.cur.newUsers)}</td><td>${num(s.cur.sessions)}</td><td>${num(s.cur.screenPageViews)}</td>
        <td>${pct(s.cur.engagementRate)}</td><td>${dur(s.cur.avgEngagementTime)}</td><td>${num(s.today.activeUsers)}</td><td>${spark(s.spark)}</td></tr>`).join('');
    const C = sum.period.cur;
    const foot = `<tr><td class="site">All ${sum.sites.length} sites</td><td><b>${num(C.activeUsers)}</b></td><td>${delta(C.activeUsers, sum.period.prev.activeUsers)}</td><td></td>
      <td>${num(C.newUsers)}</td><td>${num(C.sessions)}</td><td>${num(C.screenPageViews)}</td><td>${pct(C.engagementRate)}</td><td>${dur(C.avgEngagementTime)}</td><td>${num(sum.today.today.activeUsers)}</td><td></td></tr>`;
    const html = `
      ${section('All sites', 'Click a column to sort, a row to open that site · totals add each site\'s users, so one person visiting two sites counts twice')}
      <div class="card"><div class="tbl-wrap"><table id="sites-tbl">
        <thead><tr>${head}</tr></thead><tbody>${body}</tbody><tfoot>${foot}</tfoot></table></div></div>`;
    const after = () => document.querySelectorAll('#sites-tbl th.sortable').forEach(th => th.onclick = () => {
      const k = th.dataset.k;
      S.sort = { key: k, asc: S.sort.key === k ? !S.sort.asc : k === 'name' };
      render();
    });
    return [html, after];
  }

  function renderProp() {
    const d = S.prop;
    if (!d) return [];
    const p = d.property;
    $('#crumb').textContent = p.name.toLowerCase();
    const parts = [];
    parts.push([`<div class="ext" style="text-align:right;margin-top:-4px">Property ${esc(p.id)} · <a href="${gaLink(p.id)}" target="_blank" rel="noopener">Open in Google Analytics ↗</a></div>`, null]);
    parts.push(realtimeBlock(d.realtime, true));
    const rtPages = d.realtime.pages;
    parts.push([`<div class="card" style="margin-top:14px"><h3>Pages being viewed right now</h3>
      ${hlist(rtPages, 'views', x => x.title, num)}</div>`, null]);
    parts.push(todayBlock(d));
    parts.push(periodBlock(d));
    const pageRows = d.pages.length ? d.pages.map(x => `<tr><td class="wrap"><div>${esc(x.title)}</div><div class="small">${esc(x.path)}</div></td><td>${num(x.views)}</td><td>${num(x.users)}</td><td>${dur(x.avgEng)}</td></tr>`).join('')
      : '<tr><td colspan="4" class="empty">No data</td></tr>';
    const landRows = d.landing.length ? d.landing.map(x => `<tr><td class="wrap small">${esc(x.path)}</td><td>${num(x.sessions)}</td><td>${num(x.users)}</td><td>${pct(x.sessions ? x.engaged / x.sessions : 0)}</td></tr>`).join('')
      : '<tr><td colspan="4" class="empty">No data</td></tr>';
    const lbl = S.days === 365 ? 'last 12 months' : `last ${S.days} days`;
    parts.push([`
      ${section('Content', lbl)}
      <div class="grid g-2">
        <div class="card"><h3>Top pages</h3><div class="tbl-wrap"><table><thead><tr><th>Page</th><th>Views</th><th>Users</th><th>Avg eng. time</th></tr></thead><tbody>${pageRows}</tbody></table></div></div>
        <div class="card"><h3>Landing pages</h3><div class="tbl-wrap"><table><thead><tr><th>Landing page</th><th>Sessions</th><th>Users</th><th>Eng. rate</th></tr></thead><tbody>${landRows}</tbody></table></div></div>
      </div>
      ${section('Acquisition & audience', lbl)}
      <div class="grid g-3">
        <div class="card"><h3>Channels · sessions</h3>${hlist(d.channels, 'sessions', x => x.name)}</div>
        <div class="card"><h3>Sources · sessions</h3>${hlist(d.sources, 'sessions', x => x.name)}</div>
        <div class="card"><h3>Countries · users</h3>${hlist(d.countries, 'users', x => x.name)}
          <h3 style="margin-top:16px">Devices · users</h3>${devices(d.devices)}</div>
      </div>`, null]);
    return parts;
  }

  /* ------------------------------------------------------------ render */
  function render() {
    hideTip();
    S.charts = [];
    if (S.route.view === 'all') $('#crumb').textContent = '';
    const parts = S.route.view === 'all' ? renderAll() : renderProp();
    if (!parts.length) return;
    app.innerHTML = parts.map(p => p[0]).join('') +
      `<div class="foot">${S.demo ? '<b>Demo data</b> — add your service-account key to see real numbers · ' : ''}Data from the Google Analytics Data API · numbers can differ slightly from the GA interface (thresholding and sampling)</div>`;
    parts.forEach(p => p[1] && p[1]());
    app.querySelectorAll('tr.link[data-id]').forEach(tr => tr.onclick = () => { location.hash = '#/p/' + tr.dataset.id; });
  }

  function banner(errors) {
    const b = $('#banner');
    const list = Object.entries(errors || {});
    if (!list.length) { b.hidden = true; return; }
    b.hidden = false;
    b.innerHTML = `<b>Some data could not be loaded:</b> ` + list.slice(0, 4).map(([k, v]) => `${esc(String(k).split('|')[0].toLowerCase())} — ${esc(v)}`).join(' · ') + (list.length > 4 ? ` · and ${list.length - 4} more` : '');
  }

  function liveStatus(err) {
    const el = $('#live'), t = $('#live-text');
    if (err) { el.classList.add('stale'); t.textContent = 'Update failed — retrying'; t.title = err; return; }
    const ago = Math.round((Date.now() - S.lastOk) / 1000);
    el.classList.toggle('stale', ago > REFRESH / 1000 * 2.5);
    t.textContent = (S.demo ? 'Demo · ' : 'Live · ') + (ago < 5 ? 'just updated' : `updated ${ago}s ago`);
  }
  setInterval(() => S.lastOk && liveStatus(), 5000);

  /* ------------------------------------------------------------ loading */
  async function loadRealtime() {
    try {
      if (S.route.view === 'all') {
        S.rt = await api('realtime');
      } else {
        S.prop = await api('property', { id: S.route.id, days: S.days });
        banner(S.prop.errors);
      }
      S.lastOk = Date.now(); liveStatus(); render();
      if (S.route.view === 'all') banner({ ...(S.rt.errors || {}), ...((S.sum && S.sum.errors) || {}) });
    } catch (e) { liveStatus(e.message); if (!app.querySelector('.card')) app.innerHTML = `<div class="loading">Could not load data: ${esc(e.message)}</div>`; }
  }

  async function loadSummary() {
    if (S.route.view !== 'all') return;
    try { S.sum = await api('summary', { days: S.days }); render(); banner({ ...((S.rt && S.rt.errors) || {}), ...(S.sum.errors || {}) }); }
    catch (e) { liveStatus(e.message); }
  }

  function schedule() {
    S.timers.forEach(clearInterval);
    S.timers = [setInterval(() => !document.hidden && loadRealtime(), REFRESH)];
    if (S.route.view === 'all') S.timers.push(setInterval(() => !document.hidden && loadSummary(), 5 * 60 * 1000));
  }

  function route() {
    const m = location.hash.match(/^#\/p\/(\d+)/);
    S.route = m ? { view: 'prop', id: m[1] } : { view: 'all' };
    $('#prop-select').value = m ? m[1] : '';
    S.prop = null;
    app.innerHTML = '<div class="loading">Loading…</div>';
    $('#banner').hidden = true;
    window.scrollTo(0, 0);
    if (S.route.view === 'all') { if (S.rt || S.sum) render(); loadSummary(); }
    loadRealtime();
    schedule();
  }

  document.addEventListener('visibilitychange', () => { if (!document.hidden && Date.now() - S.lastOk > REFRESH) loadRealtime(); });

  /* ------------------------------------------------------------ controls */
  document.querySelectorAll('#range button').forEach(b => {
    b.classList.toggle('on', +b.dataset.days === S.days);
    b.onclick = () => {
      S.days = +b.dataset.days;
      try { localStorage.setItem('gadash.days', S.days); } catch (e) {}
      document.querySelectorAll('#range button').forEach(x => x.classList.toggle('on', x === b));
      if (S.route.view === 'all') loadSummary(); else loadRealtime();
    };
  });
  $('#prop-select').onchange = e => { location.hash = e.target.value ? '#/p/' + e.target.value : '#/'; };
  $('#theme').onclick = () => {
    const root = document.documentElement;
    const dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
    root.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('gadash.theme', root.dataset.theme); } catch (e) {}
    S.charts.forEach(f => f());
  };
  try { const t = localStorage.getItem('gadash.theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}

  addEventListener('hashchange', route);
  api('properties').then(j => {
    S.props = j.properties;
    $('#prop-select').innerHTML = '<option value="">All properties</option>' +
      j.properties.map(p => `<option value="${esc(p.id)}">${esc(p.name.toLowerCase())}</option>`).join('');
    $('#prop-select').value = S.route.view === 'prop' ? S.route.id : '';
  }).catch(e => { app.innerHTML = `<div class="loading">Could not load properties: ${esc(e.message)}</div>`; });
  route();
})();
