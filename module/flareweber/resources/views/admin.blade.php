<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ $bootstrap['csrf'] }}">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex">
<title>FlareWeber</title>
@include('flareweber::admin.styles')
</head>
<body>
<header id="hdr">
  <a class="hdr-back" id="hdr-back" hidden href="#/home" aria-label="Back"><span class="chev-left"></span></a>
  <div class="hdr-avatar" id="hdr-avatar" hidden></div>
  <div class="hdr-text">
    <div class="hdr-title-row"><span class="hdr-title" id="hdr-title">FlareWeber</span><span class="chev-down" id="hdr-chev" hidden></span></div>
    <div class="hdr-sub" id="hdr-sub" hidden></div>
  </div>
  <a class="hdr-pill" id="hdr-pill" hidden href="#/home"><span class="spin-sm"></span><span id="hdr-pill-text">Deploying</span></a>
  <div class="hdr-right" id="hdr-right"></div>
</header>

<main id="app"><div class="empty"><span class="spin-dark"></span></div></main>

<nav id="nav" hidden>
  <a href="#/home" data-nav="home"><span class="ico"><svg viewBox="0 0 24 24"><path d="M4 11 12 4l8 7v9a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z"/></svg></span>Home</a>
  <a href="#/pages" data-nav="pages"><span class="ico"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>Pages</a>
  <a href="#/shop" data-nav="shop" id="nav-shop"><span class="ico"><svg viewBox="0 0 24 24"><path d="M5 8h14l-1 11H6zM9 8V6a3 3 0 0 1 6 0v2"/></svg></span>Shop</a>
  <a href="#/media" data-nav="media"><span class="ico"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="m4 16 5-5 4 4 2-2 5 5"/><circle cx="16" cy="9" r="1.5"/></svg></span>Media</a>
  <a href="#/more" data-nav="more"><span class="ico"><svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="18" cy="12" r="1.6"/></svg></span>More</a>
</nav>

<div id="sheet-bg" hidden><div id="sheet" role="dialog" aria-modal="true"><div class="sheet-handle"></div><div id="sheet-body"></div></div></div>
<div id="toast" role="status" aria-live="polite"></div>

<script id="fw-bootstrap" type="application/json">{!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) !!}</script>
@include('flareweber::admin.core')
@include('flareweber::admin.welcome')
@include('flareweber::admin.home')
@include('flareweber::admin.pages')
@include('flareweber::admin.shop')
@include('flareweber::admin.media')
@include('flareweber::admin.publish')
@include('flareweber::admin.deploys')
@include('flareweber::admin.more')
<script>FW.boot();</script>
</body>
</html>
