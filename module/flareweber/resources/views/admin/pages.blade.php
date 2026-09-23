@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;

  function editUrl(p) {
    if (p.edit_url) return p.edit_url;
    if (!p.url) return null;
    return p.url + (p.url.indexOf("?") >= 0 ? "&" : "?") + "editmode=y";
  }
  function flatten(tree, depth, out) {
    (tree || []).forEach(function (n) {
      out.push({ node: n, depth: depth });
      if (n.children && n.children.length) flatten(n.children, depth + 1, out);
    });
    return out;
  }
  function pathOf(url) {
    try { var u = new URL(url, location.origin); return u.pathname === "/" ? "/" : u.pathname.replace(/\/$/, ""); } catch (e) { return url || ""; }
  }

  function row(item) {
    var p = item.node;
    var indent = "";
    for (var i = 0; i < item.depth; i++) indent += '<span class="tree-indent"></span>';
    return '<div class="li">' + indent + '<button class="grow tap" data-act="open" data-id="' + esc(p.id) + '" style="border:0;background:none;text-align:left;padding:0;font:inherit;min-width:0">' +
      '<div class="t ellip">' + esc(p.title || "(untitled)") + (p.is_active === false ? ' <span class="pill grey">Hidden</span>' : "") + '</div>' +
      '<div class="s ellip">' + esc(pathOf(p.url)) + (p.updated_at ? ' · ' + esc(FW.ago(p.updated_at)) : "") + '</div></button>' +
      '<button class="btn icon" data-act="menu" data-id="' + esc(p.id) + '" aria-label="More"><span class="dots"><i></i><i></i><i></i></span></button></div>';
  }

  FW.route(/^#\/pages(\/(posts|drafts))?$/, FW.requireSite(function (m, site) {
    var tab = m[2] || "pages";
    var resource = tab === "posts" ? "posts" : "pages";
    FW.screen({ title: tab === "posts" ? "Posts" : (tab === "drafts" ? "Drafts" : "Pages"), back: "#/home", tab: "pages",
      right: tab === "drafts" ? "" : '<button class="btn navy small" data-act="add">' + (tab === "posts" ? "Add post" : "Add page") + '</button>' });
    FW.loading();

    Promise.all([api("/api/pages"), api("/api/posts").catch(function () { return []; })]).then(function (r) {
      var pages = flatten(Array.isArray(r[0]) ? r[0] : (r[0].pages || r[0].data || []), 0, []);
      var posts = flatten(Array.isArray(r[1]) ? r[1] : (r[1].posts || r[1].data || []), 0, []);
      var drafts = pages.concat(posts).filter(function (i) { return i.node.is_active === false; }).map(function (i) { return { node: i.node, depth: 0 }; });
      var list = tab === "posts" ? posts : (tab === "drafts" ? drafts : pages);
      var all = {};
      pages.concat(posts).forEach(function (i) { all[String(i.node.id)] = i.node; });

      var tabs = '<div class="tabs"><a href="#/pages" class="' + (tab === "pages" ? "on" : "") + '">Pages<b>' + pages.length + '</b></a>' +
        '<a href="#/pages/posts" class="' + (tab === "posts" ? "on" : "") + '">Posts<b>' + posts.length + '</b></a>' +
        '<a href="#/pages/drafts" class="' + (tab === "drafts" ? "on" : "") + '">Drafts<b>' + drafts.length + '</b></a></div>';

      var body = list.length ? '<div class="card list">' + list.map(row).join("") + '</div>' +
        '<div class="sub mt12">Tap a row to open it in the editor. Use the menu for hide, show and delete.</div>'
        : '<div class="empty"><b>' + (tab === "drafts" ? "No drafts" : "Nothing here yet") + '</b>' +
          (tab === "drafts" ? "Hidden pages and posts show up here." : "Create your first " + (tab === "posts" ? "post" : "page") + " to get started.") + '</div>';

      FW.view(tabs + body, {
        open: function (el) {
          var p = all[el.getAttribute("data-id")];
          var url = p && editUrl(p);
          if (url) window.location.href = url; else toast("This item has no editor link", true);
        },
        add: function () { addContent(resource); },
        menu: function (el) {
          var p = all[el.getAttribute("data-id")];
          if (!p) return;
          var res = p.content_type === "post" ? "posts" : "pages";
          FW.sheet('<h3>' + esc(p.title || "(untitled)") + '</h3><div class="sub mono">' + esc(pathOf(p.url)) + '</div><div class="menu">' +
            '<button data-act="edit">Edit in the site editor</button>' +
            (p.url ? '<a href="' + esc(p.url) + '" target="_blank" rel="noopener" data-act="view" data-follow>Open on the site</a>' : "") +
            '<button data-act="toggle">' + (p.is_active === false ? "Show on the site" : "Hide from the site") + '</button>' +
            '<button class="danger" data-act="del">Delete</button></div>', {
            edit: function () { FW.closeSheet(); var u = editUrl(p); if (u) window.location.href = u; },
            view: function () { FW.closeSheet(); },
            toggle: function () {
              FW.closeSheet();
              api("/api/" + res + "/" + p.id, { method: "PATCH", body: { is_active: p.is_active === false } })
                .then(function () { toast(p.is_active === false ? "Now visible" : "Hidden from the site"); FW.render(); })
                .catch(function (e) { toast(e.message, true); });
            },
            del: function () {
              FW.closeSheet();
              FW.confirm({ title: "Delete " + (p.title || "this item") + "?", text: "It moves to the Microweber trash and disappears from the site on the next publish.", ok: "Delete", danger: true }).then(function (ok) {
                if (!ok) return;
                api("/api/" + res + "/" + p.id, { method: "DELETE" }).then(function () { toast("Deleted"); FW.render(); }).catch(function (e) { toast(e.message, true); });
              });
            }
          });
        }
      });
    }).catch(function (e) { FW.error(e, "#/pages"); });
  }));

  function addContent(resource) {
    FW.prompt({ title: resource === "posts" ? "New post" : "New page", fields: [{ name: "title", label: "Title", placeholder: resource === "posts" ? "Six ferns that survive a dark flat" : "About us" }], ok: "Create" })
      .then(function (v) {
        if (!v) return;
        var title = (v.title || "").trim();
        if (!title) { toast("Give it a title", true); return; }
        toast("Creating");
        api("/api/" + resource, { method: "POST", body: { title: title } }).then(function (r) {
          toast("Created");
          if (r && r.edit_url) window.location.href = r.edit_url; else FW.render();
        }).catch(function (e) {
          if (e.status === 404 || e.status === 405) toast("Creating " + (resource === "posts" ? "posts" : "pages") + " here is not available yet. Use the classic admin.", true);
          else toast(e.message, true);
        });
      });
  }
})(window.FW);
</script>
@endverbatim
