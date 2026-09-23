<?php

namespace FlareWeber\Deploy;

use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;

/**
 * Pre-compile validation and post-compile sanity checks for a publish run.
 * Errors abort the pipeline; warnings are surfaced in the deployment log.
 */
class PublishValidator
{
    private const MAX_LOGGED = 20;

    /**
     * Fatal problems that must stop a publish before anything is provisioned.
     *
     * @return array<int, string>
     */
    public function errors(Site $site): array
    {
        $errors = [];

        if ($site->requiresEcommerce() && !$this->stripeConnected($site)) {
            $errors[] = 'Ecommerce site has no Stripe account connected. Connect Stripe in site settings.';
        }

        return $errors;
    }

    /**
     * Non-fatal observations worth recording for the operator.
     *
     * @return array<int, string>
     */
    public function warnings(Site $site): array
    {
        $warnings = [];

        if (empty($site->domain)) {
            $warnings[] = 'No custom domain set; the site will only be reachable on its workers.dev subdomain.';
        }

        if (!$site->requiresEcommerce() && (bool) ($site->settings['forms'] ?? false)) {
            $warnings[] = 'Contact forms are enabled, which provisions a D1 database on publish.';
        }

        return $warnings;
    }

    /**
     * Refuse to ship a bundle that is empty, a placeholder, references
     * assets that do not exist, has broken navigation, or (for shops) has
     * no sellable products. Other broken links are logged as warnings.
     */
    public function validateCompiled(CompiledSite $compiled, Deployment $deployment): void
    {
        $manifest = $compiled->manifest;
        $errors = [];

        if (!empty($manifest['placeholder'])) {
            $errors[] = 'The site has no published home page; only a placeholder could be compiled.';
        }

        if (($manifest['routes'] ?? []) === []) {
            $errors[] = 'No pages found to publish.';
        }

        foreach (['index.html', '404.html'] as $required) {
            if (!is_file($compiled->directory . '/worker/assets/' . $required)) {
                $errors[] = "Compiled bundle is missing {$required}.";
            }
        }

        $missing = $manifest['missing_assets'] ?? [];
        if ($missing !== []) {
            $errors[] = 'Referenced assets not found on disk: ' . $this->summarize($missing) . '.';
        }

        $missingInCss = $manifest['missing_css_assets'] ?? [];
        if ($missingInCss !== []) {
            $deployment->appendLog('Warning: stylesheets reference files that do not exist: ' . $this->summarize($missingInCss));
        }

        foreach ($manifest['broken_links'] ?? [] as $link) {
            $line = sprintf('broken internal link %s -> %s (HTTP %d)', $link['from'] ?? '?', $link['to'] ?? '?', (int) ($link['status'] ?? 0));
            if (!empty($link['nav'])) {
                $errors[] = 'Navigation has a ' . $line . '.';
            } else {
                $deployment->appendLog('Warning: ' . $line);
            }
        }

        if (!empty($manifest['truncated'])) {
            $deployment->appendLog('Warning: page limit reached; some pages were not compiled.');
        }

        if (($manifest['renderer'] ?? '') === 'in-process') {
            $deployment->appendLog('Warning: pages were rendered in-process because the loopback request failed.');
        }

        if (!empty($manifest['features']['ecommerce'])) {
            $errors = array_merge($errors, $this->productErrors($compiled));
        }

        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }
    }

    /**
     * @return array<int, string>
     */
    private function productErrors(CompiledSite $compiled): array
    {
        $products = $this->products($compiled);
        $active = array_filter($products, fn (array $p) => !empty($p['is_active']));

        if ($active === []) {
            return ['Ecommerce is enabled but the shop has no active products.'];
        }

        $invalid = array_filter($active, fn (array $p) => (int) ($p['price_cents'] ?? 0) <= 0);
        if ($invalid !== []) {
            $titles = array_map(fn (array $p) => (string) ($p['title'] ?: '#' . $p['id']), array_values($invalid));

            return ['Active products without a valid price: ' . $this->summarize($titles) . '.'];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products(CompiledSite $compiled): array
    {
        $file = $compiled->directory . '/worker/data/products.json';
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stripeConnected(Site $site): bool
    {
        if (method_exists($site, 'stripeConnected')) {
            return (bool) $site->stripeConnected();
        }

        return !empty($site->settings['stripe_account_id']) && $site->secret('stripe_secret_key') !== null;
    }

    /**
     * @param array<int, string> $items
     */
    private function summarize(array $items): string
    {
        $shown = array_slice($items, 0, self::MAX_LOGGED);
        $rest = count($items) - count($shown);

        return implode(', ', $shown) . ($rest > 0 ? " (+{$rest} more)" : '');
    }
}
