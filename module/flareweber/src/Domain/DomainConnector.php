<?php

namespace FlareWeber\Domain;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;
use Illuminate\Support\Facades\Http;

class DomainConnector
{
    public function __construct(
        private readonly CloudflareClient $client
    ) {
    }

    public static function forConnection(CloudflareConnection $connection): self
    {
        return new self(CloudflareClient::forConnection($connection));
    }

    public function findZone(string $domain): ?array
    {
        $response = $this->client->get('/zones', ['name' => $domain], throwOnError: false);

        foreach ($response['result'] ?? [] as $zone) {
            if (($zone['name'] ?? null) === $domain) {
                return $zone;
            }
        }

        return null;
    }

    public function checkExternalDns(string $domain): array
    {
        $nameservers = [];
        $records = dns_get_record($domain, DNS_NS) ?: [];

        foreach ($records as $record) {
            if (str_contains($record['target'] ?? '', 'cloudflare.com')) {
                $nameservers[] = $record['target'];
            }
        }

        return [
            'on_cloudflare' => count($nameservers) >= 2,
            'nameservers' => $nameservers,
        ];
    }

    public function connectWorkerDomain(Site $site, string $domain, string $accountId): array
    {
        $response = $this->client->post("/accounts/{$accountId}/workers/domains/records", [
            'name' => $domain,
            'pattern' => ['pattern' => $domain, 'pattern_type' => 'hostname'],
            'destination' => ['type' => 'worker', 'destination_name' => $site->worker_name],
        ]);

        $result = $response['result'] ?? [];

        $site->forceFill(['domain' => $domain])->save();

        return $result;
    }

    public function verifyHttps(string $domain): bool
    {
        try {
            return Http::timeout(10)->get("https://{$domain}")->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
