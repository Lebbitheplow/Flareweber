'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const dl = require('../src/deep-link');

const BASE = 'http://127.0.0.1:8471';

test('maps the Cloudflare callback onto the local PHP route', () => {
  assert.equal(
    dl.toLocalCallbackUrl('flareweber://cloudflare/callback?code=a&state=b', BASE),
    `${BASE}/flareweber/cloudflare/callback?code=a&state=b`
  );
});

test('expands the shorthand callback alias', () => {
  assert.equal(
    dl.toLocalCallbackUrl('flareweber://callback?code=a', BASE),
    `${BASE}/flareweber/cloudflare/callback?code=a`
  );
});

test('accepts the triple-slash form some launchers produce', () => {
  assert.equal(
    dl.toLocalCallbackUrl('flareweber:///cloudflare/callback?x=1', BASE),
    `${BASE}/flareweber/cloudflare/callback?x=1`
  );
});

test('maps the Stripe callback for a numeric site id', () => {
  assert.equal(
    dl.toLocalCallbackUrl('flareweber://sites/7/stripe/callback?code=a&state=b', BASE),
    `${BASE}/flareweber/sites/7/stripe/callback?code=a&state=b`
  );
});

test('does not add a double slash when the base has a trailing slash', () => {
  assert.equal(
    dl.toLocalCallbackUrl('flareweber://cloudflare/callback', `${BASE}/`),
    `${BASE}/flareweber/cloudflare/callback`
  );
});

test('rejects routes outside the allow-list', () => {
  const bad = [
    'flareweber://admin',
    'flareweber://sites/7/publish',
    'flareweber://sites/abc/stripe/callback',
    'flareweber://sites/7/stripe/callback/extra',
    'flareweber://cloudflare/callback/../../admin?code=x',
    'flareweber://cloudflare/disconnect',
    'flareweber://desktop/session?token=x',
  ];
  for (const url of bad) {
    assert.throws(() => dl.toLocalCallbackUrl(url, BASE), /not allowed/, url);
  }
});

test('rejects links without a route', () => {
  assert.throws(() => dl.toLocalCallbackUrl('flareweber://', BASE), /Missing route/);
  assert.throws(() => dl.toLocalCallbackUrl('flareweber://?code=x', BASE), /Missing route/);
});

test('rejects non flareweber:// values', () => {
  assert.throws(() => dl.toLocalCallbackUrl('https://example.com/cloudflare/callback', BASE), /Not a flareweber/);
});

test('fromArgv finds the deep link among other arguments', () => {
  assert.equal(
    dl.fromArgv(['electron', '.', '--dev', 'flareweber://callback?code=1']),
    'flareweber://callback?code=1'
  );
  assert.equal(dl.fromArgv(['electron', '.']), null);
  assert.equal(dl.fromArgv(undefined), null);
});

test('redactUrl strips the query and fragment', () => {
  assert.equal(dl.redactUrl('flareweber://callback?code=abc&state=xyz'), 'flareweber://callback?<redacted>');
  assert.equal(dl.redactUrl(`${BASE}/x#code=1`), `${BASE}/x?<redacted>`);
  assert.equal(dl.redactUrl(`${BASE}/plain`), `${BASE}/plain`);
});

test('error messages never leak the query string', () => {
  try {
    dl.toLocalCallbackUrl('flareweber://?code=SECRET&state=SECRET2', BASE);
    assert.fail('expected a throw');
  } catch (err) {
    assert.doesNotMatch(err.message, /SECRET/);
  }
});
