<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>FlareWeber</title>
<style>
:root {
  --primary: #2893ff;
  --primary-dk: #187de0;
  --bg: #f1f5f9;
  --card: #ffffff;
  --text: #1f2937;
  --muted: #656d7d;
  --border: #e6e9f2;
  --success: #2fb344;
  --warning: #f59f00;
  --danger: #d63939;
  --radius: 10px;
}
* { box-sizing: border-box; margin: 0; }
html { -webkit-text-size-adjust: 100%; }
body {
  font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  background: var(--bg);
  color: var(--text);
  margin: 0 auto;
  max-width: 560px;
  min-height: 100vh;
  padding-bottom: 76px;
}
header {
  position: sticky; top: 0; z-index: 10;
  background: var(--card);
  border-bottom: 1px solid var(--border);
  padding: 12px 16px;
  display: flex; align-items: center; gap: 10px;
}
header h1 { font-size: 16px; font-weight: 600; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
header .back { font-size: 22px; line-height: 1; padding: 2px 8px; color: var(--muted); text-decoration: none; }
main { padding: 16px; }
.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 16px;
  margin-bottom: 12px;
}
.card h2 { font-size: 14px; font-weight: 600; margin-bottom: 8px; }
.card .sub { color: var(--muted); font-size: 13px; }
.row { display: flex; align-items: center; gap: 10px; }
.row .grow { flex: 1; min-width: 0; }
.muted { color: var(--muted); font-size: 13px; }
.mono { font-family: ui-monospace, "Cascadia Mono", Menlo, monospace; font-size: 12px; word-break: break-all; }
.badge {
  display: inline-block; padding: 2px 9px; border-radius: 99px;
  font-size: 12px; font-weight: 600; color: #fff; background: var(--muted);
}
.badge.success { background: var(--success); }
.badge.warning { background: var(--warning); }
.badge.danger  { background: var(--danger); }
.badge.primary { background: var(--primary); }
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text);
  font: inherit; font-weight: 600; font-size: 14px;
  padding: 9px 14px; cursor: pointer; text-decoration: none;
}
.btn:disabled { opacity: .5; cursor: default; }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-primary:active { background: var(--primary-dk); }
.btn-danger { color: var(--danger); border-color: #f3c2c2; }
.btn-block { width: 100%; }
.btn-lg { padding: 13px 16px; font-size: 15px; }
input[type=text], input[type=url], select {
  width: 100%; padding: 10px 12px; font: inherit;
  border: 1px solid var(--border); border-radius: 8px; background: #fff;
  margin-bottom: 10px;
}
label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; }
.toggle-row label { margin: 0; }
.fab {
  position: fixed; right: max(20px, calc(50% - 260px)); bottom: 92px; z-index: 20;
  width: 52px; height: 52px; border-radius: 50%;
  background: var(--primary); color: #fff; font-size: 26px; line-height: 1;
  border: none; box-shadow: 0 4px 14px rgba(40,147,255,.45); cursor: pointer;
  text-decoration: none; display: flex; align-items: center; justify-content: center;
}
nav {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: 560px; z-index: 20;
  display: flex; background: var(--card); border-top: 1px solid var(--border);
  padding: 6px 0 max(6px, env(safe-area-inset-bottom));
}
nav a {
  flex: 1; text-align: center; text-decoration: none; color: var(--muted);
  font-size: 11px; font-weight: 600; padding: 4px 0;
}
nav a.active { color: var(--primary); }
nav a .ico { display: block; font-size: 20px; line-height: 1.2; }
.list-item { display: block; text-decoration: none; color: inherit; }
pre.log {
  background: #0f172a; color: #d3dcec; border-radius: 8px;
  padding: 10px 12px; font-size: 12px; overflow-x: auto; white-space: pre-wrap;
}
#toast {
  position: fixed; left: 50%; bottom: 90px; transform: translateX(-50%);
  z-index: 40; max-width: 90%;
  background: #1f2937; color: #fff; border-radius: 8px;
  padding: 10px 16px; font-size: 13px; display: none;
}
#toast.error { background: var(--danger); }
.center { text-align: center; padding: 40px 20px; }
.spin {
  width: 18px; height: 18px; border-radius: 50%;
  border: 2px solid rgba(255,255,255,.4); border-top-color: #fff;
  animation: sp .7s linear infinite; display: inline-block;
}
@keyframes sp { to { transform: rotate(360deg); } }
a { color: var(--primary); }
.empty { text-align: center; color: var(--muted); padding: 36px 16px; }
</style>
</head>
<body>
<header>
  <a class="back" id="back" hidden href="#/">&#8249;</a>
  <h1 id="title">FlareWeber</h1>
  <span id="header-action"></span>
