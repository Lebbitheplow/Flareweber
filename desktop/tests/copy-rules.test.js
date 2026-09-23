'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const rules = require('../src/copy-rules');

test('first copy skips only .env and the install marker', () => {
  const list = rules.skipListFor({ firstCopy: true });
  assert.deepEqual(list, ['.env', '.flareweber-install.json']);

  assert.equal(rules.shouldSkip('.env', list), true);
  assert.equal(rules.shouldSkip('.flareweber-install.json', list), true);
  assert.equal(rules.shouldSkip('.env.example', list), false);
  assert.equal(rules.shouldSkip('userfiles/media/default/hero.jpg', list), false);
  assert.equal(rules.shouldSkip('storage/database.sqlite', list), false);
  assert.equal(rules.shouldSkip('bootstrap/cache/packages.php', list), false);
  assert.equal(rules.shouldSkip('config/app.php', list), false);
});

test('upgrade copy preserves user data and installer-written config', () => {
  const list = rules.skipListFor({ firstCopy: false });

  const preserved = [
    '.env',
    '.flareweber-install.json',
    'userfiles/media',
    'userfiles/media/default/hero.jpg',
    'storage/database.sqlite',
    'storage/app/flareweber/logs/x.log',
    'storage/logs/laravel.log',
    'config/app.php',
    'config/database.php',
    'config/microweber.php',
  ];
  for (const rel of preserved) assert.equal(rules.shouldSkip(rel, list), true, rel);

  const refreshed = [
    'userfiles/modules/flareweber/config.php',
    'userfiles/templates/default/index.php',
    'storage/framework/views/x.php',
    'storage/cache/x',
    'config/cache.php',
    'vendor/autoload.php',
    'index.php',
    'bootstrap/cache/services.php',
  ];
  for (const rel of refreshed) assert.equal(rules.shouldSkip(rel, list), false, rel);
});

test('the flareweber module directory is wiped before an upgrade copy', () => {
  assert.deepEqual(rules.UPGRADE_REFRESH, ['userfiles/modules/flareweber']);
});

test('accepts Windows separators and leading ./', () => {
  const list = rules.skipListFor({ firstCopy: false });
  assert.equal(rules.shouldSkip('userfiles\\media\\a.jpg', list), true);
  assert.equal(rules.shouldSkip('./storage/logs/x.log', list), true);
  assert.equal(rules.shouldSkip('userfiles\\templates\\x', list), false);
});

test('the copy root itself is never skipped', () => {
  assert.equal(rules.shouldSkip('', rules.skipListFor({ firstCopy: false })), false);
});

test('copyPlan decides between first copy, upgrade and no-op', () => {
  assert.deepEqual(rules.copyPlan(null, 'v1', false), { copy: true, firstCopy: true });
  assert.deepEqual(rules.copyPlan({ copied: 'v1' }, 'v1', true), { copy: false, firstCopy: false });
  assert.deepEqual(rules.copyPlan({ copied: 'v1' }, 'v2', true), { copy: true, firstCopy: false });
  assert.deepEqual(rules.copyPlan({ installedAt: 'x' }, 'v1', true), { copy: true, firstCopy: false });
  // Marker says copied but the app directory is gone: treat as a first copy.
  assert.deepEqual(rules.copyPlan({ copied: 'v1' }, 'v1', false), { copy: true, firstCopy: true });
});
