'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const nav = require('../src/navigation');

const LOCAL = 'http://127.0.0.1:8471';

test('local server URLs stay in the window', () => {
  assert.equal(nav.classifyNavigation(`${LOCAL}/flareweber/admin`, LOCAL), 'local');
  assert.equal(nav.classifyNavigation(`${LOCAL}/edit?content_id=3`, LOCAL), 'local');
  assert.equal(nav.classifyNavigation(LOCAL, LOCAL), 'local');
  assert.equal(nav.classifyNavigation(`${LOCAL}/`, `${LOCAL}/`), 'local');
});

test('a different port or host is not local', () => {
  assert.equal(nav.classifyNavigation('http://127.0.0.1:84710/x', LOCAL), 'external');
  assert.equal(nav.classifyNavigation('http://127.0.0.1:8472/x', LOCAL), 'external');
  assert.equal(nav.classifyNavigation('http://localhost:8471/x', LOCAL), 'external');
});

test('http(s) links open externally, about:blank is allowed', () => {
  assert.equal(nav.classifyNavigation('https://dash.cloudflare.com/oauth', LOCAL), 'external');
  assert.equal(nav.classifyNavigation('http://example.com', LOCAL), 'external');
  assert.equal(nav.classifyNavigation('about:blank', LOCAL), 'allow');
});

test('other schemes are dropped', () => {
  assert.equal(nav.classifyNavigation('javascript:alert(1)', LOCAL), 'deny');
  assert.equal(nav.classifyNavigation('file:///etc/passwd', LOCAL), 'deny');
  assert.equal(nav.classifyNavigation('flareweber://callback', LOCAL), 'deny');
  assert.equal(nav.classifyNavigation('mailto:x@y.z', LOCAL), 'deny');
});

test('nothing is local before the server URL is known', () => {
  assert.equal(nav.isLocalUrl(`${LOCAL}/x`, null), false);
  assert.equal(nav.classifyNavigation(`${LOCAL}/x`, null), 'external');
});
