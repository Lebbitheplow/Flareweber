@verbatim
<script>
window.FW = (function () {
  "use strict";

  var boot = JSON.parse(document.getElementById("fw-bootstrap").textContent || "{}");
  var BASE = boot.base || "/flareweber";
  var API = boot.api || "/flareweber/api";
  var app = document.getElementById("app");
  var toastEl = document.getElementById("toast");
  var sheetBg = document.getElementById("sheet-bg");
  var sheetBody = document.getElementById("sheet-body");
  var nav = document.getElementById("nav");
  var H = {};
  ["back", "avatar", "title", "chev", "sub", "pill", "pill-text", "right"].forEach(function (k) { H[k] = document.getElementById("hdr-" + k); });

  var FW = {
    boot: null, cfg: boot, routes: [], actions: {}, sheetActions: {},
    state: { sites: [], site: null, cf: null, ready: false, from: null }
  };

  /* ---------- text helpers ---------- */

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function csrf() { return document.querySelector('meta[name="csrf-token"]').content; }
  function initials(name) {
    var parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "FW";
    return (parts[0][0] + (parts[1] ? parts[1][0] : (parts[0][1] || ""))).toUpperCase();
  }
  function ago(iso) {
    if (!iso) return "never";
    var t = new Date(iso).getTime();
    if (isNaN(t)) return String(iso);
    var s = (Date.now() - t) / 1000;
    if (s < 45) return "just now";
    if (s < 90) return "1 min ago";
    if (s < 3600) return Math.round(s / 60) + " min ago";
    if (s < 7200) return "1 hour ago";
    if (s < 86400) return Math.round(s / 3600) + " hours ago";
    if (s < 172800) return "yesterday";
    if (s < 86400 * 14) return Math.round(s / 86400) + " days ago";
    return new Date(t).toLocaleDateString();
  }
  function when(iso) {
    if (!iso) return "";
    var d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso);
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    var time = pad(d.getHours()) + ":" + pad(d.getMinutes());
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var diff = (today.getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86400000;
    if (diff === 0) return "Today " + time;
    if (diff === 1) return "Yesterday " + time;
    if (diff < 7) return ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"][d.getDay()] + " " + time;
    return d.toLocaleDateString() + " " + time;
  }
  function money(cents, currency) {
    var n = (Number(cents) || 0) / 100;
    return price(n, currency);
  }
  function price(n, currency) {
    n = Number(n) || 0;
    try { return new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "USD" }).format(n); }
    catch (e) { return (currency || "") + " " + n.toFixed(2); }
  }
  function bytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + " B";
    if (n < 1048576) return Math.round(n / 1024) + " KB";
    if (n < 1073741824) return (n / 1048576).toFixed(1) + " MB";
    return (n / 1073741824).toFixed(2) + " GB";
  }
  function hostOf(url) {
    try { return new URL(url).host; } catch (e) { return String(url || ""); }
  }

  /* ---------- toast ---------- */

  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.className = "show" + (isError ? " error" : "");
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.className = ""; }, isError ? 5000 : 3200);
  }

  /* ---------- API ---------- */

  function api(path, options) {
    options = options || {};
    var url = path.indexOf("/api/") === 0 ? API + path.slice(4) : BASE + path;
    var headers = { "Accept": "application/json", "X-CSRF-TOKEN": csrf(), "X-Requested-With": "XMLHttpRequest" };
    var body;
    if (options.form) { body = options.form; }
    else if (options.body !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(options.body); }
    return fetch(url, { method: options.method || "GET", headers: headers, body: body, credentials: "same-origin" }).then(function (res) {
      if (res.status === 401) {
        window.location.href = "/admin/login";
        return new Promise(function () {});
      }
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var msg = data.message || data.error || ("Request failed (" + res.status + ")");
          if (data.errors && typeof data.errors === "object") {
            var first = Object.keys(data.errors)[0];
            if (first && data.errors[first] && data.errors[first][0]) msg = data.errors[first][0];
          }
          var err = new Error(typeof msg === "string" ? msg : JSON.stringify(msg));
          err.status = res.status; err.data = data; err.code = data.error;
          throw err;
        }
        if (data && typeof data === "object" && !Array.isArray(data)) data._status = res.status;
        return data;
      });
    });
  }

  /* Starts an OAuth round-trip that completes in the system browser, then
     polls the handoff token the backend parked the result under. */
  function oauthHandoff(startUrl, onDone, anchor) {
    document.querySelectorAll(".oauth-status").forEach(function (el) { el.remove(); });
    var statusLine = document.createElement("div");
    statusLine.className = "sub oauth-status";
    statusLine.style.marginTop = "8px";
    (anchor && anchor.parentNode ? anchor.parentNode : app).appendChild(statusLine);

    var finished = false, timer = null, token = null, unsubscribe = null;
    var deadline = Date.now() + 5 * 60 * 1000;
    function setStatus(html) { statusLine.innerHTML = html; }
    function finish(data, isError) {
      if (finished) return;
      finished = true;
      if (timer) clearInterval(timer);
      if (unsubscribe) unsubscribe();
      statusLine.remove();
      onDone(data, isError);
    }
    function poll() {
      if (finished) return;
      if (Date.now() > deadline) { finish({ status: "error", message: "Connection timed out. Try again." }, true); return; }
      fetch(BASE + "/handoff/" + encodeURIComponent(token) + "/status", {
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }, credentials: "same-origin"
      }).then(function (res) {
        if (res.status === 401) { window.location.href = "/admin/login"; return null; }
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (res.status === 404 || data.status === "expired") { finish({ status: "error", message: "Connection timed out. Try again." }, true); return null; }
          if (data.status === "pending") return null;
          data.handoff = data.handoff || token;
          finish(data, data.status === "error");
        });
      }).catch(function () { /* transient network error: keep polling */ });
    }

    api(startUrl).then(function (r) {
      if (!r.url || !r.handoff) {
        if (r.url) { window.location.href = r.url; return null; }
        finish({ status: "error", message: "No connection URL was returned" }, true);
        return null;
      }
      token = r.handoff;
      setStatus("Waiting for your browser to finish connecting...");
      // The desktop shell pings us the moment the OS hands the OAuth callback back to the app.
      if (window.flareweber && window.flareweber.onOauthLink) unsubscribe = window.flareweber.onOauthLink(function () { poll(); });
      var win = window.open(r.url, "_blank");
      // In the desktop shell window.open is intercepted (returns null) because the system browser opens the link.
      if (!win && !(window.flareweber && window.flareweber.onOauthLink)) {
        setStatus('The connection window was blocked. <a href="' + esc(r.url) + '" target="_blank" rel="noopener">Open it here</a> to continue.');
      }
      timer = setInterval(poll, 1500);
    }).catch(function (e) {
      if (e && e.code === "oauth_not_configured") finish({ status: "error", message: "OAuth is not configured on this install. Use an API token instead." }, true);
      else finish({ status: "error", message: (e && e.message) || "Could not start the connection" }, true);
    });
  }

  /* ---------- view, header, nav ---------- */

  function view(html, actions) {
    FW.actions = actions || {};
    app.innerHTML = html;
    window.scrollTo(0, 0);
  }
  function loading(dark) {
    view(dark ? '<div class="dk"><div class="empty" style="color:#9ba9be"><span class="spin"></span></div></div>' : '<div class="empty"><span class="spin-dark"></span></div>');
  }
  function errorView(e, retryHash) {
    view('<div class="empty"><b>Something went wrong</b>' + esc(e && e.message ? e.message : e) +
      (retryHash ? '<br><a class="btn soft" href="' + esc(retryHash) + '" data-act="retry">Try again</a>' : "") + "</div>",
      { retry: function () { render(); } });
  }
  function screen(o) {
    o = o || {};
    document.body.className = (o.dark ? "dark" : "") + (o.nav === false || o.dark ? " no-nav" : "");
    nav.hidden = !!(o.dark || o.nav === false);
    var site = FW.state.site;
    H.back.hidden = !o.back;
    if (o.back) H.back.setAttribute("href", typeof o.back === "string" ? o.back : "#/home");
    H.avatar.hidden = !o.avatar;
    if (o.avatar) H.avatar.textContent = initials(site ? site.name : "FW");
    H.title.textContent = o.title || "FlareWeber";
    H.chev.hidden = !o.switcher;
    H.title.parentNode.className = "hdr-title-row" + (o.switcher ? " switcher" : "");
    H.title.parentNode.onclick = o.switcher ? function () { FW.state.from = location.hash; go("#/sites"); } : null;
    H.sub.hidden = !o.sub;
    H.sub.textContent = o.sub || "";
    H.right.innerHTML = o.right || "";
    document.getElementById("nav-shop").hidden = !(site && site.ecommerce);
    nav.querySelectorAll("a").forEach(function (a) { a.className = a.getAttribute("data-nav") === o.tab ? "on" : ""; });
    updatePill();
  }
  function updatePill() {
    var d = FW.deploy.current;
    H.pill.hidden = !d || document.body.classList.contains("dark");
    if (d) {
      H["pill-text"].textContent = d.dep.environment === "preview" ? "Preview build" : "Deploying";
      H.pill.setAttribute("href", "#/publish/build/" + d.dep.id);
    }
  }

  /* ---------- sheet, confirm, prompt ---------- */

  function sheet(html, actions) {
    FW.sheetActions = actions || {};
    sheetBody.innerHTML = html;
    sheetBg.hidden = false;
  }
  function closeSheet() { sheetBg.hidden = true; sheetBody.innerHTML = ""; FW.sheetActions = {}; }
  sheetBg.addEventListener("click", function (e) { if (e.target === sheetBg) closeSheet(); });

  function confirm(o) {
    return new Promise(function (resolve) {
      sheet('<h3>' + esc(o.title) + '</h3><div class="sub">' + esc(o.text || "") + '</div>' +
        '<div class="btn-row"><button class="btn" data-act="no">Cancel</button>' +
        '<button class="btn ' + (o.danger ? "danger" : "primary") + '" data-act="yes">' + esc(o.ok || "Confirm") + '</button></div>',
        { yes: function () { closeSheet(); resolve(true); }, no: function () { closeSheet(); resolve(false); } });
    });
  }
  function prompt(o) {
    return new Promise(function (resolve) {
      var fields = (o.fields || []).map(function (f) {
        return '<div class="field"><label class="lbl" for="pf-' + esc(f.name) + '">' + esc(f.label) + '</label>' +
          '<input type="' + esc(f.type || "text") + '" id="pf-' + esc(f.name) + '" name="' + esc(f.name) + '" value="' + esc(f.value || "") +
          '" placeholder="' + esc(f.placeholder || "") + '"' + (f.step ? ' step="' + esc(f.step) + '"' : "") + (f.min != null ? ' min="' + esc(f.min) + '"' : "") + '></div>';
      }).join("");
      sheet('<h3>' + esc(o.title) + '</h3>' + (o.text ? '<div class="sub">' + esc(o.text) + '</div>' : "") +
        '<form data-form="ok">' + fields + '<div class="btn-row"><button type="button" class="btn" data-act="no">Cancel</button>' +
        '<button type="submit" class="btn primary">' + esc(o.ok || "Save") + '</button></div></form>',
        { no: function () { closeSheet(); resolve(null); },
          ok: function (form) { var v = {}; (o.fields || []).forEach(function (f) { v[f.name] = form.elements[f.name].value; }); closeSheet(); resolve(v); } });
      var first = sheetBody.querySelector("input");
      if (first) setTimeout(function () { first.focus(); }, 50);
    });
  }

  /* ---------- action delegation ---------- */

  function delegate(root, mapName) {
    root.addEventListener("click", function (e) {
      var el = e.target.closest("[data-act]");
      if (!el || !root.contains(el)) return;
      var fn = FW[mapName][el.getAttribute("data-act")];
      if (!fn) return;
      if (el.tagName === "A" && !el.hasAttribute("data-follow")) e.preventDefault();
      fn(el, e);
    });
    root.addEventListener("change", function (e) {
      var el = e.target.closest("[data-change]");
      if (!el) return;
      var fn = FW[mapName][el.getAttribute("data-change")];
      if (fn) fn(el, e);
    });
    root.addEventListener("submit", function (e) {
      var el = e.target.closest("[data-form]");
      if (!el) return;
      e.preventDefault();
      var fn = FW[mapName][el.getAttribute("data-form")];
      if (fn) fn(el, e);
    });
  }
  delegate(app, "actions");
  delegate(H.right, "actions");
  delegate(document.getElementById("sheet"), "sheetActions");

  /* ---------- state ---------- */

  function loadSites() {
    return api("/sites").then(function (sites) {
      sites = Array.isArray(sites) ? sites : [];
      FW.state.sites = sites;
      var wanted = Number(localStorage.getItem("fw.site") || 0);
      var site = null;
      sites.forEach(function (s) { if (s.id === wanted) site = s; });
      FW.state.site = site || sites[0] || null;
      if (FW.state.site) localStorage.setItem("fw.site", FW.state.site.id);
      return sites;
    });
  }
  function loadCloudflare() {
    return api("/cloudflare/status").then(function (st) {
      st = st || {};
      if (st.oauth_available === undefined) st.oauth_available = !!boot.cloudflare_oauth_available;
      FW.state.cf = st;
      return st;
    }).catch(function () { FW.state.cf = { connected: false, oauth_available: !!boot.cloudflare_oauth_available }; return FW.state.cf; });
  }
  function selectSite(id) {
    var found = null;
    FW.state.sites.forEach(function (s) { if (String(s.id) === String(id)) found = s; });
    if (found) { FW.state.site = found; localStorage.setItem("fw.site", found.id); }
    return found;
  }
  function refreshSite() {
    var s = FW.state.site;
    if (!s) return Promise.resolve(null);
    return api("/sites/" + s.id).then(function (fresh) {
      FW.state.sites = FW.state.sites.map(function (x) { return x.id === fresh.id ? fresh : x; });
      FW.state.site = fresh;
      return fresh;
    });
  }

  /* ---------- router ---------- */

  function route(re, fn) { FW.routes.push([re, fn]); }
  function go(hash) { if (location.hash === hash) render(); else location.hash = hash; }
  function requireSite(fn) {
    return function (m) {
      if (FW.state.site) return fn(m, FW.state.site);
      go(FW.state.cf && FW.state.cf.connected ? "#/new" : "#/welcome");
    };
  }
  function render() {
    if (!FW.state.ready) return;
    closeSheet();
    document.querySelectorAll(".oauth-status").forEach(function (el) { el.remove(); });
    var hash = location.hash || "#/";
    if (hash === "#/" || hash === "#") { go(FW.state.site ? "#/home" : (FW.state.cf && FW.state.cf.connected ? "#/new" : "#/welcome")); return; }
    for (var i = 0; i < FW.routes.length; i++) {
      var m = hash.match(FW.routes[i][0]);
      if (m) { FW.routes[i][1](m); return; }
    }
    go("#/");
  }
  window.addEventListener("hashchange", render);

  /* ---------- deployment watcher (survives navigation) ---------- */

  FW.deploy = {
    current: null, timer: null, listeners: [],
    start: function (siteId, dep) {
      var self = this;
      this.stop();
      this.current = { siteId: siteId, dep: dep, startedAt: Date.now() };
      updatePill();
      this.emit();
      if (dep.status === "running" || dep.status === "pending" || dep.status === "queued" || !dep.status) this.timer = setInterval(function () { self.poll(); }, 1500);
      else this.finish(dep);
    },
    poll: function () {
      var self = this, c = this.current;
      if (!c) return;
      api("/sites/" + c.siteId + "/deployments/" + c.dep.id).then(function (dep) {
        if (!self.current || self.current.dep.id !== dep.id) return;
        self.current.dep = dep;
        self.emit();
        if (dep.status === "success" || dep.status === "failed") self.finish(dep);
      }).catch(function () { /* keep polling on transient errors */ });
    },
    finish: function (dep) {
      var c = this.current;
      if (this.timer) clearInterval(this.timer);
      this.timer = null;
      this.current = null;
      updatePill();
      if (dep.status === "success") toast(dep.environment === "preview" ? "Preview is ready" : "Published to " + (hostOf(dep.url) || "Cloudflare"));
      else toast((dep.environment === "preview" ? "Preview build" : "Deploy") + " failed", true);
      var self = this;
      refreshSite().catch(function () {}).then(function () { self.emit(dep, true); });
      void c;
    },
    stop: function () { if (this.timer) clearInterval(this.timer); this.timer = null; this.current = null; updatePill(); },
    emit: function (dep, done) {
      var d = dep || (this.current && this.current.dep);
      this.listeners.slice().forEach(function (fn) { fn(d, !!done); });
    },
    onUpdate: function (fn) {
      var self = this;
      this.listeners.push(fn);
      return function () { self.listeners = self.listeners.filter(function (x) { return x !== fn; }); };
    }
  };

  /* ---------- boot ---------- */

  FW.boot = function () {
    Promise.all([loadSites(), loadCloudflare()]).then(function () {
      FW.state.ready = true;
      render();
    }).catch(function (e) {
      FW.state.ready = true;
      screen({ title: "FlareWeber", nav: false });
      errorView(e, location.hash || "#/");
    });
  };

  FW.esc = esc; FW.toast = toast; FW.api = api; FW.oauthHandoff = oauthHandoff;
  FW.ago = ago; FW.when = when; FW.money = money; FW.price = price; FW.bytes = bytes; FW.hostOf = hostOf; FW.initials = initials;
  FW.view = view; FW.loading = loading; FW.error = errorView; FW.screen = screen;
  FW.sheet = sheet; FW.closeSheet = closeSheet; FW.confirm = confirm; FW.prompt = prompt;
  FW.route = route; FW.go = go; FW.render = render; FW.requireSite = requireSite;
  FW.loadSites = loadSites; FW.loadCloudflare = loadCloudflare; FW.selectSite = selectSite; FW.refreshSite = refreshSite;
  FW.updatePill = updatePill;
  return FW;
})();
</script>
@endverbatim
