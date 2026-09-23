<?php

namespace FlareWeber\Domain;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Deploy\HealthChecker;
use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;

/**
 * Custom domains for a site's Worker (contract B): zone lookup/creation and
 * the Workers custom domain record.
 */
class DomainConnector
{
    /** Public suffixes with a mandatory second label (best effort list). */
    private const TWO_LEVEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'ltd.uk', 'plc.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'co.nz', 'org.nz', 'net.nz',
        'co.za', 'org.za', 'com.br', 'net.br', 'org.br', 'co.jp', 'ne.jp', 'or.jp',
        'co.in', 'net.in', 'org.in', 'com.mx', 'com.ar', 'com.sg', 'com.hk', 'co.kr',
        'com.tr', 'com.cn', 'net.cn', 'org.cn', 'co.il', 'com.pl', 'com.ua', 'co.id',
    ];

    public function __construct(
        private readonly CloudflareClient $client,
        private readonly HealthChecker $health = new HealthChecker()
    ) {
    }

    public static function forConnection(CloudflareConnection $connection): self
    {
        return new self(CloudflareClient::forConnection($connection));
    }

    /** Exact zone match (GET /zones?name=). */
    public function findZone(string $domain): ?array
    {
        $response = $this->client->get('/zones', ['name' => $domain, 'per_page' => 5], throwOnError: false);

        foreach ($response['result'] ?? [] as $zone) {
            if (strcasecmp((string) ($zone['name'] ?? ''), $domain) === 0) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * The zone that covers a hostname: the hostname itself or a parent up to
     * the registrable domain (shop.example.com -> example.com).
     */
    public function findZoneFor(string $hostname): ?array
    {
        foreach ($this->candidateZones($hostname) as $candidate) {
            $zone = $this->findZone($candidate);
            if ($zone !== null) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Create a full-setup zone for the registrable domain. Cloudflare returns
     * the nameservers to switch to; status stays "pending" until they are.
     */
    public function createZone(string $hostname, string $accountId): array
    {
        $response = $this->client->post('/zones', [
            'name' => $this->registrableDomain($hostname),
            'account' => ['id' => $accountId],
            'type' => 'full',
        ]);

        return (array) ($response['result'] ?? []);
    }

    /**
     * Every NS record currently published for the domain, walking up to the
     * registrable domain for subdomains (which rarely have their own NS set).
     *
     * @return array{nameservers: array<int, string>, on_cloudflare: bool, queried: string|null}
     */
    public function checkExternalDns(string $hostname): array
    {
        foreach ($this->candidateZones($hostname) as $candidate) {
            $records = @dns_get_record($candidate, DNS_NS);
            $nameservers = [];

            foreach (is_array($records) ? $records : [] as $record) {
                $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
                if ($target !== '') {
                    $nameservers[] = $target;
                }
            }

            if ($nameservers !== []) {
                sort($nameservers);
                $cloudflare = array_filter($nameservers, static fn (string $ns) => str_ends_with($ns, '.ns.cloudflare.com'));

                return [
                    'nameservers' => array_values(array_unique($nameservers)),
                    'on_cloudflare' => count($cloudflare) >= 1 && count($cloudflare) === count($nameservers),
                    'queried' => $candidate,
                ];
            }
        }

        return ['nameservers' => [], 'on_cloudflare' => false, 'queried' => null];
    }

    /**
     * Attach the hostname to the site's production Worker
     * (PUT /accounts/{account}/workers/domains).
     */
    public function connectWorkerDomain(Site $site, string $hostname, string $accountId, string $zoneId): array
    {
        if (!$site->worker_name) {
            throw new \RuntimeException('Publish the site once before connecting a domain.');
        }

        $response = $this->client->put("/accounts/{$accountId}/workers/domains", [
            'hostname' => $hostname,
            'service' => $site->worker_name,
            'environment' => 'production',
            'zone_id' => $zoneId,
        ]);

        $settings = $site->settings ?? [];
        $settings['zone_id'] = $zoneId;
        $site->forceFill(['domain' => $hostname, 'settings' => $settings])->save();

        return (array) ($response['result'] ?? []);
    }

    /**
     * Poll https://{hostname}/ until it answers 200 or the timeout passes.
     *
     * @return array{connected: bool, https_ready: bool, cert_status: string}
     */
    public function waitForHttps(string $hostname, int $timeoutSeconds = 60): array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        $url = 'https://' . $hostname . '/';

        do {
            $probe = $this->health->reachable($url);

            if ($probe['status'] === 200) {
                return ['connected' => true, 'https_ready' => true, 'cert_status' => 'active'];
            }

            if (time() >= $deadline) {
                break;
            }

            sleep(5);
        } while (true);

        return [
            'connected' => is_int($probe['status'] ?? null),
            'https_ready' => false,
            'cert_status' => 'pending',
        ];
    }

    /** @return array<int, string> hostname, then each parent down to the registrable domain */
    public function candidateZones(string $hostname): array
    {
        $hostname = strtolower(trim($hostname, '. '));
        $root = $this->registrableDomain($hostname);
        $labels = explode('.', $hostname);
        $candidates = [];

        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            $candidates[] = $candidate;

            if ($candidate === $root) {
                break;
            }

            array_shift($labels);
        }

        return $candidates;
    }

    public function registrableDomain(string $hostname): string
    {
        $hostname = strtolower(trim($hostname, '. '));
        $labels = explode('.', $hostname);
        $count = count($labels);

        if ($count <= 2) {
            return $hostname;
        }

        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        $take = in_array($lastTwo, self::TWO_LEVEL_SUFFIXES, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }
}
