'use strict';

const PROTOCOL = 'flareweber';

/**
 * The OS hands OAuth callbacks back through the custom scheme, e.g.
 *   flareweber://cloudflare/callback?code=abc&state=xyz
 *   flareweber://callback?code=abc&state=xyz          (Cloudflare shorthand)
 *   flareweber://sites/7/stripe/callback?code=...&state=...
 * The tokens still have to be exchanged by the bundled PHP app, so we replay
 * the callback against the local server. The app itself does not need the
 * result: it polls the handoff token the server parked it under.
 */
const ROUTE_ALIASES = { callback: 'cloudflare/callback' };

// Only these PHP routes may be reached through a deep link. Anything else is
// dropped so a crafted link cannot drive arbitrary local endpoints.
const ALLOWED_ROUTES = [/^cloudflare\/callback$/, /^sites\/\d+\/stripe\/callback$/];

/** Strip the query string (code=, state=) before a URL reaches a log or the UI. */
function redactUrl(url) {
  const text = String(url);
  const i = text.search(/[?#]/);
  return i === -1 ? text : `${text.slice(0, i)}?<redacted>`;
}

function isDeepLink(value) {
  return typeof value === 'string' && value.toLowerCase().startsWith(`${PROTOCOL}://`);
}

function toLocalCallbackUrl(url, baseUrl) {
  if (!isDeepLink(url)) throw new Error(`Not a ${PROTOCOL}:// link: ${redactUrl(url)}`);

  const rest = url.slice(`${PROTOCOL}://`.length);
  // Parsing the remainder against a dummy base keeps scheme-less paths such as
  // "cloudflare/callback?code=x" on the path instead of treating the first
  // segment as a host. It also collapses "..", so traversal never survives.
  const parsed = new URL(rest, 'http://localhost/');
  let route = parsed.pathname.replace(/^\/+|\/+$/g, '');

  if (!route) throw new Error(`Missing route in deep link: ${redactUrl(url)}`);

  route = ROUTE_ALIASES[route] || route;

  if (!ALLOWED_ROUTES.some((re) => re.test(route))) {
    throw new Error(`Deep link route not allowed: ${route}`);
  }

  return `${baseUrl.replace(/\/+$/, '')}/flareweber/${route}${parsed.search}`;
}

/** Pull a deep link out of an argv array (Windows/Linux relaunch the app). */
function fromArgv(argv) {
  return (Array.isArray(argv) && argv.find(isDeepLink)) || null;
}

/** Deliver the code/state to the PHP callback. Failures are not retried. */
async function deliver(url, baseUrl, log = () => {}) {
  const target = toLocalCallbackUrl(url, baseUrl);
  const shown = redactUrl(target);

  try {
    const res = await fetch(target, { redirect: 'manual' });
    log(`[deep-link] ${shown} -> HTTP ${res.status}`);
  } catch (err) {
    log(`[deep-link] ${shown} failed: ${err.message}`);
  }
}

module.exports = { PROTOCOL, ALLOWED_ROUTES, isDeepLink, redactUrl, toLocalCallbackUrl, fromArgv, deliver };
