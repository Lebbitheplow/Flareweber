@verbatim
<script>
(function (FW) {
  "use strict";
  var esc = FW.esc, api = FW.api, toast = FW.toast;

  var STEP_LABELS = { validate: "Validate content", provision: "Provision Cloudflare resources", media: "Sync media to R2", compile: "Compile pages",
    upload: "Upload worker", secrets: "Set secrets", seed: "Seed database", health: "Health check", domain: "Custom domain" };
  var STEP_ORDER = ["validate", "provision", "media", "compile", "upload", "secrets", "seed", "health", "domain"];

  /* ---------- start a deployment (shared with Home) ---------- */

  FW.startDeploy = function (site, env, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>'; }
    if (FW.deploy.current) { toast("A build is already running", true); FW.go("#/publish/build/" + FW.deploy.current.dep.id); return; }
    api("/sites/" + site.id + "/" + (env === "preview" ? "preview" : "publish"), { method: "POST" }).then(function (dep) {
      handleStarted(site, dep);
    }).catch(function (e) {
      var d = e.data || {};
      if (e.status === 409 && (e.code === "deployment_in_progress" || d.deployment)) {
        toast("A build is already running", true);
        if (d.deployment && d.deployment.id) { FW.deploy.start(site.id, d.deployment); FW.go("#/publish/build/" + d.deployment.id); }
        else FW.go("#/deploys");
        return;
      }
      if (d.id && d.status) { handleStarted(site, d); return; } // inline pipeline returned the failed deployment with a 5xx
      toast(e.message, true);
      if (btn) { btn.disabled = false; btn.textContent = env === "preview" ? "Preview" : "Publish"; }
    });
  };

  function handleStarted(site, dep) {
    if (!dep || !dep.id) { toast("The server did not return a deployment", true); return; }
    FW.deploy.start(site.id, dep);
    FW.go("#/publish/build/" + dep.id);
  }

  /* ---------- review ---------- */

  FW.route(/^#\/publish$/, FW.requireSite(function (m, site) {
    FW.screen({ dark: true });
    FW.loading(true);
    var stripeReq = site.ecommerce ? api("/sites/" + site.id + "/stripe/status").catch(function () { return null; }) : Promise.resolve(null);

    Promise.all([api("/api/changes?site=" + site.id).catch(function () { return null; }), stripeReq]).then(function (r) {
      var changes = Array.isArray(r[0]) ? r[0] : (r[0] && (r[0].changes || r[0].data)) || null;
      var stripe = r[1];
      var stripeOk = stripe ? !!stripe.connected : !!site.stripe_connected;
      var notes = [];
      if (!site.cloudflare_connected) notes.push({ warn: true, text: "No Cloudflare account is connected to this site. Connect one in More before publishing." });
      if (site.ecommerce && !stripeOk) notes.push({ warn: true, text: "Stripe is not connected, so checkout will not work on the live shop yet." });
      notes.push({ text: (site.published_at ? "The live site keeps serving the current version until the new one is ready." : "The first publish creates the worker" + (site.ecommerce || (site.settings && site.settings.forms) ? ", the database" : "") + " and the media bucket.") + " Builds usually take about a minute." });

      var list = changes === null
        ? '<div class="dk-row"><div class="grow"><div class="t">Change tracking is not available</div><div class="s">Everything is compiled fresh on every publish anyway.</div></div></div>'
        : (changes.length ? changes.map(function (c) {
            var color = c.type === "product" ? "green" : (c.type === "post" ? "purple" : "blue");
            return '<div class="dk-row"><span class="dot ' + color + '"></span><div class="grow"><div class="t ellip">' + esc((c.type ? c.type.charAt(0).toUpperCase() + c.type.slice(1) + ": " : "") + (c.title || "(untitled)")) + '</div>' +
              '<div class="s">' + esc(FW.cfg.user_name || "You") + ' · ' + esc(FW.ago(c.updated_at)) + '</div></div></div>';
          }).join("") : '<div class="dk-row"><div class="grow"><div class="t">No content changes since the last publish</div><div class="s">Publishing again rebuilds with the current settings.</div></div></div>');

      FW.view('<div class="dk"><div class="dk-top"><span style="font:600 17px/1.2 var(--font);color:#fff">Publish</span><a class="dk-close" href="#/home" aria-label="Close">&times;</a></div>' +
        '<div class="dk-body" style="margin-top:18px">' +
          '<div class="dk-card" style="padding:14px"><div class="row"><div class="icon-box" style="width:26px;height:26px;border-radius:8px;background:#f76707"></div>' +
            '<div class="grow"><div class="t" style="font-size:12.5px">' + esc(site.worker || site.name) + '</div><div class="s" style="font-size:10.5px">production · ' + esc(site.domain || FW.hostOf(site.live_url) || "workers.dev") + '</div></div>' +
            '<span style="font:500 11px/1 var(--font);color:' + (site.cloudflare_connected ? "var(--d-teal)" : "#ff8b8d") + '">' + (site.cloudflare_connected ? "connected" : "not connected") + '</span></div></div>' +
          '<div><div class="eyebrow-mono" style="color:var(--d-dim)">' + (changes ? changes.length + (changes.length === 1 ? " change" : " changes") : "Changes") + '</div><div class="stack" style="gap:9px">' + list + '</div></div>' +
          notes.map(function (n) { return '<div class="dk-note' + (n.warn ? " warn" : "") + '">' + esc(n.text) + '</div>'; }).join("") +
        '</div>' +
        '<div class="dk-foot"><button class="btn primary lg" data-act="prod"' + (site.cloudflare_connected ? "" : " disabled") + '>Deploy to production</button>' +
        '<button class="btn lg" data-act="preview"' + (site.cloudflare_connected ? "" : " disabled") + '>Push to preview instead</button></div></div>', {
        prod: function (btn) { FW.startDeploy(site, "production", btn); },
        preview: function (btn) { FW.startDeploy(site, "preview", btn); }
      });
    });
  }));

  /* ---------- build running / finished ---------- */

  FW.route(/^#\/publish\/build\/(\d+)$/, FW.requireSite(function (m, site) {
    var id = m[1];
    FW.screen({ dark: true });
    var unsubscribe = null, timer = null;

    function cleanup() { if (unsubscribe) unsubscribe(); if (timer) clearInterval(timer); unsubscribe = null; timer = null; }
    function draw(dep) {
      if (!document.getElementById("build-root")) { FW.view('<div class="dk" id="build-root"></div>'); }
      var root = document.getElementById("build-root");
      if (!root) return;
      if (location.hash !== "#/publish/build/" + id) { cleanup(); return; }
      root.innerHTML = dep.status === "success" ? successHtml(site, dep) : (dep.status === "failed" ? failedHtml(site, dep) : runningHtml(site, dep));
      var log = document.getElementById("build-log");
      if (log) log.scrollTop = log.scrollHeight;
      if (dep.status === "success" || dep.status === "failed") cleanup();
    }

    FW.loading(true);
    var current = FW.deploy.current && String(FW.deploy.current.dep.id) === String(id) ? FW.deploy.current.dep : null;
    (current ? Promise.resolve(current) : api("/sites/" + site.id + "/deployments/" + id)).then(function (dep) {
      FW.view('<div class="dk" id="build-root"></div>', {
        home: function () { FW.go("#/home"); },
        retry: function () { FW.go("#/publish"); }
      });
      if (!current && (dep.status === "running" || dep.status === "pending")) FW.deploy.start(site.id, dep);
      unsubscribe = FW.deploy.onUpdate(function (d) { if (d && String(d.id) === String(id)) draw(d); });
      draw(dep);
      timer = setInterval(function () {
        var t = document.getElementById("build-timer");
        if (!t) { clearInterval(timer); return; }
        t.textContent = elapsed(dep);
      }, 1000);
    }).catch(function (e) { FW.error(e, "#/deploys"); });
  }));

  function elapsed(dep) {
    var start = FW.deploy.current && String(FW.deploy.current.dep.id) === String(dep.id) ? FW.deploy.current.startedAt : new Date(dep.created_at).getTime();
    var s = Math.max(0, Math.round((Date.now() - (start || Date.now())) / 1000));
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    return pad(Math.floor(s / 60)) + ":" + pad(s % 60);
  }

  function steps(dep) {
    var given = Array.isArray(dep.steps) && dep.steps.length ? dep.steps : STEP_ORDER.map(function (k) { return { key: k, status: "pending" }; });
    return given.map(function (s) {
      var st = s.status || "pending";
      return '<div class="step ' + esc(st) + '"><span class="k"></span><span class="l">' + esc(s.label || STEP_LABELS[s.key] || s.key) + '</span>' +
        '<span class="d">' + esc(s.detail || (st === "running" ? "running" : (st === "skipped" ? "skipped" : ""))) + '</span></div>';
    }).join("");
  }
  function progress(dep) {
    var list = Array.isArray(dep.steps) && dep.steps.length ? dep.steps : [];
    if (!list.length) return 8;
    var done = list.filter(function (s) { return s.status === "done" || s.status === "skipped" || s.status === "failed"; }).length;
    var running = list.filter(function (s) { return s.status === "running"; }).length;
    return Math.min(100, Math.round(((done + running * 0.5) / list.length) * 100));
  }
  function logHtml(dep) {
    var log = String(dep.log || "").trim();
    return '<div class="log" id="build-log">' + (log ? esc(log) : '<span style="color:var(--d-dim)">Waiting for output</span>') + '</div>';
  }
  function runningHtml(site, dep) {
    var preview = dep.environment === "preview";
    return '<div class="dk-state"><span class="dot blue"></span>' + (preview ? "Building preview" : "Deploying") + ' · <span id="build-timer">' + esc(elapsed(dep)) + '</span></div>' +
      '<div class="dk-h1 sm">Building and uploading</div>' +
      '<div class="dk-lead" style="padding-top:7px;font-size:13px">You can leave this screen. The build keeps running and the header shows its progress.</div>' +
      '<div class="progress"><i style="width:' + progress(dep) + '%"></i></div>' +
      '<div class="steps">' + steps(dep) + '</div>' + logHtml(dep) +
      '<div class="dk-foot"><div class="btn-row"><button class="btn lg" data-act="home">Run in background</button></div></div>';
  }
  function successHtml(site, dep) {
    var preview = dep.environment === "preview";
    var url = dep.url || (preview ? site.preview_url : site.live_url) || "";
    return '<div class="dk-state ok"><span class="dot green"></span>' + (preview ? "Preview ready" : "Live") + '</div>' +
      '<div class="dk-h1 sm">' + (preview ? "Your preview is ready" : "Your site is live") + '</div>' +
      '<div class="dk-lead" style="padding-top:7px;font-size:13px">' + (preview ? "This address is separate from production and never touches the live shop database." : "Version " + esc(dep.version) + " is now serving visitors.") + '</div>' +
      '<div class="dk-body"><div class="dk-card"><div class="eyebrow" style="color:var(--d-muted)">' + (preview ? "Preview URL" : "Live URL") + '</div>' +
        (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener" style="color:#fff;font-family:var(--mono);font-size:13px;word-break:break-all">' + esc(url) + '</a>' : '<span class="sub">No URL was reported</span>') + '</div>' +
        '<div class="steps" style="margin:6px 0 0">' + steps(dep) + '</div>' +
        '<details><summary style="cursor:pointer;font:500 12px/1.4 var(--font);color:var(--d-teal)">Build log</summary>' + logHtml(dep) + '</details></div>' +
      '<div class="dk-foot">' + (url ? '<a class="btn primary lg" href="' + esc(url) + '" target="_blank" rel="noopener">Open site</a>' : "") +
      '<button class="btn lg" data-act="home">Back to home</button></div>';
  }
  function failedHtml(site, dep) {
    var failedStep = (dep.steps || []).filter(function (s) { return s.status === "failed"; })[0];
    return '<div class="dk-state fail"><span class="dot red"></span>Failed</div>' +
      '<div class="dk-h1 sm">The build did not finish</div>' +
      '<div class="dk-lead" style="padding-top:7px;font-size:13px">' + esc(failedStep ? (failedStep.detail || ("Failed at: " + (failedStep.label || STEP_LABELS[failedStep.key] || failedStep.key))) : "The log below has the details.") +
      (dep.environment === "preview" ? "" : " The previous version is still live.") + '</div>' +
      '<div class="steps">' + steps(dep) + '</div>' + logHtml(dep) +
      '<div class="dk-foot"><button class="btn primary lg" data-act="retry">Try again</button><button class="btn lg" data-act="home">Back to home</button></div>';
  }
})(window.FW);
</script>
@endverbatim