</header>
<main id="app"></main>
<nav id="nav">
  <a href="#/" data-nav="sites"><span class="ico">&#9635;</span>Sites</a>
  <a href="#/settings" data-nav="settings"><span class="ico">&#9881;</span>Settings</a>
</nav>
<div id="toast"></div>

<script>
(function () {
  "use strict";

  var BASE = "/flareweber";
  var app = document.getElementById("app");
  var titleEl = document.getElementById("title");
  var backEl = document.getElementById("back");
  var headerAction = document.getElementById("header-action");
  var toastEl = document.getElementById("toast");

  /* ---------- helpers ---------- */

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.className = isError ? "error" : "";
    toastEl.style.display = "block";
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.style.display = "none"; }, 4000);
  }

  function api(path, options) {
    options = options || {};
    return fetch(BASE + path, {
      method: options.method || "GET",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
      },
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var msg = data.message || data.error || ("Request failed (" + res.status + ")");
          var err = new Error(typeof msg === "string" ? msg : JSON.stringify(msg));
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function ago(iso) {
    if (!iso) return "never";
    var s = (Date.now() - new Date(iso).getTime()) / 1000;
    if (s < 90) return "just now";
    if (s < 5400) return Math.round(s / 60) + "m ago";
    if (s < 172800) return Math.round(s / 3600) + "h ago";
    return Math.round(s / 86400) + "d ago";
  }

  function statusBadge(status) {
    var cls = { success: "success", pending: "warning", building: "primary", deploying: "primary" }[status] || "danger";
    return '<span class="badge ' + cls + '">' + esc(status) + "</span>";
  }

  function setTitle(t, showBack, headerHtml) {
    titleEl.textContent = t;
    backEl.hidden = !showBack;
    headerAction.innerHTML = headerHtml || "";
  }

  function go(hash) { location.hash = hash; }

  /* ---------- router ---------- */

  var routes = [
    [/^#\/$/, function () { sitesList(); }],
    [/^#\/new$/, function () { siteNew(); }],
    [/^#\/settings$/, function () { settings(); }],
    [/^#\/cloudflare-accounts$/, function () { cloudflareAccounts(); }],
    [/^#\/site\/(\d+)\/deploys$/, function (m) { siteDeploys(m[1]); }],
    [/^#\/site\/(\d+)\/domain$/, function (m) { siteDomain(m[1]); }],
    [/^#\/site\/(\d+)\/settings$/, function (m) { siteSettings(m[1]); }],
    [/^#\/site\/(\d+)$/, function (m) { siteDetail(m[1]); }]
  ];

  function render() {
    var hash = location.hash || "#/";
    for (var i = 0; i < routes.length; i++) {
      var m = hash.match(routes[i][0]);
      if (m) { routes[i][1](m); navActive(hash); return; }
    }
    go("#/");
  }

  function navActive(hash) {
    document.querySelectorAll("#nav a").forEach(function (a) {
      var sec = a.getAttribute("data-nav");
      a.className = (sec === "settings") === (hash === "#/settings" || hash === "#/cloudflare-accounts") ? "active" : "";
    });
  }

  window.addEventListener("hashchange", render);

  /* ---------- screens: sites ---------- */

  function sitesList() {
    setTitle("FlareWeber", false);
    app.innerHTML = '<div class="empty">Loading…</div>';
    document.getElementById("fab") && document.getElementById("fab").remove();

    api("/sites").then(function (sites) {
      var fab = document.createElement("a");
      fab.id = "fab"; fab.href = "#/new"; fab.innerHTML = "+"; fab.title = "New site";
      document.body.appendChild(fab);

      if (!sites.length) {
        app.innerHTML =
          '<div class="empty"><p>No sites yet.</p><p>Publish your Microweber site to your own Cloudflare account.</p>' +
          '<p style="margin-top:16px"><a class="btn btn-primary" href="#/new">Create your first site</a></p></div>';
        return;
      }

      app.innerHTML = sites.map(function (s) {
        var dep = s.latest_deployment;
        return '<a class="card list-item" href="#/site/' + s.id + '">' +
          '<div class="row"><div class="grow">' +
            "<h2>" + esc(s.name) + "</h2>" +
            '<div class="muted">' + esc(s.domain || s.worker || "no domain yet") + "</div>" +
          "</div>" + statusBadge(dep ? dep.status : (s.published_at ? "success" : "draft")) + "</div>" +
          '<div class="muted" style="margin-top:6px">' +
            (dep ? "v" + dep.version + " · " + ago(dep.created_at) : "never published") +
            (s.cloudflare_connected ? "" : ' · <span style="color:var(--warning)">connect Cloudflare</span>') +
          "</div></a>";
      }).join("");
    }).catch(function (e) { app.innerHTML = '<div class="empty">' + esc(e.message) + "</div>"; });
  }

  function siteNew() {
    setTitle("New site", true);
    app.innerHTML =
      '<div class="card">' +
        "<h2>Site name</h2>" +
        '<input type="text" id="f-name" placeholder="Leaflet Land" maxlength="120">' +
        '<div class="toggle-row"><label for="f-ecom">Online shop</label><input type="checkbox" id="f-ecom"></div>' +
        '<div class="toggle-row"><label for="f-forms">Contact forms</label><input type="checkbox" id="f-forms" checked></div>' +
        '<div class="toggle-row"><label for="f-media">Media library (R2)</label><input type="checkbox" id="f-media" checked></div>' +
        '<button class="btn btn-primary btn-block btn-lg" id="create">Create site</button>' +
      "</div>" +
      '<p class="muted">You can connect Cloudflare and set a custom domain afterwards.</p>';

    document.getElementById("create").onclick = function () {
      var name = document.getElementById("f-name").value.trim();
      if (!name) { toast("Pick a site name", true); return; }
      this.disabled = true;
      this.innerHTML = '<span class="spin"></span> Creating…';
      api("/sites", {
        method: "POST",
        body: {
          name: name,
          ecommerce: document.getElementById("f-ecom").checked,
          forms: document.getElementById("f-forms").checked,
          media: document.getElementById("f-media").checked
        }
      }).then(function (site) {
        toast("Site created");
        go("#/site/" + site.id);
      }).catch(function (e) {
        toast(e.message, true);
        this.disabled = false;
        this.textContent = "Create site";
      });
    };
  }

  /* ---------- screens: site detail ---------- */

  function siteDetail(id) {
    setTitle("Site", true);
    app.innerHTML = '<div class="empty">Loading…</div>';
    var fab = document.getElementById("fab"); if (fab) fab.remove();

    api("/sites/" + id).then(function (s) {
      setTitle(s.name, true);
      var dep = s.latest_deployment;
      var liveUrl = (dep && dep.status === "success" && dep.url) || (s.domain ? "https://" + s.domain : null);

      app.innerHTML =
        (s.cloudflare_connected ? "" :
          '<div class="card" style="border-color:#f5d78e;background:#fdf6e3">' +
          "<h2>Connect Cloudflare</h2><div class=\"sub\">Publishing needs your Cloudflare account. " +
          '<a href="#/settings">Connect now →</a></div></div>') +
        '<div class="card">' +
          '<div class="row"><div class="grow"><h2>' + esc(s.name) + "</h2>" +
          '<div class="muted mono">' + esc(s.domain || s.worker || "") + "</div></div>" +
          statusBadge(dep ? dep.status : (s.published_at ? "success" : "draft")) + "</div>" +
          '<div class="muted" style="margin:6px 0 12px">' +
            (dep ? "v" + dep.version + " · published " + ago(dep.created_at) : "Never published") +
          "</div>" +
          '<button class="btn btn-primary btn-block btn-lg" id="publish">Publish now</button>' +
          '<div class="row" style="margin-top:10px">' +
            '<button class="btn grow" id="preview">Preview</button>' +
            (liveUrl ? '<a class="btn grow" href="' + esc(liveUrl) + '" target="_blank" rel="noopener">Open site ↗</a>' : "") +
            '<a class="btn grow" href="/admin" target="_blank" rel="noopener">Edit content ↗</a>' +
          "</div>" +
          '<pre class="log" id="log" hidden></pre>' +
        "</div>" +
        '<a class="card list-item" href="#/site/' + id + '/deploys"><div class="row"><div class="grow"><h2>Deploys</h2><div class="sub">History and rollbacks</div></div><span>›</span></div></a>' +
        '<a class="card list-item" href="#/site/' + id + '/domain"><div class="row"><div class="grow"><h2>Domain</h2><div class="sub">' + (s.domain ? esc(s.domain) : "Connect a custom domain") + '</div></div><span>›</span></div></a>' +
        '<a class="card list-item" href="#/site/' + id + '/settings"><div class="row"><div class="grow"><h2>Settings</h2><div class="sub">Shop, forms, media, Stripe</div></div><span>›</span></div></a>';

      document.getElementById("publish").onclick = function () { runDeploy(id, "/publish", this); };
      document.getElementById("preview").onclick = function () { runDeploy(id, "/preview", this); };
    }).catch(function (e) { app.innerHTML = '<div class="empty">' + esc(e.message) + "</div>"; });
  }

  function runDeploy(id, path, btn) {
    var log = document.getElementById("log");
    log.hidden = false;
    log.textContent = path === "/preview" ? "Building preview…" : "Publishing…";
    btn.disabled = true;

    var other = document.getElementById(path === "/preview" ? "publish" : "preview");
    if (other) other.disabled = true;

    api("/sites/" + id + path, { method: "POST" }).then(function (dep) {
      log.textContent = dep.log || (dep.status === "success" ? "Done." : "Failed.");
      toast(dep.status === "success"
        ? (dep.environment === "preview" ? "Preview ready" : "Published " + (dep.url || ""))
        : "Deploy failed: " + dep.status, dep.status !== "success");
      btn.disabled = false;
      if (other) other.disabled = false;
      if (dep.status === "success" && dep.url && dep.environment === "production") {
        log.textContent += "\n→ " + dep.url;
      }
    }).catch(function (e) {
      log.textContent = String(e.message);
      toast("Deploy failed", true);
      btn.disabled = false;
      if (other) other.disabled = false;
    });
  }

  /* ---------- screens: deploys ---------- */

  function siteDeploys(id) {
    setTitle("Deploys", true);
    app.innerHTML = '<div class="empty">Loading…</div>';

    api("/sites/" + id + "/deployments").then(function (deps) {
      if (!deps.length) {
        app.innerHTML = '<div class="empty">No deploys yet. Hit “Publish now”.</div>';
        return;
      }
      app.innerHTML = deps.map(function (d, i) {
        return '<div class="card">' +
          '<div class="row"><div class="grow"><h2>Version ' + d.version +
            (d.environment === "preview" ? ' <span class="badge">preview</span>' : "") + "</h2>" +
            '<div class="muted">' + esc(d.created_at || "") + (d.url ? ' · <a href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(d.url) + "</a>" : "") + "</div></div>" +
            statusBadge(d.status) + "</div>" +
          (d.status === "success" && d.environment !== "preview" && i > 0
            ? '<button class="btn btn-danger" style="margin-top:8px" data-rollback="' + d.id + '">Roll back to this version</button>'
            : "") +
          (d.log ? "<details style=\"margin-top:8px\"><summary class=\"muted\">Log</summary><pre class=\"log\">" + esc(d.log) + "</pre></details>" : "") +
        "</div>";
      }).join("");

      app.querySelectorAll("[data-rollback]").forEach(function (btn) {
        btn.onclick = function () {
          btn.disabled = true;
          api("/sites/" + id + "/deployments/" + btn.getAttribute("data-rollback") + "/rollback", { method: "POST" })
            .then(function (r) {
              toast(r.status === "success" ? "Rolled back" : "Rollback failed", r.status !== "success");
              if (r.status === "success") siteDeploys(id);
              btn.disabled = false;
            })
            .catch(function (e) { toast(e.message, true); btn.disabled = false; });
        };
      });
    }).catch(function (e) { app.innerHTML = '<div class="empty">' + esc(e.message) + "</div>"; });
  }

  /* ---------- screens: domain ---------- */

  function siteDomain(id) {
    setTitle("Domain", true);
    api("/sites/" + id).then(function (s) {
      app.innerHTML =
        '<div class="card">' +
          "<h2>Custom domain</h2>" +
          '<div class="sub" style="margin-bottom:10px">' +
            (s.domain ? "Currently connected: <b>" + esc(s.domain) + "</b>" : "No domain yet. Point your domain at Cloudflare, then connect it to this site.") +
          "</div>" +
          '<label for="d-input">Domain</label>' +
          '<input type="text" id="d-input" placeholder="example.com" value="' + esc(s.domain || "") + '">' +
          '<div class="row">' +
            '<button class="btn grow" id="check">Check</button>' +
            '<button class="btn btn-primary grow" id="connect">Connect</button>' +
          "</div>" +
          '<div id="d-result" class="muted" style="margin-top:10px"></div>' +
        "</div>" +
        (s.cloudflare_connected ? "" : '<div class="card" style="border-color:#f5d78e;background:#fdf6e3">Connect your Cloudflare account first in <a href="#/settings">Settings</a>.</div>');

      var out = document.getElementById("d-result");

      document.getElementById("check").onclick = function () {
        out.textContent = "Checking…";
        api("/sites/" + id + "/domain/check", { method: "POST", body: { domain: document.getElementById("d-input").value.trim() } })
          .then(function (r) {
            out.innerHTML = r.found
              ? "Zone found on Cloudflare (status: " + esc(r.zone_status || "unknown") + "). Ready to connect."
              : (r.on_cloudflare_nameservers
                  ? "Zone not found, but nameservers point to Cloudflare — it may still be syncing."
                  : "Domain is not on this Cloudflare account and its nameservers are: " + esc((r.nameservers || []).join(", ")));
          })
          .catch(function (e) { out.textContent = e.message; });
      };

      document.getElementById("connect").onclick = function () {
        out.textContent = "Connecting… (can take up to a minute)";
        api("/sites/" + id + "/domain/connect", { method: "POST", body: { domain: document.getElementById("d-input").value.trim() } })
          .then(function (r) {
            out.innerHTML = "Connected ✓" + (r.https_ready ? " HTTPS ready." : " DNS/HTTPS still propagating.");
            toast("Domain connected");
          })
          .catch(function (e) { out.textContent = e.message; });
      };
    });
  }

  /* ---------- screens: site settings ---------- */

  function siteSettings(id) {
    setTitle("Site settings", true);
    api("/sites/" + id).then(function (s) {
      var st = s.settings || {};
      app.innerHTML =
        '<div class="card">' +
          "<h2>Name</h2>" +
          '<input type="text" id="s-name" value="' + esc(s.name) + '" maxlength="120">' +
          '<button class="btn btn-primary" id="save-name">Save</button>' +
        "</div>" +
        '<div class="card">' +
          "<h2>Features</h2>" +
          '<div class="toggle-row"><label for="s-ecom">Online shop (Stripe + D1)</label><input type="checkbox" id="s-ecom" ' + (s.ecommerce ? "checked" : "") + "></div>" +
          '<div class="toggle-row"><label for="s-forms">Contact forms (D1)</label><input type="checkbox" id="s-forms" ' + (st.forms ? "checked" : "") + "></div>" +
          '<div class="toggle-row"><label for="s-media">Media library (R2)</label><input type="checkbox" id="s-media" ' + (st.media !== false ? "checked" : "") + "></div>" +
          '<button class="btn btn-primary" id="save-feat">Save features</button>' +
          '<div class="muted" style="margin-top:8px">Changes apply on the next publish.</div>' +
        "</div>" +
        '<div class="card">' +
          "<h2>Payments</h2>" +
          '<div class="sub" style="margin-bottom:10px">' +
            (s.ecommerce
              ? (st.stripe_account_id ? "Stripe account connected (" + esc(st.stripe_account_id) + ")." : "Connect Stripe to accept payments.")
              : "Enable “Online shop” to accept payments.") +
          "</div>" +
          (s.ecommerce ? '<a class="btn" href="' + BASE + "/sites/" + id + '/stripe/connect">Connect with Stripe ↗</a>' : "") +
        "</div>" +
        '<div class="card">' +
          "<h2>Cloudflare resources</h2>" +
          '<div class="muted mono">worker: ' + esc(s.worker || "—") + "</div>" +
          '<div class="muted mono">' + esc(s.cloudflare_connected ? "connected" : "not connected") + "</div>" +
        "</div>";

      document.getElementById("save-name").onclick = function () {
        api("/sites/" + id, { method: "PUT", body: { name: document.getElementById("s-name").value.trim() } })
          .then(function () { toast("Saved"); })
          .catch(function (e) { toast(e.message, true); });
      };

      document.getElementById("save-feat").onclick = function () {
        api("/sites/" + id, {
          method: "PUT",
          body: { settings: {
            ecommerce: document.getElementById("s-ecom").checked,
            forms: document.getElementById("s-forms").checked,
            media: document.getElementById("s-media").checked,
            template: st.template || "dream"
          } }
        }).then(function () { toast("Saved — republish to apply"); })
          .catch(function (e) { toast(e.message, true); });
      };
    });
  }

  /* ---------- screens: global settings ---------- */

  function settings() {
    setTitle("Settings", false);
    var fab = document.getElementById("fab"); if (fab) fab.remove();
    app.innerHTML = '<div class="empty">Loading…</div>';

    var q = new URLSearchParams(location.search);
    if (q.get("error") === "token_inactive") toast("Cloudflare token was not active — try connecting again", true);

    api("/cloudflare/status").then(function (st) {
      app.innerHTML =
        '<div class="card">' +
          "<h2>Cloudflare</h2>" +
          (st.connected
            ? '<div class="row"><div class="grow"><div>Connected as <b>' + esc(st.account_name || st.account_id) + "</b></div>" +
              '<div class="muted mono">' + esc(st.account_id) + "</div></div>" +
              '<button class="btn btn-danger" id="cf-disconnect">Disconnect</button></div>'
            : (st.account_id
                ? '<div class="sub" style="margin-bottom:10px">Token expired for ' + esc(st.account_name || st.account_id) + ".</div>" +
                  '<a class="btn btn-primary" href="' + BASE + '/cloudflare/connect">Reconnect</a>'
                : '<div class="sub" style="margin-bottom:10px">Publish sites to your own Cloudflare account: one Worker, one D1 database, one R2 bucket per site. Free tier friendly.</div>' +
                  '<a class="btn btn-primary" href="' + BASE + '/cloudflare/connect">Connect Cloudflare</a>')) +
        "</div>" +
        '<div class="card">' +
          "<h2>About</h2>" +
          '<div class="sub">FlareWeber compiles this Microweber site and deploys it to Cloudflare Workers. Content stays here; visitors hit the edge.</div>' +
        "</div>" +
        '<div class="card">' +
          "<h2>Content</h2>" +
          '<div class="sub" style="margin-bottom:10px">Edit pages and upload media in the Microweber admin, then publish.</div>' +
          '<div class="row"><a class="btn grow" href="/admin" target="_blank" rel="noopener">Admin ↗</a>' +
          '<a class="btn grow" href="/edit" target="_blank" rel="noopener">Live edit ↗</a></div>' +
        "</div>";

      var disc = document.getElementById("cf-disconnect");
      if (disc) disc.onclick = function () {
        api("/cloudflare/" + st.connection_id + "/disconnect", { method: "POST" })
          .then(function () { toast("Disconnected"); settings(); })
          .catch(function (e) { toast(e.message, true); });
      };
    }).catch(function (e) { app.innerHTML = '<div class="empty">' + esc(e.message) + "</div>"; });
  }

  function cloudflareAccounts() {
    setTitle("Choose Cloudflare account", false);
    app.innerHTML = '<div class="empty">Loading…</div>';

    api("/cloudflare/accounts").then(function (r) {
      if (!r.connection_id || !r.accounts || !r.accounts.length) {
        app.innerHTML = '<div class="empty">No pending Cloudflare connection. <a href="#/settings">Connect first</a>.</div>';
        return;
      }
      app.innerHTML = '<div class="card"><h2>Pick the account to publish into</h2>' +
        r.accounts.map(function (a) {
          return '<div class="row" style="padding:8px 0;border-top:1px solid var(--border)">' +
            '<div class="grow"><b>' + esc(a.name || a.id) + '</b><div class="muted mono">' + esc(a.id) + "</div></div>" +
            '<button class="btn btn-primary" data-acct="' + esc(a.id) + '">Select</button></div>';
        }).join("") + "</div>";

      app.querySelectorAll("[data-acct]").forEach(function (btn) {
        btn.onclick = function () {
          btn.disabled = true;
          api("/cloudflare/accounts/select", { method: "POST", body: { account_id: btn.getAttribute("data-acct") } })
            .then(function () { toast("Cloudflare connected"); go("#/new"); })
            .catch(function (e) { toast(e.message, true); btn.disabled = false; });
        };
      });
    }).catch(function (e) { app.innerHTML = '<div class="empty">' + esc(e.message) + "</div>"; });
  }

  render();
})();
</script>
</body>
</html>
