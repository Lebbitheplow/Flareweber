<?php

namespace FlareWeber\Console;

use FlareWeber\Migration\SitesExporter;
use FlareWeber\Models\Site;
use Illuminate\Console\Command;

class ExportSitesCommand extends Command
{
    protected $signature = 'flareweber:export
        {--site= : Only export the site with this id}
        {--out= : Write to this file. A .zip path bundles site.json plus the media library; anything else (or stdout) is JSON}
        {--no-content : Skip Microweber content (pages, posts, products, categories)}
        {--no-media : Skip media files when writing a .zip}';

    protected $description = 'Export FlareWeber sites, deployment history and Microweber content as a portable bundle (no secrets, no account-bound resource ids). Usage: flareweber:export --out=site.zip';

    public function handle(SitesExporter $exporter): int
    {
        $query = Site::query()->with(['deployments', 'cloudflareConnection']);

        if ($siteId = $this->option('site')) {
            $query->whereKey($siteId);

            if (! $query->exists()) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
        }

        $bundle = $exporter->bundle($query->get(), ! $this->option('no-content'));
        $out = (string) $this->option('out');

        if ($out !== '' && str_ends_with(strtolower($out), '.zip')) {
            $media = $exporter->writeZip($out, $bundle, ! $this->option('no-media'));
            $this->info(sprintf(
                'Exported %d site(s), %d content item(s) and %d media file(s) to %s',
                count($bundle['sites']),
                count($bundle['content']['content'] ?? []),
                $media,
                $out
            ));

            return self::SUCCESS;
        }

        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($out !== '') {
            file_put_contents($out, $json);
            $this->info("Exported to {$out} (JSON, no media files; use a .zip path to include media)");
        } else {
            $this->output->writeln((string) $json);
        }

        return self::SUCCESS;
    }
}
