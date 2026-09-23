/*
 * FlareWeber store shim.
 *
 * Injected into every compiled page (as /fw/store.js) when the site has
 * ecommerce or forms enabled. The static build has no Microweber backend,
 * so this shim reroutes shop and form interactions to the Worker API
 * (/api/cart, /api/checkout, /api/forms), renders its own cart drawer and
 * fills the order summary on /thank-you/.
 *
 * Config comes from window.FW_FEATURES = {ecommerce: bool, forms: bool}.
 */
(function () {
  "use strict";

  var API = "/api";
  var features = window.FW_FEATURES || { ecommerce: true, forms: true };
  var CART_NOT_CONFIGURED = "Checkout is not available yet: the shop has not been configured. Please try again later.";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function api(path, options) {
    options = options || {};
    options.headers = Object.assign({ Accept: "application/json" }, options.headers);
    options.credentials = "same-origin";
    if (options.body && typeof options.body === "object" && !(options.body instanceof FormData)) {
      options.body = JSON.stringify(options.body);
      options.headers["Content-Type"] = "application/json";
    }
    return fetch(API + path, options).then(function (res) {
      if (!res.ok) {
        return res.json().catch(function () { return {}; }).then(function (d) {
          var err = new Error(messageFor(res.status, d));
          err.code = d.error || "";
          err.status = res.status;
          throw err;
        });
      }
      return res.status === 204 ? {} : res.json();
    });
  }

  function messageFor(status, data) {
    var code = data && data.error;
    if (status === 503 && code === "cart_not_configured") return CART_NOT_CONFIGURED;
    if (code === "insufficient_stock") {
      return "Not enough stock" + (data.available != null ? " (only " + data.available + " left)" : "") + ".";
    }
    if (code === "currency_mismatch") return "This item uses a different currency than your cart.";
    if (code === "not_found") return "Item not found.";
    if (data && data.message) return data.message;
    return code ? code.replace(/_/g, " ") : "Request failed (" + status + ")";
  }

  var toastEl = null;
  function toast(msg, isError) {
    if (!toastEl) {
      toastEl = document.createElement("div");
      toastEl.setAttribute("role", "status");
      toastEl.style.cssText =
        "position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;" +
        "padding:10px 18px;border-radius:8px;font:14px/1.4 system-ui,sans-serif;color:#fff;" +
        "box-shadow:0 4px 16px rgba(0,0,0,.25);max-width:90vw;";
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.style.background = isError ? "#b3261e" : "#166534";
    toastEl.style.display = "block";
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.style.display = "none"; }, 4000);
  }

  /* ---------- ecommerce: cart drawer ---------- */

  var drawer = null;
  var cartCount = 0;
  var currency = "USD";

  function money(cents) {
    return (cents / 100).toLocaleString(undefined, { style: "currency", currency: currency || "USD" });
  }

  function inStock(quantity) {
    return quantity == null || quantity === -1 || quantity > 0;
  }

  function ensureFab() {
    var fab = document.getElementById("fw-cart-fab");
    if (fab) return fab;
    fab = document.createElement("button");
    fab.id = "fw-cart-fab";
    fab.setAttribute("aria-label", "Open cart");
    fab.style.cssText =
      "position:fixed;right:16px;bottom:16px;z-index:99997;width:56px;height:56px;" +
      "border-radius:50%;border:none;cursor:pointer;background:#111827;color:#fff;" +
      "font:16px system-ui,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,.3);";
    fab.addEventListener("click", openDrawer);
    document.body.appendChild(fab);
    return fab;
  }

  function renderFab() {
    ensureFab().innerHTML = cartCount ? "&#128722;<sup>" + cartCount + "</sup>" : "&#128722;";
  }

  function ensureDrawer() {
    if (drawer) return drawer;
    drawer = document.createElement("div");
    drawer.id = "fw-cart-drawer";
    drawer.style.cssText =
      "position:fixed;top:0;right:0;height:100%;width:min(420px,100vw);z-index:99998;" +
      "background:#fff;color:#111;transform:translateX(100%);transition:transform .2s ease;" +
      "box-shadow:-4px 0 24px rgba(0,0,0,.2);display:flex;flex-direction:column;" +
      "font:15px/1.5 system-ui,sans-serif;";
    drawer.innerHTML =
      '<div style="display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid #e5e7eb">' +
      '<strong style="flex:1">Your cart</strong>' +
      '<button id="fw-cart-close" style="border:none;background:none;font-size:20px;cursor:pointer" aria-label="Close">&times;</button>' +
      "</div>" +
      '<div id="fw-cart-items" style="flex:1;overflow:auto;padding:8px 16px"></div>' +
      '<div style="border-top:1px solid #e5e7eb;padding:12px 16px">' +
      '<div id="fw-cart-total" style="display:flex;justify-content:space-between;margin-bottom:8px"></div>' +
      '<input id="fw-cart-email" type="email" placeholder="Email for the order" style="width:100%;box-sizing:border-box;padding:8px;margin-bottom:8px;border:1px solid #d1d5db;border-radius:6px">' +
      '<button id="fw-cart-checkout" style="width:100%;padding:12px;border:none;border-radius:6px;background:#166534;color:#fff;font-weight:600;cursor:pointer">Checkout</button>' +
      '<div id="fw-cart-msg" style="min-height:20px;font-size:13px;color:#b3261e;margin-top:6px"></div>' +
      "</div>";
    document.body.appendChild(drawer);
    drawer.querySelector("#fw-cart-close").addEventListener("click", closeDrawer);
    drawer.querySelector("#fw-cart-checkout").addEventListener("click", checkout);
    return drawer;
  }

  function openDrawer() { ensureDrawer().style.transform = "translateX(0)"; loadCart(); }
  function closeDrawer() { if (drawer) drawer.style.transform = "translateX(100%)"; }
  function drawerOpen() { return drawer && drawer.style.transform === "translateX(0)"; }

  function itemQty(i) { return Number(i.quantity_in_cart != null ? i.quantity_in_cart : i.quantity) || 0; }
  function itemId(i) { return i.variant_id != null ? i.variant_id : i.id; }

  function loadCart() {
    return api("/cart").then(function (data) {
      var items = data.items || [];
      cartCount = items.reduce(function (n, i) { return n + itemQty(i); }, 0);
      currency = data.currency || data.total_currency || (items[0] && items[0].currency) || currency;
      renderFab();

      var list = drawer.querySelector("#fw-cart-items");
      if (!items.length) {
        list.innerHTML = '<p style="color:#6b7280">Your cart is empty.</p>';
      } else {
        list.innerHTML = items.map(function (i) {
          var id = itemId(i);
          return '<div style="display:flex;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid #f3f4f6">' +
            '<div style="flex:1">' + esc(i.product_title || i.title || "Item " + id) +
            '<div style="color:#6b7280;font-size:13px">' + money(i.price_cents) + " &times; " + itemQty(i) + "</div></div>" +
            '<button data-inc="' + esc(id) + '" style="cursor:pointer;border:1px solid #d1d5db;border-radius:4px;background:#fff">+</button>' +
            '<button data-del="' + esc(id) + '" style="cursor:pointer;border:1px solid #d1d5db;border-radius:4px;background:#fff" aria-label="Remove">&times;</button>' +
            "</div>";
        }).join("");
      }
      var total = data.total_cents != null ? data.total_cents :
        items.reduce(function (n, i) { return n + i.price_cents * itemQty(i); }, 0);
      drawer.querySelector("#fw-cart-total").innerHTML = "<strong>Total</strong><strong>" + money(total) + "</strong>";
      drawer.querySelector("#fw-cart-checkout").disabled = !items.length;

      list.querySelectorAll("[data-inc]").forEach(function (b) {
        b.addEventListener("click", function () { addToCart(Number(b.getAttribute("data-inc")), 1); });
      });
      list.querySelectorAll("[data-del]").forEach(function (b) {
        b.addEventListener("click", function () {
          api("/cart/items/" + b.getAttribute("data-del"), { method: "DELETE" }).then(loadCart).catch(showError);
        });
      });
    }).catch(showError);
  }

  function showError(err) {
    if (drawer) { drawer.querySelector("#fw-cart-msg").textContent = err.message; }
    toast(err.message, true);
  }

  function addToCart(variantId, qty) {
    return api("/cart/items", {
      method: "POST",
      body: { variant_id: variantId, quantity: qty || 1 },
    }).then(function (data) {
      cartCount = (data.items || []).reduce(function (n, i) { return n + itemQty(i); }, 0);
      renderFab();
      if (drawerOpen()) { loadCart(); }
      toast("Added to cart");
    }).catch(showError);
  }

  function checkout() {
    var msg = drawer.querySelector("#fw-cart-msg");
    msg.textContent = "";
    api("/checkout", {
      method: "POST",
      body: { email: drawer.querySelector("#fw-cart-email").value || null },
    }).then(function (data) {
      if (data.checkout_url) { window.location.href = data.checkout_url; return; }
      msg.textContent = "No checkout URL returned.";
    }).catch(showError);
  }

  // Compiled Microweber product ids map to the D1 default variant (id * 10).
  function variantIdFor(productId) {
    return Number(productId) * 10;
  }

  function bindAddToCart() {
    document.querySelectorAll("form").forEach(function (form) {
      var action = form.getAttribute("action") || "";
      var input = form.querySelector('input[name="id"], input[name="product_id"], input[name="content_id"]');
      var triggers = form.querySelector('[name="add-to-cart"], [data-add-to-cart]');
      if (!triggers && !/add[-_]?to[-_]?cart/i.test(action)) return;
      if (!input) return;
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var qty = form.querySelector('input[name="qty"], input[name="quantity"]');
        addToCart(variantIdFor(input.value), qty ? parseInt(qty.value, 10) || 1 : 1);
      });
    });

    // The compiler turns Microweber's inline mw.cart.add('.holder') calls into
    // data-fw-add-to-cart; the holder carries hidden content_id / for_id inputs.
    document.querySelectorAll("[data-fw-add-to-cart]").forEach(function (btn) {
      btn.addEventListener("click", function (ev) {
        ev.preventDefault();
        var holder = null;
        try { holder = document.querySelector(btn.getAttribute("data-fw-add-to-cart")); } catch (e) { holder = null; }
        var scope = holder || btn.closest(".product-item-single, .mw-add-to-cart-holder, .module-shop-cart-add, .product") || document;
        var idInput = scope.querySelector('input[name="content_id"], input[name="for_id"], input[name="product_id"], input[name="id"]');
        var withId = scope.querySelector("[data-content-id]");
        var productId = idInput ? idInput.value : (withId ? withId.getAttribute("data-content-id") : null);
        var qty = scope.querySelector('input[name="qty"], input[name="quantity"]');
        if (!productId) { toast("Could not determine which product to add.", true); return; }
        addToCart(variantIdFor(productId), qty ? parseInt(qty.value, 10) || 1 : 1);
      });
    });

    document.querySelectorAll("[data-fw-add-to-cart-id], .mw-add-to-cart[data-id], [data-add-to-cart][data-id]").forEach(function (btn) {
      if (btn.closest("form")) return;
      btn.addEventListener("click", function (ev) {
        ev.preventDefault();
        addToCart(variantIdFor(btn.getAttribute("data-fw-add-to-cart-id") || btn.getAttribute("data-id")), 1);
      });
    });
  }

  // On product pages, offer a guaranteed add-to-cart bar. The product is
  // resolved by slug (last path segment) through the Worker API.
  function bindProductPage() {
    var slug = decodeURIComponent(location.pathname.replace(/\/+$/, "").split("/").pop() || "");
    if (!slug || slug === location.pathname.replace(/\/+$/, "")) return;
    api("/products/" + encodeURIComponent(slug)).then(function (data) {
      var variant = (data.variants || [])[0];
      var product = data.product;
      if (!variant || !product) return;
      if (variant.currency) currency = variant.currency;
      var available = inStock(variant.quantity);

      var bar = document.createElement("div");
      bar.id = "fw-product-bar";
      bar.style.cssText =
        "position:sticky;bottom:0;z-index:99996;display:flex;gap:12px;align-items:center;" +
        "padding:12px 16px;background:#fff;border-top:1px solid #e5e7eb;font:15px/1.4 system-ui,sans-serif;";
      bar.innerHTML =
        '<div style="flex:1"><strong>' + esc(product.title) + "</strong><br>" +
        "<span>" + money(variant.price_cents) + "</span>" +
        (available ? "" : ' <span style="color:#b3261e">out of stock</span>') +
        "</div>" +
        (available
          ? '<button style="padding:12px 20px;border:none;border-radius:6px;background:#166534;color:#fff;font-weight:600;cursor:pointer">Add to cart</button>'
          : "");
      var btn = bar.querySelector("button");
      if (btn) {
        btn.addEventListener("click", function () { addToCart(variant.id, 1); });
      }
      document.body.appendChild(bar);
    }).catch(function () { /* not a product page */ });
  }

  /* ---------- thank-you page ---------- */

  function renderThankYou() {
    var target = document.getElementById("fw-order-summary");
    if (!target) return;
    var status = document.getElementById("fw-order-status");
    var sessionId = new URLSearchParams(location.search).get("session_id");
    if (!sessionId) {
      if (status) status.textContent = "We could not find your order reference.";
      return;
    }
    api("/orders/by-session/" + encodeURIComponent(sessionId)).then(function (order) {
      currency = order.currency || currency;
      var items = order.items || [];
      if (status) {
        status.textContent = "Order #" + order.id + (order.status ? " (" + order.status + ")" : "") +
          (order.email ? ", confirmation sent to " + order.email : "") + ".";
      }
      target.innerHTML =
        '<table style="width:100%;border-collapse:collapse;margin:16px 0">' +
        items.map(function (i) {
          var unit = i.unit_price_cents != null ? i.unit_price_cents : i.price_cents;
          return '<tr><td style="padding:6px 0;border-bottom:1px solid #e5e7eb">' + esc(i.title || "Item") +
            ' &times; ' + esc(i.quantity) + '</td><td style="text-align:right;border-bottom:1px solid #e5e7eb">' +
            money(unit * i.quantity) + "</td></tr>";
        }).join("") +
        '<tr><td style="padding:8px 0"><strong>Total</strong></td><td style="text-align:right"><strong>' +
        money(order.total_cents || 0) + "</strong></td></tr></table>";
    }).catch(function (err) {
      if (status) status.textContent = err.message;
    });
  }

  /* ---------- forms ---------- */

  function bindForms() {
    document.querySelectorAll("form").forEach(function (form) {
      var action = form.getAttribute("action") || "";
      var marked = form.hasAttribute("data-fw-form");
      var microweberEndpoint = /\/(proc|api\/v2|api|mw)(\/|$)/i.test(action) ||
        /contact/i.test(action) || /contact/i.test(form.className);
      if (!marked && !microweberEndpoint) return;
      if (/add[-_]?to[-_]?cart/i.test(action)) return;

      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var data = { _hp: "" };
        new FormData(form).forEach(function (v, k) {
          if (typeof v === "string") data[k] = v;
        });
        var name = form.getAttribute("data-fw-form") ||
          (action.replace(/\/+$/, "").split("/").pop() || "contact");

        api("/forms", { method: "POST", body: { form: name, data: data } })
          .then(function () {
            form.reset();
            toast("Message sent");
          })
          .catch(function (err) { toast(err.message, true); });
      });
    });
  }

  /* ---------- boot ---------- */

  function interceptShopLinks() {
    document.addEventListener("click", function (ev) {
      var link = ev.target.closest ? ev.target.closest("a") : null;
      if (!link || link.origin !== location.origin) return;
      if (/^\/(cart|checkout|account)(\/|$)/i.test(link.pathname || "")) {
        ev.preventDefault();
        openDrawer();
      }
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    if (features.ecommerce) {
      renderFab();
      bindAddToCart();
      bindProductPage();
      interceptShopLinks();
      renderThankYou();
    }
    if (features.forms) {
      bindForms();
    }
  });
})();
