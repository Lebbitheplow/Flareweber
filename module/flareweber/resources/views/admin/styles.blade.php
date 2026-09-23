@verbatim
<style>
:root {
  --primary: #2893ff; --primary-dk: #187de0; --link: #1f7cff;
  --bg: #f1f5f9; --card: #ffffff; --border: #e2e8f0; --line: #f1f5f9;
  --navy: #0f172a; --text: #0f172a; --text-2: #49566c; --muted: #6c7a91; --dim: #9ba9be;
  --green: #33a86b; --green-bg: #effaf3; --green-tx: #1f7c4d;
  --orange: #f76707; --orange-bg: #fff7ed; --orange-bd: #ffd7b0; --orange-tx: #87560c;
  --red: #f83b3e; --red-bg: #fffbfb; --red-bd: #ffc7c8; --red-tx: #c11417;
  --d-bg: #0f172a; --d-card: #1d273b; --d-border: #313c52; --d-text: #c8d3e1; --d-muted: #9ba9be; --d-dim: #6c7a91; --d-teal: #83d5c6; --d-green: #51b67e;
  --font: "Instrument Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --mono: "IBM Plex Mono", ui-monospace, "Cascadia Mono", Menlo, monospace;
  --r: 16px; --r-sm: 12px; --nav-h: 64px; --maxw: 720px;
}
* { box-sizing: border-box; margin: 0; }
html { -webkit-text-size-adjust: 100%; }
body { font: 14px/1.5 var(--font); background: var(--bg); color: var(--text); min-height: 100vh; padding-bottom: calc(var(--nav-h) + 16px); -webkit-font-smoothing: antialiased; }
body.dark { background: var(--d-bg); color: #fff; padding-bottom: 0; }
body.no-nav { padding-bottom: 16px; }
a { color: var(--link); text-decoration: none; }
button { font: inherit; cursor: pointer; }
svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
[hidden] { display: none !important; }

/* header */
#hdr { position: sticky; top: 0; z-index: 10; background: var(--card); border-bottom: 1px solid var(--border); padding: 10px 16px; display: flex; align-items: center; gap: 11px; max-width: var(--maxw); margin: 0 auto; min-height: 58px; }
body.dark #hdr { display: none; }
.hdr-back { width: 34px; height: 34px; border-radius: 11px; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex: none; color: var(--text-2); }
.chev-left { width: 9px; height: 9px; border-left: 1.5px solid var(--text-2); border-bottom: 1.5px solid var(--text-2); transform: rotate(45deg); margin-left: 3px; }
.chev-down { width: 7px; height: 7px; border-bottom: 1.5px solid var(--muted); border-right: 1.5px solid var(--muted); transform: rotate(45deg); margin-top: -3px; display: inline-block; }
.chev-right { width: 7px; height: 7px; border-top: 1.5px solid var(--dim); border-right: 1.5px solid var(--dim); transform: rotate(45deg); display: inline-block; flex: none; }
.hdr-avatar, .avatar { width: 36px; height: 36px; border-radius: 11px; background: var(--navy); color: #fff; display: flex; align-items: center; justify-content: center; font: 600 13px/1 var(--font); flex: none; letter-spacing: .02em; }
.hdr-text { flex: 1; min-width: 0; }
.hdr-title-row { display: flex; align-items: center; gap: 6px; cursor: default; }
.hdr-title-row.switcher { cursor: pointer; }
.hdr-title { font: 600 15px/1.2 var(--font); color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hdr-sub { font: 400 11.5px/1.3 var(--mono); color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hdr-right { display: flex; align-items: center; gap: 8px; flex: none; }
.hdr-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 99px; background: var(--navy); color: #fff; font: 500 11px/1 var(--font); flex: none; }

/* layout */
main { padding: 16px; max-width: var(--maxw); margin: 0 auto; }
body.dark main { padding: 0; min-height: 100vh; display: flex; flex-direction: column; }
.stack { display: flex; flex-direction: column; gap: 14px; }
.cols { display: grid; grid-template-columns: 1fr; gap: 14px; }
@media (min-width: 900px) { .cols.two { grid-template-columns: 1fr 1fr; align-items: start; } }
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); padding: 16px; }
.card.list { padding: 0; overflow: hidden; }
.card h2, .h2 { font: 600 14px/1.3 var(--font); color: var(--text); }
.h3 { font: 600 13px/1 var(--font); color: var(--text); margin-bottom: 10px; }
.sub { font: 400 12px/1.5 var(--font); color: var(--muted); }
.eyebrow { font: 500 11px/1 var(--font); color: var(--muted); letter-spacing: .04em; text-transform: uppercase; margin-bottom: 8px; }
.eyebrow-mono { font: 500 10.5px/1 var(--mono); letter-spacing: .08em; color: var(--muted); text-transform: uppercase; margin: 6px 0 10px; }
.mono { font-family: var(--mono); font-size: 11.5px; color: var(--muted); word-break: break-all; }
.divider { height: 1px; background: var(--border); margin: 14px 0; }
.row { display: flex; align-items: center; gap: 12px; }
.grow { flex: 1; min-width: 0; }
.ellip { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.right { text-align: right; }
.mt8 { margin-top: 8px; } .mt12 { margin-top: 12px; } .mt16 { margin-top: 16px; } .mb10 { margin-bottom: 10px; }
.txt-orange { color: var(--orange); } .txt-red { color: var(--red-tx); } .txt-green { color: var(--green-tx); }

/* list rows */
.li { display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-bottom: 1px solid var(--line); background: var(--card); color: inherit; text-align: left; width: 100%; border-left: 0; border-right: 0; border-top: 0; }
.li:last-child { border-bottom: 0; }
.li.tap { cursor: pointer; }
.li.tap:active { background: var(--bg); }
.li .t { font: 600 13.5px/1.3 var(--font); color: var(--text); }
.li .s { font: 400 11.5px/1.4 var(--mono); color: var(--muted); }
.li .s.sans { font-family: var(--font); }
.li.failed { background: var(--red-bg); }
.icon-box { width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex: none; background: var(--bg); color: var(--text-2); }
.icon-box.blue { background: #edf8ff; color: var(--primary); }
.icon-box.orange { background: var(--orange-bg); color: var(--orange); }
.icon-box.green { background: var(--green-bg); color: var(--green); }
.thumb { width: 48px; height: 48px; border-radius: 11px; background: repeating-linear-gradient(135deg, #eef2f7 0 6px, #f8fafc 6px 12px); flex: none; object-fit: cover; overflow: hidden; }
.tree-indent { width: 14px; flex: none; }

/* pills and badges */
.pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 99px; font: 500 11px/1 var(--font); white-space: nowrap; }
.pill .dot { width: 6px; height: 6px; border-radius: 99px; background: currentColor; }
.pill.green { background: var(--green-bg); color: var(--green-tx); }
.pill.orange { background: var(--orange-bg); color: var(--orange-tx); border: 1px solid var(--orange-bd); }
.pill.red { background: var(--red-bg); color: var(--red-tx); border: 1px solid var(--red-bd); }
.pill.blue { background: #edf8ff; color: var(--link); }
.pill.grey { background: var(--bg); color: var(--text-2); }
.pill.navy { background: var(--navy); color: #fff; }
.dot { width: 7px; height: 7px; border-radius: 99px; flex: none; display: inline-block; }
.dot.green { background: var(--green); } .dot.red { background: var(--red); } .dot.blue { background: var(--primary); } .dot.orange { background: var(--orange); } .dot.grey { background: var(--dim); } .dot.purple { background: #8b5cf6; }

/* buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 44px; padding: 0 16px; border-radius: var(--r-sm); border: 1px solid var(--border); background: var(--card); color: var(--text); font: 600 14px/1 var(--font); text-decoration: none; white-space: nowrap; }
.btn:disabled { opacity: .5; cursor: default; }
.btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn.primary:active { background: var(--primary-dk); }
.btn.navy { background: var(--navy); border-color: var(--navy); color: #fff; }
.btn.soft { background: var(--bg); border-color: var(--bg); color: var(--text-2); font-weight: 500; font-size: 12.5px; height: 38px; padding: 0 14px; border-radius: 11px; }
.btn.small { height: 32px; padding: 0 12px; border-radius: 10px; font-size: 11.5px; }
.btn.danger { color: var(--red-tx); border-color: var(--red-bd); }
.btn.block { width: 100%; }
.btn.lg { height: 52px; border-radius: 14px; font-size: 15px; }
.btn.ghost { background: transparent; border-color: transparent; color: var(--muted); font-weight: 500; }
.btn.icon { width: 34px; height: 34px; padding: 0; border-radius: 11px; color: var(--text-2); }
.btn-row { display: flex; gap: 9px; }
.btn-row .btn { flex: 1; }
.dots { display: inline-flex; gap: 2.5px; }
.dots i { width: 3px; height: 3px; border-radius: 99px; background: var(--text-2); }

/* forms */
input[type=text], input[type=url], input[type=email], input[type=number], input[type=password], input[type=search], select, textarea { width: 100%; height: 46px; padding: 0 14px; font: 400 14px/1.3 var(--font); color: var(--text); border: 1px solid var(--border); border-radius: var(--r-sm); background: #fff; outline: none; }
textarea { height: auto; min-height: 96px; padding: 12px 14px; font-family: var(--mono); font-size: 12.5px; resize: vertical; }
input:focus, select:focus, textarea:focus { border-color: var(--primary); }
label.lbl { display: block; font: 500 11px/1 var(--font); color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin: 0 0 7px; }
.field { margin-bottom: 14px; }
.toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--line); }
.toggle-row:first-child { border-top: 0; padding-top: 0; }
.toggle-row .t { font: 600 14px/1.3 var(--font); }
.toggle-row .s { font: 400 12px/1.4 var(--font); color: var(--muted); }
.switch { position: relative; width: 46px; height: 28px; flex: none; }
.switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.switch span { position: absolute; inset: 0; border-radius: 99px; background: #cbd5e1; transition: background .15s; }
.switch span::after { content: ""; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 99px; background: #fff; transition: transform .15s; }
.switch input:checked + span { background: var(--primary); }
.switch input:checked + span::after { transform: translateX(18px); }
.tpl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.tpl { border: 2px solid var(--border); border-radius: 14px; overflow: hidden; background: #fff; cursor: pointer; padding: 0; text-align: left; }
.tpl.on { border-color: var(--primary); }
.tpl .art { height: 90px; background: repeating-linear-gradient(135deg, #eef2f7 0 6px, #f8fafc 6px 12px); display: flex; flex-direction: column; justify-content: flex-end; padding: 8px; gap: 4px; }
.tpl .art i { display: block; height: 8px; border-radius: 3px; background: #cbd5e1; }
.tpl .name { padding: 10px 12px; font: 600 13px/1 var(--font); }
.two-up { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 430px) { .two-up { grid-template-columns: 1fr; } }

/* tabs and chips */
.tabs { display: flex; gap: 18px; padding: 0 16px; background: var(--card); border-bottom: 1px solid var(--border); margin: -16px -16px 14px; overflow-x: auto; }
.tabs a { padding: 12px 0 11px; font: 500 13px/1 var(--font); color: var(--muted); border-bottom: 2px solid transparent; white-space: nowrap; }
.tabs a.on { color: var(--text); font-weight: 600; border-bottom-color: var(--navy); }
.tabs a b { font-weight: inherit; color: var(--dim); margin-left: 4px; }
.chips { display: flex; gap: 7px; margin-bottom: 12px; flex-wrap: wrap; }
.chip { height: 30px; padding: 0 12px; border-radius: 99px; background: var(--card); border: 1px solid var(--border); display: inline-flex; align-items: center; gap: 6px; font: 500 11.5px/1 var(--font); color: var(--text-2); }
.chip.on { background: var(--navy); border-color: var(--navy); color: #fff; }
.chip.warn { background: var(--orange-bg); border-color: var(--orange-bd); color: var(--orange-tx); font-weight: 600; }

/* stats */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 9px; }
@media (max-width: 430px) { .stats { grid-template-columns: 1fr 1fr; } }
.stat { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 12px; color: inherit; }
.stat b { display: block; font: 600 19px/1 var(--font); color: var(--text); }
.stat b.warn { color: var(--orange); }
.stat span { display: block; margin-top: 5px; font: 400 11px/1.2 var(--font); color: var(--muted); }

/* deploy card (dark inside light) */
.deploy-card { background: var(--navy); border-radius: 18px; padding: 17px; color: #fff; }
.deploy-card .state { display: flex; align-items: center; gap: 8px; font: 500 11.5px/1 var(--mono); color: var(--d-muted); letter-spacing: .04em; text-transform: uppercase; }
.deploy-card .head { margin-top: 12px; font: 600 17px/1.3 var(--font); color: #fff; }
.deploy-card .sub { margin-top: 6px; font: 400 12.5px/1.5 var(--font); color: var(--d-muted); }
.deploy-card .btn-row { margin-top: 15px; }
.deploy-card .btn { border-color: var(--d-border); background: transparent; color: var(--d-text); font-weight: 500; }
.deploy-card .btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }

/* stepper */
.stepper { display: flex; align-items: center; gap: 7px; flex: none; }
.stepper button { width: 30px; height: 30px; border-radius: 9px; background: var(--bg); border: 0; color: var(--text-2); font-size: 16px; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.stepper button:disabled { opacity: .4; }
.stepper b { width: 34px; text-align: center; font: 600 14px/1 var(--mono); color: var(--text); }
.stepper b.warn { color: var(--orange); }

/* media grid */
.media-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
@media (min-width: 640px) { .media-grid { grid-template-columns: repeat(4, 1fr); } }
.media-tile { border: 0; padding: 0; background: var(--card); border-radius: 12px; overflow: hidden; text-align: left; border: 1px solid var(--border); }
.media-tile .img { aspect-ratio: 1; background: repeating-linear-gradient(135deg, #eef2f7 0 6px, #f8fafc 6px 12px); display: flex; align-items: center; justify-content: center; color: var(--dim); font: 600 11px/1 var(--mono); }
.media-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.media-tile .meta { padding: 7px 8px; }
.media-tile .n { font: 500 11px/1.3 var(--font); color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-tile .z { font: 400 10.5px/1.3 var(--mono); color: var(--muted); }

/* dark screens */
.dk { flex: 1; display: flex; flex-direction: column; background: var(--d-bg); color: #fff; max-width: var(--maxw); width: 100%; margin: 0 auto; min-height: 100vh; }
.dk-top { padding: 22px 22px 0; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.dk-brand { display: flex; align-items: center; gap: 9px; font: 600 13px/1 var(--font); color: #fff; }
.dk-brand i { width: 24px; height: 24px; border-radius: 8px; background: var(--primary); display: block; }
.dk-step { font: 400 12px/1 var(--font); color: var(--d-dim); }
.dk-h1 { padding: 30px 22px 0; font: 600 25px/1.22 var(--font); color: #fff; letter-spacing: -.4px; }
.dk-h1.sm { font-size: 21px; padding-top: 12px; }
.dk-lead { padding: 12px 22px 0; font: 400 14px/1.6 var(--font); color: var(--d-muted); }
.dk-body { padding: 0 22px; display: flex; flex-direction: column; gap: 12px; margin-top: 22px; }
.dk-card { padding: 16px; border: 1px solid var(--d-border); border-radius: 16px; background: var(--d-card); color: var(--d-text); }
.dk-card .t { font: 600 14px/1.3 var(--font); color: #fff; }
.dk-card .s { font: 400 12px/1.4 var(--mono); color: var(--d-dim); }
.dk-card .divider { background: var(--d-border); }
.dk-card input, .dk-card textarea, .dk-card select { background: var(--d-bg); border-color: var(--d-border); color: var(--d-text); }
.dk-card input:focus, .dk-card textarea:focus { border-color: var(--primary); }
.dk-card label.lbl { color: var(--d-muted); }
.dk-card .sub { color: var(--d-muted); }
.dk-card details summary { cursor: pointer; font: 500 12px/1.4 var(--font); color: var(--d-teal); list-style: none; }
.dk-card details summary::-webkit-details-marker { display: none; }
.dk-card ul { margin: 8px 0 0 16px; padding: 0; font: 400 12px/1.6 var(--font); color: var(--d-muted); }
.dk-note { padding: 14px; border-radius: 14px; background: rgba(40,147,255,.08); border: 1px solid rgba(40,147,255,.3); font: 400 12px/1.6 var(--font); color: var(--d-text); }
.dk-note.warn { background: rgba(247,103,7,.1); border-color: rgba(247,103,7,.4); }
.dk-row { padding: 13px; border-radius: 14px; background: var(--d-card); display: flex; align-items: center; gap: 11px; color: inherit; }
.dk-row .t { font: 500 13px/1.3 var(--font); color: #fff; }
.dk-row .s { font: 400 11px/1.3 var(--font); color: var(--d-dim); }
.dk-row .a { font: 500 11px/1 var(--font); color: var(--d-muted); }
.dk-foot { margin-top: auto; padding: 20px 22px 24px; display: flex; flex-direction: column; gap: 10px; }
.dk-foot .btn-row { flex-direction: row; }
.dk .btn { border-color: var(--d-border); background: transparent; color: var(--d-text); font-weight: 500; }
.dk .btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
.dk .btn.ghost { border-color: transparent; color: var(--d-muted); }
.dk .btn.cancel { background: var(--d-card); border-color: var(--d-card); color: #ff8b8d; }
.dk .btn.tile { flex-direction: column; height: auto; padding: 14px; gap: 6px; align-items: flex-start; text-align: left; }
.dk-close { width: 32px; height: 32px; border-radius: 10px; background: var(--d-card); border: 0; color: var(--d-text); display: flex; align-items: center; justify-content: center; font-size: 18px; line-height: 1; }
.dk-state { display: flex; align-items: center; gap: 9px; font: 500 11px/1 var(--mono); letter-spacing: .06em; color: var(--d-teal); text-transform: uppercase; padding: 0 22px; margin-top: 26px; }
.dk-state.fail { color: #ff8b8d; }
.dk-state.ok { color: var(--d-green); }
.progress { margin: 20px 22px 0; height: 6px; border-radius: 99px; background: var(--d-card); overflow: hidden; }
.progress i { display: block; height: 6px; border-radius: 99px; background: var(--primary); transition: width .4s; }
.steps { margin: 20px 22px 0; display: flex; flex-direction: column; gap: 14px; }
.step { display: flex; align-items: center; gap: 11px; }
.step .k { width: 20px; height: 20px; border-radius: 99px; border: 2px solid var(--d-border); flex: none; display: flex; align-items: center; justify-content: center; }
.step.done .k { background: var(--green); border-color: var(--green); }
.step.done .k::after { content: ""; width: 8px; height: 4px; border-left: 2px solid #fff; border-bottom: 2px solid #fff; transform: rotate(-45deg); margin-top: -2px; }
.step.running .k { border-color: var(--primary); animation: pulse 1s ease-in-out infinite; }
.step.failed .k { background: var(--red); border-color: var(--red); }
.step.skipped .k { border-style: dashed; }
.step .l { flex: 1; font: 500 13px/1.3 var(--font); color: var(--d-dim); }
.step.done .l, .step.running .l, .step.failed .l { color: #fff; }
.step .d { font: 400 11px/1.3 var(--mono); color: var(--d-dim); text-align: right; max-width: 45%; }
.step.running .d { color: var(--d-teal); }
.step.failed .d { color: #ff8b8d; }
.log { margin: 22px 22px 0; flex: 1; min-height: 120px; max-height: 40vh; border-radius: 14px; background: #000; border: 1px solid var(--d-card); padding: 13px; overflow: auto; font: 400 11px/1.9 var(--mono); color: var(--d-muted); white-space: pre-wrap; word-break: break-word; }
pre.log.light { margin: 10px 0 0; background: var(--navy); color: #d3dcec; border-radius: 10px; padding: 10px 12px; font-size: 11.5px; line-height: 1.6; flex: none; max-height: 320px; }
@keyframes pulse { 50% { opacity: .5; } }

/* nav */
#nav { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: var(--maxw); z-index: 20; display: flex; background: var(--card); border-top: 1px solid var(--border); padding: 7px 4px max(4px, env(safe-area-inset-bottom)); }
#nav a { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 5px 0; font: 500 10px/1 var(--font); color: var(--muted); }
#nav a .ico svg { width: 20px; height: 20px; }
#nav a.on { color: var(--link); font-weight: 600; }

/* sheet, toast, misc */
#sheet-bg { position: fixed; inset: 0; z-index: 30; background: rgba(15,23,42,.5); display: flex; align-items: flex-end; justify-content: center; }
#sheet { width: 100%; max-width: var(--maxw); background: var(--card); border-radius: 20px 20px 0 0; padding: 8px 16px max(20px, env(safe-area-inset-bottom)); max-height: 88vh; overflow: auto; animation: up .18s ease-out; }
@keyframes up { from { transform: translateY(30px); opacity: 0; } }
.sheet-handle { width: 40px; height: 4px; border-radius: 99px; background: var(--border); margin: 4px auto 14px; }
#sheet h3 { font: 600 16px/1.3 var(--font); margin-bottom: 6px; }
#sheet .sub { margin-bottom: 14px; }
.menu a, .menu button { display: flex; align-items: center; gap: 12px; width: 100%; padding: 13px 4px; border: 0; border-bottom: 1px solid var(--line); background: none; text-align: left; font: 500 14px/1.3 var(--font); color: var(--text); }
.menu a:last-child, .menu button:last-child { border-bottom: 0; }
.menu .danger { color: var(--red-tx); }
#toast { position: fixed; left: 50%; bottom: calc(var(--nav-h) + 20px); transform: translateX(-50%); z-index: 40; max-width: 92%; background: var(--navy); color: #fff; border-radius: 12px; padding: 11px 16px; font: 500 13px/1.4 var(--font); display: none; box-shadow: 0 8px 24px rgba(15,23,42,.25); }
#toast.error { background: var(--red-tx); }
#toast.show { display: block; }
.empty { text-align: center; color: var(--muted); padding: 40px 20px; font-size: 13px; }
.empty b { display: block; color: var(--text); font-size: 15px; margin-bottom: 6px; }
.empty .btn { margin-top: 14px; }
.spin, .spin-sm, .spin-dark { width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; animation: sp .7s linear infinite; display: inline-block; vertical-align: middle; }
.spin-sm { width: 12px; height: 12px; }
.spin-dark { border-color: rgba(15,23,42,.15); border-top-color: var(--primary); width: 24px; height: 24px; }
@keyframes sp { to { transform: rotate(360deg); } }
.kv { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: 13px; }
.kv:last-child { border-bottom: 0; }
.kv span:first-child { color: var(--muted); }
.kv span:last-child { font-family: var(--mono); font-size: 12px; text-align: right; word-break: break-all; }
.code { font: 400 12px/1.7 var(--mono); background: var(--bg); border-radius: 10px; padding: 10px 12px; white-space: pre; overflow-x: auto; color: var(--text-2); }
.copy-list { list-style: none; padding: 0; margin: 8px 0 0; }
.copy-list li { font: 400 12.5px/1.6 var(--mono); color: var(--text); }
.section-title { display: flex; align-items: baseline; justify-content: space-between; margin: 4px 0 10px; }
.section-title .h3 { margin: 0; }
.section-title a { font: 500 12px/1 var(--font); }
</style>
@endverbatim
