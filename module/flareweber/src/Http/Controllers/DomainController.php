<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Domain\DomainConnector;
use FlareWeber\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DomainController extends Controller
{
    public function check(Request $request, Site $site)
    {
        $data = $request->validate(['domain' => 'required|domain']);
        $domain = $data['domain'];

        $connection = $site->cloudflareConnection;
        if ($connection === null) {
            return response()->json(['error' => 'connect_cloudflare_first'], 409);
        }

        $connector = DomainConnector::forConnection($connection);
        $zone = $connector->findZone($domain);

        if ($zone !== null) {
            return response()->json(['found' => true, 'zone_status' => $zone['status'] ?? null]);
        }

        $dns = $connector->checkExternalDns($domain);

        return response()->json([
            'found' => false,
            'on_cloudflare_nameservers' => $dns['on_cloudflare'],
            'nameservers' => $dns['nameservers'],
        ]);
    }

    public function connect(Request $request, Site $site)
    {
        $data = $request->validate(['domain' => 'required|domain']);
        $connection = $site->cloudflareConnection;

        abort_if($connection === null, 409, 'Connect Cloudflare first.');

        $connector = DomainConnector::forConnection($connection);
        $result = $connector->connectWorkerDomain($site, $data['domain'], $connection->account_id);

        return response()->json([
            'connected' => true,
            'domain' => $data['domain'],
            'https_ready' => $connector->verifyHttps($data['domain']),
            'details' => $result,
        ]);
    }
}
