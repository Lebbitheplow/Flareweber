<?php

namespace FlareWeber\Console;

use FlareWeber\Models\Site;
use FlareWeber\Migration\SitesExporter;
use Illuminate\Console\Command;

class ExportSitesCommand extends Command
{
    protected $signature = 'flareweber:export
        {--site= : Only export the site with this id}
        {--out= : Write JSON to this file instead of stdout}';

    protected $description = 'Export FlareWeber site configuration and deployment history as a portable JSON bundle (no secrets)';

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

        $json = json_encode(
            $exporter->bundle($query->get()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($out = $this->option('out')) {
            file_put_contents($out, $json);
            $this->info("Exported to {$out}");
        } else {
            $this->output->writeln($json);
        }

        return self::SUCCESS;
    }
}
