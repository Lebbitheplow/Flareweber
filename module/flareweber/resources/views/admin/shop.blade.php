@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;
  var LOW = 5;

  function currencyOf(site) { return (site.settings && site.settings.currency) || "USD"; }
  function isLow(p) { return p.qty != null && Number(p.qty) <= LOW; }

  function productRow(p, cur) {
    var unlimited = p.qty == null;
    var img = p.image ? '<img class="thumb" src="' + esc(p.image) + '" alt="" loading="lazy">' : '<div class="thumb"></div>';
    return '<div class="li">' + img +
      '<button class="grow tap" data-act="open" data-id="' + esc(p.id) + '" style="border:0;background:none;text-align:left;padding:0;font:inherit;min-width:0">' +
        '<div class="t ellip">' + esc(p.title || "(untitled)") + (p.is_active === false ? ' <span class="pill orange">Draft</span>' : "") + '</div>' +
        '<div class="s ellip">' + esc(FW.price(p.price, p.currency || cur)) + (p.sku ? " · SKU " + esc(p.sku) : "") + '</div></button>' +
      '<div class="stepper">' + (unlimited
        ? '<span class="sub" style="padding:0 6px">unlimited</span>'
        : '<button data-act="dec" data-id="' + esc(p.id) + '" aria-label="Decrease stock"' + (Number(p.qty) <= 0 ? " disabled" : "") + '>&minus;</button>' +
          '<b class="' + (isLow(p) ? "warn" : "") + '" id="qty-' + esc(p.id) + '">' + esc(p.qty) + '</b>' +
          '<button data-act="inc" data-id="' + esc(p.id) + '" aria-label="Increase stock">+</button>') +
      '</div></div>';
  }

  function emptyShop(site, reason) {
    var text = reason === "no_database"
      ? "Orders are stored in the site database on Cloudflare, which is created on the first publish."
      : (reason === "unpublished" ? "Publish the site first. Orders placed on the live shop show up here." : reason);
    return '<div class="empty"><b>No orders yet</b>' + esc(text) + (reason === "unpublished" ? '<br><a class="btn soft" href="#/publish">Publish now</a>' : "") + '</div>';
  }

  /* ---------- products ---------- */

  FW.route(/^#\/shop$/, FW.requireSite(function (m, site) {
    if (!site.ecommerce) { FW.go("#/home"); return; }
    FW.screen({ title: "Shop", back: "#/home", tab: "shop", right: '<button class="btn navy small" data-act="add">Add</button>' });
    FW.loading();
    var filter = "all";

    api("/api/products").then(function (list) {
      list = Array.isArray(list) ? list : (list.products || list.data || []);
      var cur = currencyOf(site);
      var byId = {};
      list.forEach(function (p) { byId[String(p.id)] = p; });
      var low = list.filter(isLow).length;

      function body() {
        var rows = list.filter(function (p) {
          if (filter === "low") return isLow(p);
          if (filter === "live") return p.is_active !== false;
          if (filter === "draft") return p.is_active === false;
          return true;
        });
        return '<div class="chips">' +
          '<button class="chip' + (filter === "all" ? " on" : "") + '" data-act="filter" data-id="all">All ' + list.length + '</button>' +
          '<button class="chip' + (filter === "low" ? " on" : (low ? " warn" : "")) + '" data-act="filter" data-id="low"><span class="dot orange"></span>Low stock ' + low + '</button>' +
          '<button class="chip' + (filter === "live" ? " on" : "") + '" data-act="filter" data-id="live">Published</button>' +
          '<button class="chip' + (filter === "draft" ? " on" : "") + '" data-act="filter" data-id="draft">Drafts</button></div>' +
          (rows.length ? '<div class="card list">' + rows.map(function (p) { return productRow(p, cur); }).join("") + '</div>'
            : '<div class="empty"><b>' + (list.length ? "Nothing matches" : "No products yet") + '</b>' + (list.length ? "" : "Add your first product to start selling.") + '</div>');
      }

      var tabs = '<div class="tabs"><a href="#/shop" class="on">Products<b>' + list.length + '</b></a><a href="#/shop/orders">Orders</a></div>';
      FW.view(tabs + '<div id="shop-body">' + body() + '</div>', {
        filter: function (el) { filter = el.getAttribute("data-id"); document.getElementById("shop-body").innerHTML = body(); },
        open: function (el) { var p = byId[el.getAttribute("data-id")]; if (p && p.edit_url) window.location.href = p.edit_url; else if (p && p.url) window.open(p.url, "_blank"); },
        inc: function (el) { step(byId[el.getAttribute("data-id")], 1); },
        dec: function (el) { step(byId[el.getAttribute("data-id")], -1); },
        add: function () {
          FW.prompt({ title: "New product", fields: [{ name: "title", label: "Title", placeholder: "Fern Trio Set" }, { name: "price", label: "Price (" + cur + ")", type: "number", step: "0.01", min: 0, placeholder: "34.00" }], ok: "Create" })
            .then(function (v) {
              if (!v) return;
              if (!(v.title || "").trim()) { toast("Give it a title", true); return; }
              api("/api/products", { method: "POST", body: { title: v.title.trim(), price: v.price === "" ? undefined : Number(v.price) } })
                .then(function (r) { toast("Product created"); if (r && r.edit_url) window.location.href = r.edit_url; else FW.render(); })
                .catch(function (e) { toast(e.message, true); });
            });
        }
      });

      function step(p, delta) {
        if (!p || p.qty == null) return;
        var next = Math.max(0, Number(p.qty) + delta);
        if (next === Number(p.qty)) return;
        var el = document.getElementById("qty-" + p.id);
        if (el) el.textContent = next;
        api("/api/products/" + p.id, { method: "PATCH", body: { qty: next } }).then(function () {
          p.qty = next;
          toast((p.title || "Product") + ": stock " + next);
          low = list.filter(isLow).length;
          document.getElementById("shop-body").innerHTML = body();
        }).catch(function (e) { toast(e.message, true); if (el) el.textContent = p.qty; });
      }
    }).catch(function (e) { FW.error(e, "#/shop"); });
  }));

  /* ---------- orders ---------- */

  var GROUPS = [
    { key: "packing", label: "Needs packing", statuses: ["paid", "processing"] },
    { key: "waiting", label: "Waiting on payment", statuses: ["pending", "unpaid"] },
    { key: "done", label: "Fulfilled", statuses: ["fulfilled", "shipped", "completed"] },
    { key: "other", label: "Cancelled and refunded", statuses: ["cancelled", "canceled", "refunded", "expired", "failed"] }
  ];
  function statusPill(s) {
    var cls = { paid: "green", processing: "green", fulfilled: "blue", shipped: "blue", completed: "blue", pending: "orange", unpaid: "orange" }[s] || "grey";
    return '<span class="pill ' + cls + '">' + esc(s || "unknown") + '</span>';
  }

  FW.route(/^#\/shop\/orders$/, FW.requireSite(function (m, site) {
    if (!site.ecommerce) { FW.go("#/home"); return; }
    FW.screen({ title: "Orders", back: "#/shop", tab: "shop" });
    FW.loading();
    var tabs = '<div class="tabs"><a href="#/shop">Products</a><a href="#/shop/orders" class="on">Orders</a></div>';

    if (!site.published_at) { FW.view(tabs + emptyShop(site, "unpublished")); return; }

    api("/api/sites/" + site.id + "/orders").then(function (orders) {
      orders = Array.isArray(orders) ? orders : (orders.orders || orders.data || []);
      var byId = {};
      orders.forEach(function (o) { byId[String(o.id)] = o; });
      var open = orders.filter(function (o) { return o.status === "paid" || o.status === "processing"; }).length;
      FW.screen({ title: "Orders", sub: open + " open · " + orders.length + " total", back: "#/shop", tab: "shop" });

      var placed = {};
      var html = GROUPS.map(function (g) {
        var rows = orders.filter(function (o) { if (placed[o.id]) return false; var hit = g.statuses.indexOf(o.status) >= 0; if (hit) placed[o.id] = true; return hit; });
        if (!rows.length) return "";
        return '<div class="eyebrow-mono">' + esc(g.label) + ' · ' + rows.length + '</div><div class="card list mb10">' + rows.map(function (o) {
          return '<button class="li tap" data-act="open" data-id="' + esc(o.id) + '"><div class="grow"><div class="t">#' + esc(o.id) + ' ' + statusPill(o.status) +
            '</div><div class="s sans">' + esc(o.email || "no email") + ' · ' + esc((o.items || []).length) + (o.items && o.items.length === 1 ? " item" : " items") + ' · ' + esc(FW.ago(o.created_at)) + '</div></div>' +
            '<div class="t">' + esc(FW.money(o.total_cents, o.currency)) + '</div><span class="chev-right"></span></button>';
        }).join("") + '</div>';
      }).join("");
      var rest = orders.filter(function (o) { return !placed[o.id]; });
      if (rest.length) html += '<div class="eyebrow-mono">Other · ' + rest.length + '</div><div class="card list">' + rest.map(function (o) {
        return '<button class="li tap" data-act="open" data-id="' + esc(o.id) + '"><div class="grow"><div class="t">#' + esc(o.id) + ' ' + statusPill(o.status) + '</div></div><div class="t">' + esc(FW.money(o.total_cents, o.currency)) + '</div></button>';
      }).join("") + '</div>';

      FW.view(tabs + (orders.length ? html : emptyShop(site, "Orders placed on the live shop show up here.")), {
        open: function (el) { orderSheet(site, byId[el.getAttribute("data-id")]); }
      });
    }).catch(function (e) {
      if (e.code === "no_database" || e.status === 501) FW.view(tabs + emptyShop(site, "no_database"));
      else FW.error(e, "#/shop/orders");
    });
  }));

  function orderSheet(site, o) {
    if (!o) return;
    var items = (o.items || []).map(function (i) {
      var qty = i.quantity != null ? i.quantity : (i.qty != null ? i.qty : 1);
      var unit = i.unit_price_cents != null ? i.unit_price_cents : (i.price_cents != null ? i.price_cents : 0);
      return '<div class="kv"><span style="color:var(--text)">' + esc(i.title || i.name || "Item") + '</span><span>' + esc(qty) + ' × ' + esc(FW.money(unit, o.currency)) + '</span></div>';
    }).join("");
    var canFulfil = o.status === "paid" || o.status === "processing";
    var canCancel = o.status === "pending" || o.status === "unpaid";
    FW.sheet('<h3>Order #' + esc(o.id) + ' ' + statusPill(o.status) + '</h3><div class="sub">' + esc(FW.when(o.created_at)) + '</div>' +
      '<div class="card" style="padding:4px 14px">' + (items || '<div class="sub" style="padding:10px 0">No line items</div>') +
        (o.subtotal_cents != null ? '<div class="kv"><span>Subtotal</span><span>' + esc(FW.money(o.subtotal_cents, o.currency)) + '</span></div>' : "") +
        (o.shipping_cents != null ? '<div class="kv"><span>Shipping</span><span>' + esc(FW.money(o.shipping_cents, o.currency)) + '</span></div>' : "") +
        '<div class="kv"><span><b>Total</b></span><span><b>' + esc(FW.money(o.total_cents, o.currency)) + '</b></span></div></div>' +
      '<div class="card mt12"><div class="eyebrow">Customer</div><div>' + esc(o.name || o.customer_name || "") + '</div>' +
        (o.email ? '<a href="mailto:' + esc(o.email) + '" data-act="mail" data-follow>' + esc(o.email) + '</a>' : '<span class="sub">No email on file</span>') +
        (o.address ? '<div class="sub mt8">' + esc(o.address) + '</div>' : "") + '</div>' +
      '<div class="btn-row mt16">' +
        (canFulfil ? '<button class="btn primary lg" data-act="fulfil">Mark as fulfilled</button>' : "") +
        (canCancel ? '<button class="btn danger" data-act="cancel">Cancel order</button>' : "") +
        (!canFulfil && !canCancel ? '<button class="btn" data-act="close">Close</button>' : "") +
      '</div>', {
      close: function () { FW.closeSheet(); },
      mail: function () {},
      fulfil: function (btn) { setStatus(site, o, "fulfilled", btn); },
      cancel: function (btn) {
        FW.closeSheet();
        FW.confirm({ title: "Cancel order #" + o.id + "?", text: "The customer is not notified automatically.", ok: "Cancel order", danger: true })
          .then(function (ok) { if (ok) setStatus(site, o, "cancelled"); });
      }
    });
  }

  function setStatus(site, o, status, btn) {
    if (btn) btn.disabled = true;
    api("/api/sites/" + site.id + "/orders/" + o.id, { method: "PATCH", body: { status: status } })
      .then(function () { FW.closeSheet(); toast("Order #" + o.id + " " + status); FW.render(); })
      .catch(function (e) { toast(e.message, true); if (btn) btn.disabled = false; });
  }
})(window.FW);
</script>
@endverbatim
