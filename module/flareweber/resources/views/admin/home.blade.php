@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api;

  function typeLabel(t) { return { page: "Page", post: "Post", product: "Product" }[t] || "Content"; }
  function typeHash(t) { return t === "product" ? "#/shop" : (t === "post" ? "#/pages/posts" : "#/pages"); }

  function deployCard(site, dash, changes) {
    var running = FW.deploy.current && String(FW.deploy.current.siteId) === String(site.id) ? FW.deploy.current.dep : null;
    var dep = dash.latest_deployment || site.latest_deployment;
    var prodFailed = dep && dep.status === "failed" && dep.environment !== "preview";
    var n = changes.length;
    var state, dot, head, sub;

    if (running) {
      state = running.environment === "preview" ? "Building preview" : "Deploying"; dot = "blue";
      head = "Build in progress";
      var step = (running.steps || []).filter(function (s) { return s.status === "running"; })[0];
      sub = step ? step.label || step.key : "Waiting for the build to start";
    } else if (prodFailed) {
      state = "Last deploy failed"; dot = "red";
      head = n ? n + (n === 1 ? " change" : " changes") + " waiting to publish" : "The last build did not finish";
      sub = "Open the build log to see what went wrong, then publish again.";
    } else if (site.published_at) {
      state = "Live on Cloudflare"; dot = "green";
      head = n ? n + (n === 1 ? " change" : " changes") + " since last publish" : "Everything is published";
      sub = n ? changes.slice(0, 3).map(function (c) { return c.title; }).join(", ") + (n > 3 ? " and " + (n - 3) + " more" : "") : "Published " + FW.ago(site.published_at) + (site.live_url ? " to " + FW.hostOf(site.live_url) : "");
    } else {
      state = "Never published"; dot = "grey";
      head = "Ready to go live";
      sub = site.cloudflare_connected ? "Publish to get a workers.dev address for this site." : "Connect Cloudflare in More, then publish.";
    }

    return '<div class="deploy-card"><div class="state"><span class="dot ' + dot + '"></span>' + esc(state) + '</div>' +
      '<div class="head">' + esc(head) + '</div><div class="sub">' + esc(sub) + '</div>' +
      '<div class="btn-row">' + (running
        ? '<a class="btn primary" href="#/publish/build/' + esc(running.id) + '">View progress</a>'
        : '<a class="btn primary" href="#/publish">Publish</a><button class="btn" data-act="preview" style="flex:0 0 auto">Preview</button>' +
          (prodFailed ? '<a class="btn" href="#/deploys" style="flex:0 0 auto">Log</a>' : "")) +
      '</div></div>';
  }

  function stats(site, dash) {
    var low = (dash.low_stock || []).length;
    var tiles = [
      ['<a class="stat" href="#/pages"><b>' + esc(dash.pages != null ? dash.pages : 0) + '</b><span>Pages</span></a>']
    ];
    if (site.ecommerce) {
      tiles.push('<a class="stat" href="#/shop"><b>' + esc(dash.products != null ? dash.products : 0) + '</b><span>Products</span></a>');
      tiles.push('<a class="stat" href="#/shop/orders"><b>' + (dash.orders_open == null ? '<span style="font-size:13px;color:var(--dim)">none</span>' : esc(dash.orders_open)) + '</b><span>To fulfil</span></a>');
      tiles.push('<a class="stat" href="#/shop"><b class="' + (low ? "warn" : "") + '">' + low + '</b><span>Low stock</span></a>');
    } else {
      tiles.push('<a class="stat" href="#/media"><b>' + esc(dash.media != null ? dash.media : "") + (dash.media == null ? '<span style="font-size:13px;color:var(--dim)">library</span>' : "") + '</b><span>Media</span></a>');
      tiles.push('<a class="stat" href="#/deploys"><b>' + esc(dash.latest_deployment ? "v" + dash.latest_deployment.version : "v0") + '</b><span>Live version</span></a>');
      tiles.push('<a class="stat" href="#/publish"><b>' + esc((dash.unpublished_changes || []).length) + '</b><span>Changes</span></a>');
    }
    return '<div class="stats">' + tiles.join("") + '</div>';
  }

  function jumpList(site, dash, orders) {
    var items = [];
    (dash.unpublished_changes || []).slice(0, 3).forEach(function (c) {
      items.push({ icon: "blue", svg: '<svg viewBox="0 0 24 24"><path d="M4 20h4l10-10-4-4L4 16z"/><path d="m12 8 4 4"/></svg>',
        t: "Edit " + (c.title || typeLabel(c.type).toLowerCase()), s: typeLabel(c.type) + " · edited " + FW.ago(c.updated_at), href: typeHash(c.type) });
    });
    (dash.low_stock || []).slice(0, 2).forEach(function (p) {
      items.push({ icon: "orange", svg: '<svg viewBox="0 0 24 24"><path d="M12 4 3 20h18z"/><path d="M12 10v5M12 17.5v.5"/></svg>',
        t: (p.title || "A product") + " is down to " + (p.qty == null ? "0" : p.qty), s: "Restock or hide it from the shop", href: "#/shop" });
    });
    (orders || []).filter(function (o) { return o.status === "paid" || o.status === "processing"; }).slice(0, 2).forEach(function (o) {
      items.push({ icon: "", svg: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="7"/></svg>',
        t: "Order #" + o.id + " needs packing", s: "Paid " + FW.ago(o.created_at) + " · " + FW.money(o.total_cents, o.currency), href: "#/shop/orders" });
    });
    if (!items.length) {
      return '<div class="card"><div class="h2">You are all caught up</div><div class="sub">Edits you make in the site editor show up here until they are published.</div>' +
        '<div class="btn-row mt12"><a class="btn soft" href="' + esc(site.live_url ? "#/pages" : "#/pages") + '">Edit pages</a>' + (site.ecommerce ? '<a class="btn soft" href="#/shop">Open shop</a>' : "") + '</div></div>';
    }
    return '<div class="card list">' + items.map(function (i) {
      return '<a class="li tap" href="' + esc(i.href) + '"><div class="icon-box ' + i.icon + '">' + i.svg + '</div><div class="grow"><div class="t ellip">' + esc(i.t) + '</div><div class="s sans">' + esc(i.s) + '</div></div><span class="chev-right"></span></a>';
    }).join("") + '</div>';
  }

  FW.route(/^#\/home$/, FW.requireSite(function (m, site) {
    FW.screen({ title: site.name, sub: site.domain || FW.hostOf(site.live_url) || (site.worker ? site.worker + " (not published)" : "not published yet"),
      avatar: true, switcher: true, tab: "home",
      right: site.live_url ? '<a class="btn icon" href="' + esc(site.live_url) + '" target="_blank" rel="noopener" title="Open site"><svg viewBox="0 0 24 24"><path d="M14 5h5v5M19 5l-8 8M9 6H5v13h13v-4"/></svg></a>' : "" });
    FW.loading();

    var ordersReq = site.ecommerce && site.published_at
      ? api("/api/sites/" + site.id + "/orders").catch(function () { return []; })
      : Promise.resolve([]);

    Promise.all([api("/api/dashboard?site=" + site.id), ordersReq]).then(function (r) {
      var dash = r[0] || {}, orders = Array.isArray(r[1]) ? r[1] : (r[1] && r[1].orders) || [];
      var changes = dash.unpublished_changes || [];
      FW.view('<div class="cols two"><div class="stack">' + deployCard(site, dash, changes) + stats(site, dash) + '</div>' +
        '<div><div class="h3">Jump back in</div>' + jumpList(site, dash, orders) + '</div></div>', {
        preview: function (btn) { FW.startDeploy(site, "preview", btn); }
      });
    }).catch(function (e) {
      if (e.status === 404) {
        FW.view('<div class="stack">' + deployCard(site, {}, []) +
          '<div class="card"><div class="h2">Dashboard data is not available yet</div><div class="sub">' + esc(e.message) + '</div></div></div>',
          { preview: function (btn) { FW.startDeploy(site, "preview", btn); } });
        return;
      }
      FW.error(e, "#/home");
    });
  }));

  // Re-render Home when a background deployment finishes so the state card is fresh.
  FW.deploy.onUpdate(function (dep, done) {
    if (done && location.hash === "#/home") FW.render();
  });
})(window.FW);
</script>
@endverbatim
