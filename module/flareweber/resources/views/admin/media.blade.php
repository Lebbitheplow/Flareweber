@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;
  var IMG = /\.(jpe?g|png|gif|webp|avif|svg|bmp|ico)$/i;

  function nameOf(f) { return f.title || f.filename || String(f.url || "").split("/").pop() || "file"; }
  function ext(f) { var n = String(f.filename || f.url || ""); var m = n.match(/\.([a-z0-9]+)(\?.*)?$/i); return m ? m[1].toUpperCase() : "FILE"; }

  function tile(f) {
    var isImg = IMG.test(String(f.filename || f.url || ""));
    return '<button class="media-tile" data-act="open" data-id="' + esc(f.id) + '"><div class="img">' +
      (isImg && f.url ? '<img src="' + esc(f.url) + '" alt="" loading="lazy">' : esc(ext(f))) + '</div>' +
      '<div class="meta"><div class="n">' + esc(nameOf(f)) + '</div><div class="z">' + esc(f.size != null ? FW.bytes(f.size) : "") + '</div></div></button>';
  }

  FW.route(/^#\/media$/, FW.requireSite(function (m, site) {
    FW.screen({ title: "Media", back: "#/home", tab: "media",
      right: '<button class="btn navy small" data-act="upload">Upload</button><input type="file" id="media-file" multiple hidden data-change="files" accept="image/*,video/*,audio/*,.pdf,.zip,.txt,.csv">' });
    FW.loading();

    api("/api/media").then(function (files) {
      files = Array.isArray(files) ? files : (files.media || files.data || []);
      var byId = {};
      var total = 0;
      files.forEach(function (f) { byId[String(f.id)] = f; total += Number(f.size) || 0; });
      FW.screen({ title: "Media", sub: files.length + (files.length === 1 ? " file" : " files") + " · " + FW.bytes(total), back: "#/home", tab: "media",
        right: '<button class="btn navy small" data-act="upload">Upload</button><input type="file" id="media-file" multiple hidden data-change="files" accept="image/*,video/*,audio/*,.pdf,.zip,.txt,.csv">' });

      FW.view((files.length
        ? '<div class="media-grid">' + files.map(tile).join("") + '</div><div class="sub mt12">Files are synced to your site\'s R2 bucket on publish and served from /media/.</div>'
        : '<div class="empty"><b>No media yet</b>Upload images and files to use them in pages and products.<br><button class="btn soft" data-act="upload">Upload files</button></div>'), {
        upload: function () { document.getElementById("media-file").click(); },
        files: function (input) { uploadAll(input.files); },
        open: function (el) { fileSheet(byId[el.getAttribute("data-id")]); }
      });
    }).catch(function (e) { FW.error(e, "#/media"); });
  }));

  function uploadAll(fileList) {
    var files = Array.prototype.slice.call(fileList || []);
    if (!files.length) return;
    toast("Uploading " + files.length + (files.length === 1 ? " file" : " files"));
    var done = 0, failed = 0;
    var chain = Promise.resolve();
    files.forEach(function (f) {
      chain = chain.then(function () {
        var fd = new FormData();
        fd.append("file", f, f.name);
        return api("/api/media", { method: "POST", form: fd }).then(function () { done++; }).catch(function (e) { failed++; toast(f.name + ": " + e.message, true); });
      });
    });
    chain.then(function () {
      if (done) toast(done + (done === 1 ? " file uploaded" : " files uploaded"));
      FW.render();
    });
  }

  function fileSheet(f) {
    if (!f) return;
    var isImg = IMG.test(String(f.filename || f.url || ""));
    FW.sheet('<h3 class="ellip">' + esc(nameOf(f)) + '</h3>' +
      (isImg && f.url ? '<div style="border-radius:12px;overflow:hidden;background:var(--bg);margin-bottom:12px;max-height:40vh;display:flex;justify-content:center"><img src="' + esc(f.url) + '" alt="" style="max-width:100%;max-height:40vh;object-fit:contain"></div>' : "") +
      '<div class="card" style="padding:4px 14px">' +
        '<div class="kv"><span>Size</span><span>' + esc(f.size != null ? FW.bytes(f.size) : "unknown") + '</span></div>' +
        (f.updated_at ? '<div class="kv"><span>Updated</span><span>' + esc(FW.when(f.updated_at)) + '</span></div>' : "") +
        '<div class="kv"><span>URL</span><span>' + esc(f.url || "") + '</span></div></div>' +
      '<div class="btn-row mt16">' +
        (f.url ? '<a class="btn" href="' + esc(f.url) + '" target="_blank" rel="noopener" data-act="view" data-follow>Open</a><button class="btn" data-act="copy">Copy URL</button>' : "") +
        '<button class="btn danger" data-act="del">Delete</button></div>', {
      view: function () { FW.closeSheet(); },
      copy: function () {
        var url = f.url && f.url.indexOf("http") === 0 ? f.url : location.origin + f.url;
        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () { toast("URL copied"); }, function () { window.prompt("Copy the URL", url); });
      },
      del: function () {
        FW.closeSheet();
        FW.confirm({ title: "Delete " + nameOf(f) + "?", text: "Pages that use this file will show a broken image after the next publish.", ok: "Delete", danger: true }).then(function (ok) {
          if (!ok) return;
          api("/api/media/" + encodeURIComponent(f.id), { method: "DELETE" }).then(function () { toast("Deleted"); FW.render(); }).catch(function (e) { toast(e.message, true); });
        });
      }
    });
  }
})(window.FW);
</script>
@endverbatim
