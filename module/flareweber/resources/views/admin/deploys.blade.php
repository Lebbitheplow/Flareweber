@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;

  function isRunning(d) { return d.status === "running" || d.status === "pending"; }
  function logBlock(d) {
    if (!d.log) return "";
    return '<details style="margin-top:8px"><summary class="sub" style="cursor:pointer">Build log</summary><pre class="log light">' + esc(d.log) + '</pre></details>';
  }

  /* ---------- deploy history ---------- */

  FW.route(/^#\/deploys$/, FW.requireSite(function (m, site) {
    FW.screen({ title: "Deploys", sub: (site.worker || site.name) + " · production", back: "#/more" });
    FW.loading();

    api("/sites/" + site.id + "/deployments").then(function (deps) {
      deps = Array.isArray(deps) ? deps : (deps.deployments || deps.data || []);
      deps.sort(function (a, b) { return new Date(b.created_at || 0) - new Date(a.created_at || 0) || (b.id - a.id); });
      var live = null;
      deps.forEach(function (d) { if (!live && d.status === "success" && d.environment !== "preview") live = d; });
      var byId = {};
      deps.forEach(function (d) { byId[String(d.id)] = d; });

      if (!deps.length) {
        FW.view('<div class="empty"><b>No deploys yet</b>Publish the site to create the first version.<br><a class="btn soft" href="#/publish">Publish</a></div>');
        return;
      }

      var liveCard = live
        ? '<div class="card"><div class="row"><span class="dot green"></span><span class="eyebrow-mono txt-green" style="margin:0">Live now</span><div class="grow"></div><span class="mono">v' + esc(live.version) + '</span></div>' +
          '<div class="h2 mt12">' + esc(live.url ? FW.hostOf(live.url) : site.name) + '</div>' +
          '<div class="sub">' + esc(FW.when(live.created_at)) + (live.finished_at && live.created_at ? ' · ' + Math.max(1, Math.round((new Date(live.finished_at) - new Date(live.created_at)) / 1000)) + 's build' : "") + '</div>' +
          '<div class="btn-row mt12" style="justify-content:flex-start">' + (live.url ? '<a class="btn soft" href="' + esc(live.url) + '" target="_blank" rel="noopener" style="flex:0 0 auto">Open site</a>' : "") +
          (live.log ? '<button class="btn soft" data-act="log" data-id="' + esc(live.id) + '" style="flex:0 0 auto">Build log</button>' : "") + '</div>' +
          '<pre class="log light" id="log-' + esc(live.id) + '" hidden>' + esc(live.log || "") + '</pre></div>'
        : '<div class="card"><div class="row"><span class="dot grey"></span><span class="eyebrow-mono" style="margin:0">Nothing live yet</span></div><div class="sub mt8">No production deploy has succeeded so far.</div></div>';

      var rows = deps.filter(function (d) { return !live || d.id !== live.id; }).map(function (d) {
        var preview = d.environment === "preview";
        var failed = d.status === "failed";
        var title = failed ? "Failed" : (preview ? "Preview build" : (d.status === "success" ? "Production release" : "In progress"));
        var action = "";
        if (isRunning(d)) action = '<a class="btn small" href="#/publish/build/' + esc(d.id) + '">View</a>';
        else if (failed) action = d.log ? '<button class="btn small danger" data-act="log" data-id="' + esc(d.id) + '">Log</button>' : "";
        else if (preview) action = d.url ? '<a class="btn small" href="' + esc(d.url) + '" target="_blank" rel="noopener">Open</a>' : "";
        else if (d.status === "success") action = '<button class="btn small" data-act="rollback" data-id="' + esc(d.id) + '">Roll back</button>';
        return '<div class="li' + (failed ? " failed" : "") + '" style="flex-wrap:wrap"><div class="grow"><div class="t" style="font-weight:500">' +
          (failed ? '<span class="dot red" style="margin-right:7px"></span>' : "") + esc(title) + (preview ? ' <span class="pill grey">preview</span>' : "") + (isRunning(d) ? ' <span class="pill blue">running</span>' : "") + '</div>' +
          '<div class="s">v' + esc(d.version) + ' · ' + esc(FW.when(d.created_at)) + (d.url && !preview ? ' · ' + esc(FW.hostOf(d.url)) : "") + '</div></div>' + action +
          '<pre class="log light" id="log-' + esc(d.id) + '" hidden style="flex-basis:100%">' + esc(d.log || "") + '</pre></div>';
      }).join("");

      FW.view(liveCard + (rows ? '<div class="card list mt12">' + rows + '</div>' : "") +
        '<div class="sub mt12">Rollback swaps the live worker version. Content drafts are untouched. Preview builds cannot be rolled back to.</div>', {
        log: function (el) { var p = document.getElementById("log-" + el.getAttribute("data-id")); if (p) p.hidden = !p.hidden; },
        rollback: function (el) {
          var d = byId[el.getAttribute("data-id")];
          FW.confirm({ title: "Roll back to v" + d.version + "?", text: "Visitors get that version immediately. Your content here stays as it is.", ok: "Roll back" }).then(function (ok) {
            if (!ok) return;
            el.disabled = true;
            api("/sites/" + site.id + "/deployments/" + d.id + "/rollback", { method: "POST" }).then(function (r) {
              if (r && r.id && (isRunning(r) || r.status === "running")) { FW.deploy.start(site.id, r); toast("Rolling back"); FW.go("#/publish/build/" + r.id); return; }
              toast(r && r.status === "failed" ? "Rollback failed" : "Rolled back to v" + d.version, r && r.status === "failed");
              FW.refreshSite().catch(function () {}).then(function () { FW.render(); });
            }).catch(function (e) { toast(e.message, true); el.disabled = false; });
          });
        }
      });
    }).catch(function (e) { FW.error(e, "#/deploys"); });
  }));

  /* ---------- custom domain ---------- */

  FW.route(/^#\/domain$/, FW.requireSite(function (m, site) {
    FW.screen({ title: "Domain", back: "#/more" });
    var status = site.domain
      ? '<div class="row"><span class="dot green"></span><div class="grow"><div class="h2">' + esc(site.domain) + '</div><div class="sub">Connected to this site' + (site.resources && site.resources.zone_id ? " · zone " + esc(site.resources.zone_id) : "") + '</div></div></div>'
      : '<div class="row"><span class="dot grey"></span><div class="grow"><div class="h2">No custom domain</div><div class="sub">' + (site.live_url ? "Visitors use " + esc(FW.hostOf(site.live_url)) + " for now." : "Publish first to get a workers.dev address, then add your own domain.") + '</div></div></div>';

    FW.view('<div class="stack"><div class="card">' + status + '</div>' +
      (site.cloudflare_connected ? "" : '<div class="card" style="border-color:var(--orange-bd);background:var(--orange-bg)">Connect your Cloudflare account in <a href="#/cloudflare">More</a> first.</div>') +
      '<div class="card"><label class="lbl" for="d-input">Domain</label><input type="text" id="d-input" placeholder="example.com" value="' + esc(site.domain || "") + '" autocomplete="off" autocapitalize="none">' +
        '<div class="btn-row mt12"><button class="btn" data-act="check">Check</button><button class="btn primary" data-act="connect">Connect</button></div>' +
        '<div id="d-result" class="mt12"></div></div>' +
      '<div class="sub">Checking looks the domain up on your Cloudflare account. Connecting adds it to the worker and requests HTTPS.</div></div>', {
      check: function (btn) { check(site, btn); },
      connect: function (btn) { connect(site, btn); }
    });
  }));

  function domainInput() { var v = document.getElementById("d-input").value.trim().toLowerCase(); if (!v) toast("Enter a domain", true); return v; }
  function nsList(list) { return '<ul class="copy-list">' + (list || []).map(function (n) { return "<li>" + esc(n) + "</li>"; }).join("") + "</ul>"; }

  function check(site, btn) {
    var domain = domainInput(); if (!domain) return;
    var out = document.getElementById("d-result");
    btn.disabled = true; out.innerHTML = '<span class="spin-dark"></span>';
    api("/sites/" + site.id + "/domain/check", { method: "POST", body: { domain: domain } }).then(function (r) {
      var html = "";
      var zoneStatus = r.zone_status || (r.zone && r.zone.status);
      if (r.found || zoneStatus) {
        html += '<div class="row"><span class="pill ' + (zoneStatus === "active" ? "green" : "orange") + '"><span class="dot"></span>Zone ' + esc(zoneStatus || "found") + '</span><span class="sub">on your Cloudflare account</span></div>';
        if (zoneStatus && zoneStatus !== "active") html += '<div class="sub mt8">The zone is waiting for its nameservers to switch. Set these at your registrar:</div>' + nsList(r.name_servers || (r.zone && r.zone.name_servers));
        else html += '<div class="sub mt8">Ready to connect.</div>';
      } else if (r.on_cloudflare_nameservers) {
        html += '<div class="pill orange"><span class="dot"></span>Nameservers point to Cloudflare</div><div class="sub mt8">The zone is not on this account yet. Connect will add it.</div>';
      } else {
        html += '<div class="pill grey"><span class="dot"></span>Not on Cloudflare</div>';
        if (r.name_servers && r.name_servers.length) html += '<div class="sub mt8">Set these nameservers at your registrar, then check again:</div>' + nsList(r.name_servers);
        else html += '<div class="sub mt8">Connect will create the zone and show the nameservers to set.</div>';
        if (r.nameservers && r.nameservers.length) html += '<div class="sub mt8">Current nameservers:</div>' + nsList(r.nameservers);
      }
      html += '<button class="btn soft mt12" data-act="check">Check again</button>';
      out.innerHTML = html; btn.disabled = false;
    }).catch(function (e) { out.innerHTML = '<div class="txt-red">' + esc(e.message) + '</div>'; btn.disabled = false; });
  }

  function connect(site, btn) {
    var domain = domainInput(); if (!domain) return;
    var out = document.getElementById("d-result");
    btn.disabled = true; out.innerHTML = '<div class="row"><span class="spin-dark"></span><span class="sub">Connecting, this can take up to a minute</span></div>';
    api("/sites/" + site.id + "/domain/connect", { method: "POST", body: { domain: domain } }).then(function (r) {
      var ns = r.name_servers || (r.details && r.details.name_servers) || (r.zone && r.zone.name_servers);
      var zoneStatus = r.zone_status || (r.details && r.details.zone_status) || (r.zone && r.zone.status);
      out.innerHTML = '<div class="row"><span class="pill green"><span class="dot"></span>Connected</span>' +
        '<span class="pill ' + (r.https_ready ? "green" : "orange") + '">' + (r.https_ready ? "HTTPS ready" : "HTTPS pending") + '</span></div>' +
        (r.https_ready ? '<div class="sub mt8">https://' + esc(domain) + ' is serving your site.</div>' : '<div class="sub mt8">DNS and the certificate are still propagating.' + (zoneStatus && zoneStatus !== "active" && ns && ns.length ? " Set these nameservers at your registrar:" : "") + '</div>' + (zoneStatus && zoneStatus !== "active" && ns && ns.length ? nsList(ns) : "")) +
        '<button class="btn soft mt12" data-act="check">Check again</button>';
      toast("Domain connected");
      FW.refreshSite().catch(function () {});
      btn.disabled = false;
    }).catch(function (e) { out.innerHTML = '<div class="txt-red">' + esc(e.message) + '</div>'; btn.disabled = false; });
  }
})(window.FW);
</script>
@endverbatim
