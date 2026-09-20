<?php

namespace FlareWeber\Console;

use FlareWeber\Migration\SitesImporter;
use Illuminate\Console\Command;

class ImportSitesCommand extends Command
{
    protected $signature = 'flareweber:import {file : JSON bundle produced by flareweber:export}';

    protected $description = 'Import sites from a FlareWeber export bundle (connections are recreated as disconnected)';

    public function handle(SitesImporter $importer): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $bundle = json_decode((string) file_get_contents($file), true);

        if (! is_array($bundle)) {
            $this->error('Invalid JSON bundle.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($bundle);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$result['sites']} site(s), skipped {$result['skipped']} already present.");

        if ($result['sites'] > 0) {
            $this->warn('Re-connect your Cloudflare account (Settings > Cloudflare) before publishing.');
        }

        return self::SUCCESS;
    }
}
