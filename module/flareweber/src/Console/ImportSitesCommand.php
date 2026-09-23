<?php

namespace FlareWeber\Console;

use FlareWeber\Migration\BundleArchive;
use FlareWeber\Migration\SitesImporter;
use Illuminate\Console\Command;

class ImportSitesCommand extends Command
{
    protected $signature = 'flareweber:import
        {file : Bundle produced by flareweber:export (.zip with site.json + media/, or plain .json)}
        {--no-content : Import site rows only, skip Microweber content}
        {--no-media : Do not extract media files from a .zip bundle}
        {--overwrite-media : Replace media files that already exist in userfiles/media}';

    protected $description = 'Import sites, deployment history, Microweber content and media from a FlareWeber export bundle. Connections are recreated as disconnected. Usage: flareweber:import site.zip';

    public function handle(SitesImporter $importer, BundleArchive $archive): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $isZip = str_ends_with(strtolower($file), '.zip');

        try {
            $bundle = $isZip
                ? $archive->readJson($file)
                : json_decode((string) file_get_contents($file), true);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($bundle)) {
            $this->error('Invalid JSON bundle.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($bundle, ! $this->option('no-content'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d site(s) (skipped %d already present), %d content item(s), %d categorie(s).',
            $result['sites'],
            $result['skipped'],
            $result['content'],
            $result['categories']
        ));

        if ($isZip && ! $this->option('no-media')) {
            $mediaDir = rtrim(base_path(), '/') . '/userfiles/media';
            $media = $archive->extractMedia($file, $mediaDir, (bool) $this->option('overwrite-media'));
            $this->info("Media: {$media['written']} file(s) written, {$media['skipped']} skipped.");
        }

        if ($result['sites'] > 0) {
            $this->warn('Re-connect your Cloudflare account and Stripe before publishing; resources are recreated on the next publish.');
        }

        return self::SUCCESS;
    }
}
