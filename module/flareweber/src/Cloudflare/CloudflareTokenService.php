<?php

namespace FlareWeber\Cloudflare;

use FlareWeber\Models\CloudflareConnection;
use RuntimeException;

/**
 * Connect a Cloudflare account with a user-created API token instead of
 * OAuth (contract G). The token is verified, then stored encrypted on a
 * connection with method "token".
 */
class CloudflareTokenService
{
    /** Permissions the UI tells the user to grant when creating the token. */
    public const REQUIRED_PERMISSIONS = [
        'Account: Workers Scripts Edit',
        'Account: D1 Edit',
        'Account: Workers R2 Storage Edit',
        'Account: Account Settings Read',
        'Zone: Zone Read',
        'Zone: DNS Edit',
        'Zone: Workers Routes Edit',
        'Zone: SSL and Certificates Edit',
    ];

    /**
     * @return array{status: string, connection_id: int, account_id?: string, account_name?: string, accounts?: array}
     */
    public function connect(string $token, ?int $userId = null): array
    {
        $token = trim($token);

        if ($token === '') {
            throw new RuntimeException('Enter a Cloudflare API token.');
        }

        $connection = new CloudflareConnection();
        $connection->user_id = $userId;
        $connection->method = CloudflareConnection::METHOD_TOKEN;
        $connection->access_token = $token;
        $connection->refresh_token = null;
        $connection->token_expires_at = null;
        $connection->account_id = '';
        $connection->status = 'pending';

        $client = new CloudflareClient($connection, (string) config('flareweber.cloudflare.api_base'));

        if (!$client->verifyToken()) {
            throw new RuntimeException('Cloudflare did not accept that API token. Check that it is active and complete.');
        }

        try {
            $accounts = $client->accounts();
        } catch (\Throwable) {
            throw new RuntimeException('The token cannot list accounts. Grant it "Account Settings Read".');
        }

        if ($accounts === []) {
            throw new RuntimeException('The token has no access to any Cloudflare account.');
        }

        $connection->save();

        if (count($accounts) === 1) {
            $connection->forceFill([
                'account_id' => (string) ($accounts[0]['id'] ?? ''),
                'account_name' => (string) ($accounts[0]['name'] ?? ''),
                'status' => 'connected',
            ])->save();

            return [
                'status' => 'connected',
                'connection_id' => $connection->id,
                'account_id' => $connection->account_id,
                'account_name' => $connection->account_name,
            ];
        }

        return [
            'status' => 'needs_account',
            'connection_id' => $connection->id,
            'accounts' => array_map(
                static fn (array $a) => ['id' => $a['id'] ?? '', 'name' => $a['name'] ?? ''],
                $accounts
            ),
        ];
    }
}
