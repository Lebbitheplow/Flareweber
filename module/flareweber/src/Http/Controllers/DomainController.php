<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Domain\DomainConnector;
use FlareWeber\Models\Site;
use FlareWeber\Support\Handoff;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DomainController extends Controller
{
    private const DOMAIN_RULE = 'regex:/^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/i';

    /**
     * Zone status for a domain. Creates the zone when the domain is not on
     * Cloudflare yet so the user gets the nameservers to switch to.
     */
    public function check(Request $request, Site $site)
    {
        $domain = $this->domain($request);
        $connection = $site->cloudflareConnection;

        if ($connection === null) {
            return response()->json(['error' => 'connect_cloudflare_first'], 409);
        }

        try {
            $connector = DomainConnector::forConnection($connection);
            $zone = $connector->findZoneFor($domain);
            $dns = $connector->checkExternalDns($domain);
            $created = false;

            if ($zone === null) {
                $zone = $connector->createZone($domain, $connection->account_id);
                $created = $zone !== [];
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'cloudflare_error', 'message' => Handoff::sanitize($e->getMessage())], 502);
        }

        return response()->json([
            'found' => !$created && $zone !== [],
            'created' => $created,
            'zone_status' => $zone['status'] ?? null,
            'zone_id' => $zone['id'] ?? null,
            'zone_name' => $zone['name'] ?? null,
            'name_servers' => array_values((array) ($zone['name_servers'] ?? [])),
            'current_nameservers' => $dns['nameservers'],
            'on_cloudflare' => ($zone['status'] ?? null) === 'active' || $dns['on_cloudflare'],
        ]);
    }

    /** Attach the domain to the production Worker; requires an active zone. */
    public function connect(Request $request, Site $site)
    {
        $domain = $this->domain($request);
        $connection = $site->cloudflareConnection;

        if ($connection === null) {
            return response()->json(['error' => 'connect_cloudflare_first'], 409);
        }

        try {
            $connector = DomainConnector::forConnection($connection);
            $zone = $connector->findZoneFor($domain);

            if ($zone === null) {
                return response()->json(['error' => 'zone_not_found', 'message' => 'Add the domain to Cloudflare first (run the check).'], 409);
            }

            if (($zone['status'] ?? null) !== 'active') {
                return response()->json([
                    'error' => 'zone_not_active',
                    'zone_status' => $zone['status'] ?? null,
                    'name_servers' => array_values((array) ($zone['name_servers'] ?? [])),
                    'message' => 'The zone is not active yet. Switch the nameservers at your registrar and try again.',
                ], 409);
            }

            $connector->connectWorkerDomain($site, $domain, $connection->account_id, (string) $zone['id']);
            $probe = $connector->waitForHttps($domain, 60);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'cloudflare_error', 'message' => Handoff::sanitize($e->getMessage())], 502);
        }

        return response()->json([
            'domain' => $domain,
            'zone_id' => $zone['id'],
            'live_url' => 'https://' . $domain,
        ] + $probe);
    }

    private function domain(Request $request): string
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', self::DOMAIN_RULE],
        ]);

        return strtolower(trim($data['domain'], '. '));
    }
}
