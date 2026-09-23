'use strict';

/**
 * Pure rules for copying the bundled Microweber app into the writable app
 * directory. Kept free of fs/electron so they can be unit tested.
 *
 * Paths are relative to the app root and use forward slashes.
 */

// Never copied from the bundle: these belong to the user's install.
const ALWAYS_SKIP = ['.env', '.flareweber-install.json'];

// On an upgrade (re-copy over an existing install) user data and the files
// the Microweber installer rewrote must survive too. config/app.php holds
// the APP_KEY, config/database.php the SQLite path and config/microweber.php
// the is_installed flag; overwriting them would make the app look
// uninstalled.
const UPGRADE_SKIP = [
  'userfiles/media',
  'storage/database.sqlite',
  'storage/app',
  'storage/logs',
  'config/app.php',
  'config/database.php',
  'config/microweber.php',
];

// Wiped before an upgrade copy so files removed upstream do not linger.
const UPGRADE_REFRESH = ['userfiles/modules/flareweber'];

function skipListFor({ firstCopy }) {
  return firstCopy ? [...ALWAYS_SKIP] : [...ALWAYS_SKIP, ...UPGRADE_SKIP];
}

function normalizeRelative(relPath) {
  return String(relPath || '')
    .split('\\')
    .join('/')
    .replace(/^\.\/+/, '')
    .replace(/^\/+|\/+$/g, '');
}

/** True when relPath is a skip entry or lives underneath one. */
function shouldSkip(relPath, skipList) {
  const rel = normalizeRelative(relPath);
  if (!rel) return false;
  return skipList.some((entry) => rel === entry || rel.startsWith(`${entry}/`));
}

/**
 * Decide whether the bundle has to be copied. `marker` is the parsed
 * .flareweber-install.json (or null), `bundleId` identifies the shipped
 * payload (desktop version + Microweber version + fingerprint) and
 * `appExists` tells whether a previous copy is present.
 */
function copyPlan(marker, bundleId, appExists) {
  const upToDate = Boolean(appExists && marker && marker.copied === bundleId);
  if (upToDate) return { copy: false, firstCopy: false };
  return { copy: true, firstCopy: !appExists };
}

module.exports = {
  ALWAYS_SKIP,
  UPGRADE_SKIP,
  UPGRADE_REFRESH,
  skipListFor,
  shouldSkip,
  copyPlan,
  normalizeRelative,
};
