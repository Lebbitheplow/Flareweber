@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;

  function navRow(href, title, sub, extra) {
    return '<a class="li tap" href="' + esc(href) + '"><div class="grow"><div class="t">' + esc(title) + '</div>' + (sub ? '<div class="s sans">' + sub + '</div>' : "") + '</div>' + (extra || "") + '<span class="chev-right"></span></a>';
  }

  /* ---------- More ---------- */

  FW.route(/^#\/more$/, FW.requireSite(function (m, site) {
    FW.screen({ title: "More", sub: site.name, back: "#/home", tab: "more" });
    var cf = FW.state.cf || {};
    var st = site.settings || {};
    var feats = [site.ecommerce ? "shop" : null, st.forms ? "forms" : null, st.media !== false ? "media" : null].filter(Boolean).join(", ") || "static only";

    FW.view('<div class="stack">' +
      '<div class="card list">' +
        navRow("#/cloudflare", "Cloudflare", cf.connected ? esc(cf.account_name || cf.account_id) + ' · ' + esc(cf.method === "token" ? "API token" : (cf.method === "oauth" ? "OAuth" : "connected")) : '<span class="txt-orange">Not connected</span>',
          '<span class="pill ' + (cf.connected ? "green" : "orange") + '"><span class="dot"></span>' + (cf.connected ? "Connected" : "Connect") + '</span>') +
        navRow("#/site-settings", "Site settings", esc(feats) + " · " + esc(st.template || "default") + " template") +
        navRow("#/payments", "Payments", site.ecommerce ? (site.stripe_connected ? "Stripe connected" + (site.stripe_method ? " · " + esc(site.stripe_method === "key" ? "API key" : "Stripe Connect") : "") : '<span class="txt-orange">Stripe not connected</span>') : "Shop is off for this site") +
        navRow("#/domain", "Domain", site.domain ? esc(site.domain) : "workers.dev address" + (site.live_url ? ": " + esc(FW.hostOf(site.live_url)) : "")) +
        navRow("#/deploys", "Deploys", site.latest_deployment ? "v" + esc(site.latest_deployment.version) + " · " + esc(FW.ago(site.latest_deployment.created_at)) : "No deploys yet") +
      '</div>' +
      '<div class="card list">' +
        navRow("#/sites", "Switch site", FW.state.sites.length + (FW.state.sites.length === 1 ? " site" : " sites")) +
        '<a class="li tap" href="' + esc(FW.cfg.classic_admin_url || "/admin") + '"><div class="grow"><div class="t">Open classic Microweber admin</div><div class="s sans">Modules, users, templates and everything else</div></div><span class="chev-right"></span></a>' +
      '</div>' +
      '<div class="card"><div class="h2">Export and import</div><div class="sub mb10">Move FlareWeber sites and their Cloudflare connections between installs from the command line.</div>' +
        '<div class="code">php artisan flareweber:export sites.json\nphp artisan flareweber:import sites.json</div>' +
        '<div class="sub mt8">Run these in the Microweber root. Content itself travels with the regular Microweber backup.</div></div>' +
      '<div class="card"><div class="h2">About</div><div class="sub">FlareWeber compiles this Microweber site to static pages plus a Cloudflare Worker for cart, checkout and forms. Content stays here; visitors hit the edge.</div>' +
        '<div class="kv mt8"><span>Signed in as</span><span>' + esc(FW.cfg.user_name || "Admin") + '</span></div>' +
        '<div class="kv"><span>Running in</span><span>' + (FW.cfg.desktop ? "FlareWeber desktop" : "browser") + '</span></div>' +
        (site.resources ? '<div class="kv"><span>Worker</span><span>' + esc(site.resources.worker || site.worker || "not created") + '</span></div>' +
          '<div class="kv"><span>D1</span><span>' + esc(site.resources.d1 || "none") + '</span></div><div class="kv"><span>R2</span><span>' + esc(site.resources.r2 || "none") + '</span></div>' : "") +
      '</div></div>');
  }));

  /* ---------- Cloudflare accounts ---------- */

  FW.route(/^#\/cloudflare$/, function () {
    FW.screen({ title: "Cloudflare", back: "#/more", nav: !!FW.state.site });
    FW.loading();
    FW.loadCloudflare().then(function (cf) {
      var conns = cf.connections && cf.connections.length ? cf.connections : (cf.connection_id ? [{ id: cf.connection_id, account_id: cf.account_id, account_name: cf.account_name, method: cf.method, status: cf.connected ? "connected" : "disconnected" }] : []);
      var site = FW.state.site;
      FW.view('<div class="stack">' +
        (conns.length ? '<div class="card list">' + conns.map(function (c) {
          var ok = c.status === "connected" || (c.status == null && cf.connected);
          var current = site && String(site.cloudflare_connection_id) === String(c.id);
          return '<div class="li"><div class="grow"><div class="t">' + esc(c.account_name || c.account_id || "Account") + (current ? ' <span class="pill blue">this site</span>' : "") + '</div>' +
            '<div class="s">' + esc(c.account_id || "") + ' · ' + esc(c.method === "token" ? "API token" : (c.method === "oauth" ? "OAuth" : "connected")) + '</div></div>' +
            (ok ? '<span class="pill green"><span class="dot"></span>Connected</span><button class="btn small danger" data-act="disconnect" data-id="' + esc(c.id) + '">Disconnect</button>'
                : '<span class="pill orange"><span class="dot"></span>Disconnected</span>') +
            (!current && site && ok ? '<button class="btn small" data-act="use" data-id="' + esc(c.id) + '">Use for this site</button>' : "") + '</div>';
        }).join("") + '</div>' : '<div class="card"><div class="h2">No Cloudflare account</div><div class="sub">Connect one to publish. Sites you already created keep their data.</div></div>') +
        '<div class="btn-row"><a class="btn" href="#/welcome/add">' + (conns.length ? "Add another account" : "Connect Cloudflare") + '</a>' +
        (conns.length && !cf.connected ? '<a class="btn primary" href="#/welcome/add">Reconnect</a>' : "") + '</div>' +
        '<div class="sub">Each site publishes into one account: one Worker, one D1 database and one R2 bucket per site. Free tier friendly.</div></div>', {
        disconnect: function (el) {
          FW.confirm({ title: "Disconnect this account?", text: "Publishing stops until you reconnect. Deployed sites keep running and keep their data.", ok: "Disconnect", danger: true }).then(function (ok) {
            if (!ok) return;
            api("/cloudflare/" + el.getAttribute("data-id") + "/disconnect", { method: "POST" })
              .then(function () { toast("Disconnected"); FW.loadSites().catch(function () {}).then(function () { FW.render(); }); })
              .catch(function (e) { toast(e.message, true); });
          });
        },
        use: function (el) {
          api("/sites/" + site.id, { method: "PUT", body: { cloudflare_connection_id: Number(el.getAttribute("data-id")) } })
            .then(function () { toast("Site now publishes to this account"); return FW.refreshSite(); }).then(function () { FW.render(); })
            .catch(function (e) { toast(e.message, true); });
        }
      });
    }).catch(function (e) { FW.error(e, "#/cloudflare"); });
  });

  /* ---------- Site settings ---------- */

  FW.route(/^#\/site-settings$/, FW.requireSite(function (m, site) {
    FW.screen({ title: "Site settings", back: "#/more" });
    var st = site.settings || {};
    var tpl = st.template || "default";
    function sw(id, on) { return '<label class="switch"><input type="checkbox" id="' + id + '"' + (on ? " checked" : "") + '><span></span></label>'; }

    FW.view('<form class="stack" data-form="save">' +
      '<div class="card"><label class="lbl" for="s-name">Site name</label><input type="text" id="s-name" value="' + esc(site.name) + '" maxlength="120"></div>' +
      '<div class="card">' +
        '<div class="toggle-row"><div><div class="t">Sell products</div><div class="s">Cart, checkout, orders and stock (Stripe + D1)</div></div>' + sw("s-ecom", site.ecommerce) + '</div>' +
        '<div class="toggle-row"><div><div class="t">Contact forms</div><div class="s">Form entries are stored in the site database</div></div>' + sw("s-forms", !!st.forms) + '</div>' +
        '<div class="toggle-row"><div><div class="t">Media library</div><div class="s">Sync uploads to an R2 bucket and serve from /media/</div></div>' + sw("s-media", st.media !== false) + '</div>' +
        '<div class="toggle-row"><div><div class="t">Sync inventory on publish</div><div class="s">Overwrite live stock counts with the numbers here</div></div>' + sw("s-sync", st.sync_inventory !== false) + '</div>' +
      '</div>' +
      '<div><div class="section-title"><div class="h3">Template</div></div><div class="tpl-grid">' + ["default", "big"].map(function (t) {
        return '<button type="button" class="tpl' + (t === tpl ? " on" : "") + '" data-act="tpl" data-id="' + t + '"><div class="art"><i style="width:70%"></i><i style="width:45%"></i></div><div class="name">' + (t === "default" ? "Default" : "Big") + '</div></button>';
      }).join("") + '</div></div>' +
      '<div class="card"><label class="lbl" for="s-email">Contact email</label><input type="email" id="s-email" value="' + esc(st.contact_email || "") + '" placeholder="hello@example.com"><div class="sub mt8">Form entries and order notices are sent here.</div></div>' +
      '<button type="submit" class="btn primary block lg">Save settings</button>' +
      '<div class="sub">Changes apply on the next publish.</div></form>', {
      tpl: function (el) { tpl = el.getAttribute("data-id"); document.querySelectorAll(".tpl").forEach(function (b) { b.className = "tpl" + (b.getAttribute("data-id") === tpl ? " on" : ""); }); },
      save: function (form) {
        var btn = form.querySelector("[type=submit]");
        var name = document.getElementById("s-name").value.trim();
        if (!name) { toast("The site needs a name", true); return; }
        btn.disabled = true;
        api("/sites/" + site.id, { method: "PUT", body: { name: name, settings: {
          ecommerce: document.getElementById("s-ecom").checked, forms: document.getElementById("s-forms").checked, media: document.getElementById("s-media").checked,
          sync_inventory: document.getElementById("s-sync").checked, template: tpl, contact_email: document.getElementById("s-email").value.trim() || null
        } } }).then(function () { toast("Saved. Republish to apply."); return FW.refreshSite(); }).then(function () { FW.go("#/more"); })
          .catch(function (e) { toast(e.message, true); btn.disabled = false; });
      }
    });
  }));

  /* ---------- Payments ---------- */

  FW.route(/^#\/payments$/, FW.requireSite(function (m, site) {
    FW.screen({ title: "Payments", back: "#/more" });
    FW.loading();
    api("/sites/" + site.id + "/stripe/status").catch(function (e) {
      if (e.status === 404) return { connected: !!site.stripe_connected, method: site.stripe_method || null, account_id: site.settings && site.settings.stripe_account_id, oauth_available: !!FW.cfg.stripe_oauth_available, _fallback: true };
      throw e;
    }).then(function (st) {
      var oauth = st.oauth_available != null ? !!st.oauth_available : !!FW.cfg.stripe_oauth_available;
      var html = '<div class="stack">' +
        (site.ecommerce ? "" : '<div class="card" style="border-color:var(--orange-bd);background:var(--orange-bg)">Selling is switched off for this site. Turn on <a href="#/site-settings">Sell products</a> to take payments.</div>') +
        '<div class="card"><div class="row"><span class="dot ' + (st.connected ? "green" : "grey") + '"></span><div class="grow"><div class="h2">' + (st.connected ? "Stripe connected" : "Stripe not connected") + '</div>' +
          '<div class="sub">' + (st.connected ? esc(st.account_id || "") + (st.method ? " · " + (st.method === "key" ? "API key" : "Stripe Connect") : "") : "Connect Stripe so checkout works on the live shop.") + '</div></div>' +
          (st.connected ? '<button class="btn small danger" data-act="disconnect">Disconnect</button>' : "") + '</div></div>';
      if (!st.connected) {
        html += '<div class="two-up">' +
          (oauth ? '<div class="card"><div class="h2">Connect with Stripe</div><div class="sub">Sign in to Stripe in your browser and authorise this site.</div><button class="btn primary block mt12" data-act="oauth">Connect with Stripe</button></div>' : "") +
          '<div class="card"><div class="h2">Use an API key</div><div class="sub">Paste a secret or restricted key from the Stripe dashboard (Developers, API keys).</div>' +
            '<div class="mt12"><label class="lbl" for="sk">Secret key</label><input type="password" id="sk" placeholder="sk_live_ or rk_live_" autocomplete="off"></div>' +
            '<button class="btn primary block mt12" data-act="key">Verify and save</button></div></div>';
      }
      html += '<div class="sub">Webhooks are registered automatically on publish. Refunds and disputes are handled in the Stripe dashboard.</div></div>';

      FW.view(html, {
        oauth: function (btn) {
          btn.disabled = true;
          FW.oauthHandoff("/sites/" + site.id + "/stripe/connect", function (r) {
            btn.disabled = false;
            if (r.status === "connected") { toast("Stripe connected"); FW.refreshSite().catch(function () {}).then(function () { FW.render(); }); }
            else toast(r.message || "Stripe connection failed", true);
          }, btn);
        },
        key: function (btn) {
          var key = document.getElementById("sk").value.trim();
          if (!key) { toast("Paste a Stripe secret key", true); return; }
          btn.disabled = true;
          api("/sites/" + site.id + "/stripe/key", { method: "POST", body: { secret_key: key } })
            .then(function () { toast("Stripe connected"); return FW.refreshSite(); }).then(function () { FW.render(); })
            .catch(function (e) { toast(e.message, true); btn.disabled = false; });
        },
        disconnect: function () {
          FW.confirm({ title: "Disconnect Stripe?", text: "Checkout stops working on the live shop after the next publish.", ok: "Disconnect", danger: true }).then(function (ok) {
            if (!ok) return;
            api("/sites/" + site.id + "/stripe/disconnect", { method: "POST" })
              .then(function () { toast("Stripe disconnected"); return FW.refreshSite(); }).then(function () { FW.render(); })
              .catch(function (e) { toast(e.message, true); });
          });
        }
      });
    }).catch(function (e) { FW.error(e, "#/payments"); });
  }));
})(window.FW);
</script>
@endverbatim
