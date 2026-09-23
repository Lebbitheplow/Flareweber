@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;

  var PERMISSIONS = [
    "Account: Workers Scripts Edit", "Account: D1 Edit", "Account: Workers R2 Storage Edit", "Account: Account Settings Read",
    "Zone: Zone Read", "Zone: DNS Edit", "Zone: Workers Routes Edit", "Zone: SSL and Certificates Edit (all zones)"
  ];

  /* ---------- account picker (token flow and OAuth flow share it) ---------- */

  function pickAccount(r) {
    return new Promise(function (resolve) {
      var accounts = r.accounts || [];
      FW.sheet('<h3>Pick the account to publish into</h3><div class="sub">This token can reach more than one Cloudflare account.</div>' +
        '<div class="card list">' + accounts.map(function (a) {
          return '<button class="li tap" data-act="pick" data-id="' + esc(a.id) + '"><div class="grow"><div class="t">' + esc(a.name || a.id) +
            '</div><div class="s">' + esc(a.id) + '</div></div><span class="chev-right"></span></button>';
        }).join("") + '</div>',
        { pick: function (el) {
          el.disabled = true;
          api("/cloudflare/accounts/select", { method: "POST", body: { connection_id: r.connection_id, account_id: el.getAttribute("data-id"), handoff: r.handoff || null } })
            .then(function (res) { FW.closeSheet(); resolve(res); })
            .catch(function (e) { toast(e.message, true); el.disabled = false; });
        } });
    });
  }
  FW.pickAccount = pickAccount;

  function afterConnect(next) {
    return FW.loadCloudflare().then(function () { FW.go(next); });
  }

  /* ---------- welcome / connect ---------- */

  FW.route(/^#\/welcome(\/add)?$/, function (m) {
    var adding = !!m[1];
    var cf = FW.state.cf || {};
    var next = adding ? "#/cloudflare" : "#/new";
    var oauth = !!cf.oauth_available;
    FW.screen({ dark: true });

    var html = '<div class="dk">' +
      '<div class="dk-top"><div class="dk-brand"><i></i>FlareWeber</div><span class="dk-step">' + (adding ? "Add an account" : "Step 1 of 2") + '</span></div>' +
      '<div class="dk-h1">' + (adding ? "Connect another Cloudflare account" : "Connect the account you will publish to") + '</div>' +
      '<div class="dk-lead">Your site is built here and pushed to Cloudflare Workers. Nothing is public until you hit Publish.</div>' +
      '<div class="dk-body">' +
      (cf.connected && !adding ? '<div class="dk-note">Already connected to <b>' + esc(cf.account_name || cf.account_id) + '</b>. <a href="#/new" style="color:#83d5c6">Continue to create your site</a> or connect a different account below.</div>' : "") +
      '<div class="two-up">' +
      (oauth ?
        '<div class="dk-card"><div class="row"><div class="icon-box" style="background:#f76707;color:#fff;border-radius:12px"><svg viewBox="0 0 24 24"><path d="M7 17a4 4 0 0 1-.5-8A6 6 0 0 1 18 8.5 3.5 3.5 0 0 1 17.5 17z"/></svg></div>' +
        '<div class="grow"><div class="t">Connect with Cloudflare</div><div class="s">workers.dev + custom domains</div></div><span class="pill green"><span class="dot"></span>Ready</span></div>' +
        '<div class="divider"></div><div class="sub">Sign in with Cloudflare in your browser. Only the permissions needed to deploy are requested.</div>' +
        '<button class="btn primary block lg mt16" data-act="oauth">Connect with Cloudflare</button></div>' : "") +
      '<div class="dk-card"><div class="row"><div class="icon-box" style="background:#0f172a;color:#c8d3e1;border-radius:12px"><svg viewBox="0 0 24 24"><path d="M14 7a3 3 0 1 1 3 3l-7 7H7v-3z"/><path d="m12 9 3 3"/></svg></div>' +
        '<div class="grow"><div class="t">Use an API token</div><div class="s">Cloudflare dashboard, My profile, API tokens</div></div></div>' +
        '<div class="divider"></div>' +
        '<label class="lbl" for="cf-token">API token</label><textarea id="cf-token" placeholder="Paste the token here" autocomplete="off" spellcheck="false"></textarea>' +
        '<details class="mt12"><summary>Required permissions</summary><ul>' + PERMISSIONS.map(function (p) { return "<li>" + esc(p) + "</li>"; }).join("") + '</ul></details>' +
        '<button class="btn primary block lg mt16" data-act="verify">Verify and continue</button></div>' +
      '</div></div>' +
      '<div class="dk-foot">' + (adding ? '<a class="btn ghost" href="#/cloudflare">Back</a>' : '<a class="btn ghost" href="#/new">Skip for now, connect before publishing</a>') + '</div>' +
      '</div>';

    FW.view(html, {
      oauth: function (btn) {
        btn.disabled = true;
        FW.oauthHandoff("/cloudflare/connect", function (r) {
          btn.disabled = false;
          if (r.status === "connected") { toast("Cloudflare connected" + (r.account_name ? " (" + r.account_name + ")" : "")); afterConnect(next); }
          else if (r.status === "needs_account") { pickAccount(r).then(function () { toast("Cloudflare connected"); afterConnect(next); }); }
          else toast(r.message || "Cloudflare connection failed", true);
        }, btn);
      },
      verify: function (btn) {
        var token = document.getElementById("cf-token").value.trim();
        if (!token) { toast("Paste an API token first", true); return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="spin"></span> Verifying';
        api("/cloudflare/token", { method: "POST", body: { token: token } }).then(function (r) {
          if (r.status === "needs_account") return pickAccount(r);
          return r;
        }).then(function (r) {
          toast("Cloudflare connected" + (r && r.account_name ? " (" + r.account_name + ")" : ""));
          return afterConnect(next);
        }).catch(function (e) {
          toast(e.message, true);
          btn.disabled = false;
          btn.textContent = "Verify and continue";
        });
      }
    });
  });

  /* ---------- OAuth landing without a handoff (browser flow) ---------- */

  FW.route(/^#\/cloudflare-accounts$/, function () {
    FW.screen({ title: "Choose account", back: "#/welcome", nav: false });
    FW.loading();
    api("/cloudflare/accounts").then(function (r) {
      if (!r.connection_id || !r.accounts || !r.accounts.length) {
        FW.view('<div class="empty"><b>No pending connection</b>Start again from the connect screen.<br><a class="btn soft" href="#/welcome">Connect Cloudflare</a></div>');
        return;
      }
      FW.view('<div class="card list">' + r.accounts.map(function (a) {
        return '<button class="li tap" data-act="pick" data-id="' + esc(a.id) + '"><div class="grow"><div class="t">' + esc(a.name || a.id) + '</div><div class="s">' + esc(a.id) + '</div></div><span class="chev-right"></span></button>';
      }).join("") + '</div>', {
        pick: function (el) {
          el.disabled = true;
          api("/cloudflare/accounts/select", { method: "POST", body: { connection_id: r.connection_id, account_id: el.getAttribute("data-id") } })
            .then(function () { toast("Cloudflare connected"); afterConnect(FW.state.site ? "#/home" : "#/new"); })
            .catch(function (e) { toast(e.message, true); el.disabled = false; });
        }
      });
    }).catch(function (e) { FW.error(e); });
  });

  /* ---------- create site ---------- */

  FW.route(/^#\/new$/, function () {
    var hasSites = FW.state.sites.length > 0;
    var cf = FW.state.cf || {};
    FW.screen({ title: "New site", sub: hasSites ? "" : "Step 2 of 2", back: hasSites ? "#/sites" : "#/welcome", nav: false });
    var tpl = "default";

    FW.view('<div class="stack">' +
      (cf.connected ? "" : '<div class="card" style="border-color:var(--orange-bd);background:var(--orange-bg)"><div class="h2">No Cloudflare account yet</div><div class="sub">You can create the site now and <a href="#/welcome">connect Cloudflare</a> before the first publish.</div></div>') +
      '<div class="card"><label class="lbl" for="f-name">Site name</label><input type="text" id="f-name" placeholder="Ferns and Fog" maxlength="120" autocomplete="off">' +
        '<div class="divider"></div><div class="eyebrow">Worker address</div><div class="sub">Your site gets a <span class="mono">workers.dev</span> address on the first publish' + (cf.connected ? "" : " once Cloudflare is connected") + '. A custom domain can be added later.</div></div>' +
      '<div class="card">' +
        '<div class="toggle-row"><div><div class="t">Sell products</div><div class="s">Adds cart, orders and stock</div></div><label class="switch"><input type="checkbox" id="f-ecom"><span></span></label></div>' +
        '<div class="toggle-row"><div><div class="t">Contact forms</div><div class="s">Stores form entries in the site database</div></div><label class="switch"><input type="checkbox" id="f-forms" checked><span></span></label></div>' +
      '</div>' +
      '<div><div class="section-title"><div class="h3">Starting template</div><span class="sub">Microweber templates</span></div>' +
        '<div class="tpl-grid">' + ["default", "big"].map(function (t) {
          return '<button type="button" class="tpl' + (t === tpl ? " on" : "") + '" data-act="tpl" data-id="' + t + '"><div class="art"><i style="width:70%"></i><i style="width:45%"></i></div><div class="name">' + (t === "default" ? "Default" : "Big") + '</div></button>';
        }).join("") + '</div></div>' +
      '<button class="btn primary block lg" data-act="create">Create site</button>' +
      '</div>', {
      tpl: function (el) {
        tpl = el.getAttribute("data-id");
        document.querySelectorAll(".tpl").forEach(function (b) { b.className = "tpl" + (b.getAttribute("data-id") === tpl ? " on" : ""); });
      },
      create: function (btn) {
        var name = document.getElementById("f-name").value.trim();
        if (!name) { toast("Pick a site name", true); document.getElementById("f-name").focus(); return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="spin"></span> Creating';
        api("/sites", { method: "POST", body: {
          name: name, ecommerce: document.getElementById("f-ecom").checked, forms: document.getElementById("f-forms").checked, media: true, template: tpl
        } }).then(function (site) {
          return FW.loadSites().then(function () { FW.selectSite(site.id); toast("Site created"); FW.go("#/home"); });
        }).catch(function (e) { toast(e.message, true); btn.disabled = false; btn.textContent = "Create site"; });
      }
    });
    setTimeout(function () { var i = document.getElementById("f-name"); if (i) i.focus(); }, 50);
  });

  /* ---------- site switcher ---------- */

  function siteState(s) {
    var d = s.latest_deployment;
    if (d && (d.status === "running" || d.status === "pending")) return '<span class="txt-green">Deploying now</span>';
    if (d && d.status === "failed") return '<span class="txt-red">Last deploy failed</span>';
    if (s.published_at) return "Live · deployed " + esc(FW.ago(d ? d.created_at : s.published_at));
    if (s.preview_url) return "Preview only";
    return "Not published yet";
  }

  FW.route(/^#\/sites$/, function () {
    var cf = FW.state.cf || {};
    var sites = FW.state.sites;
    var back = FW.state.from && FW.state.from !== "#/sites" ? FW.state.from : "#/home";
    FW.screen({ title: "Your sites", sub: sites.length + (sites.length === 1 ? " site" : " sites") + (cf.connected ? " · " + (cf.account_name || "1 Cloudflare account") : ""), back: back, nav: false,
      right: '<a class="btn navy small" href="#/new">New</a>' });

    function rows(filter) {
      var list = sites.filter(function (s) { return !filter || (s.name || "").toLowerCase().indexOf(filter) >= 0 || (s.domain || "").toLowerCase().indexOf(filter) >= 0; });
      if (!list.length) return '<div class="empty">' + (filter ? "No sites match" : "No sites yet") + '</div>';
      return list.map(function (s) {
        return '<button class="li tap" data-act="pick" data-id="' + s.id + '"><div class="avatar">' + esc(FW.initials(s.name)) + '</div>' +
          '<div class="grow"><div class="t">' + esc(s.name) + (FW.state.site && FW.state.site.id === s.id ? ' <span class="pill blue">current</span>' : "") + '</div>' +
          '<div class="s">' + esc(s.domain || FW.hostOf(s.live_url) || s.worker || "no address yet") + '</div><div class="s sans">' + siteState(s) + '</div></div><span class="chev-right"></span></button>';
      }).join("");
    }

    FW.view('<div class="stack"><input type="search" id="site-q" placeholder="Search sites" data-change="q" autocomplete="off">' +
      '<div class="card list" id="site-rows">' + rows("") + '</div>' +
      '<div class="sub">Switching sites keeps your place: you land on the same screen for the new site.</div></div>', {
      pick: function (el) {
        FW.selectSite(el.getAttribute("data-id"));
        FW.go(back === "#/sites" || /^#\/(new|welcome|sites)/.test(back) ? "#/home" : back);
      },
      q: function (el) { document.getElementById("site-rows").innerHTML = rows(el.value.trim().toLowerCase()); }
    });
    var q = document.getElementById("site-q");
    q.addEventListener("input", function () { document.getElementById("site-rows").innerHTML = rows(q.value.trim().toLowerCase()); });
  });
})(window.FW);
</script>
@endverbatim
