'use strict';

/** True when url points at the bundled site (same origin as the local server). */
function isLocalUrl(url, localUrl) {
  if (typeof url !== 'string' || typeof localUrl !== 'string' || !localUrl) return false;
  const base = localUrl.replace(/\/+$/, '');
  return (
    url === base ||
    url.startsWith(`${base}/`) ||
    url.startsWith(`${base}?`) ||
    url.startsWith(`${base}#`)
  );
}

function isHttpUrl(url) {
  return typeof url === 'string' && /^https?:\/\//i.test(url);
}

/**
 * How the shell handles a navigation or window.open request:
 *   'local'    load it in the same window so the PHP session cookie is kept
 *   'allow'    let the renderer proceed (about:blank)
 *   'external' hand it to the system browser
 *   'deny'     drop it (javascript:, file:, custom schemes)
 */
function classifyNavigation(url, localUrl) {
  if (isLocalUrl(url, localUrl)) return 'local';
  if (url === 'about:blank') return 'allow';
  if (isHttpUrl(url)) return 'external';
  return 'deny';
}

module.exports = { isLocalUrl, isHttpUrl, classifyNavigation };
