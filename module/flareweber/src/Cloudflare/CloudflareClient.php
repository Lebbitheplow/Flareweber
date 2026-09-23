<?php

namespace FlareWeber\Cloudflare;

use FlareWeber\Models\CloudflareConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareClient
{
    /** Refresh OAuth tokens this many seconds before they actually expire. */
    private const REFRESH_LEEWAY = 120;

    public function __construct(
        private readonly CloudflareConnection $connection,
        private readonly string $apiBase
    ) {
    }

    public static function forConnection(CloudflareConnection $connection): self
    {
        if ($connection->status === 'disconnected' || (string) $connection->access_token === '') {
            throw new RuntimeException('Cloudflare connection is disconnected. Reconnect your account.');
        }

        // API tokens never expire on our side and cannot be refreshed.
        if (!$connection->isTokenMethod() && $connection->expiresWithin(self::REFRESH_LEEWAY)) {
            self::refreshWithLock($connection);
        }

        if ($connection->status !== 'connected') {
            throw new RuntimeException('Cloudflare connection is not active (' . $connection->status . '). Reconnect your account.');
        }

        return new self($connection, (string) config('flareweber.cloudflare.api_base'));
    }

    /**
     * Refresh the OAuth tokens under a cache lock so concurrent requests
     * (web + detached deployment) do not race and burn the refresh token.
     */
    private static function refreshWithLock(CloudflareConnection $connection): void
    {
        $refresh = static function () use ($connection): void {
            $connection->refresh();

            if (!$connection->expiresWithin(self::REFRESH_LEEWAY)) {
                return; // another process refreshed meanwhile
            }

            if (!$connection->refresh_token) {
                $connection->status = 'expired';
                $connection->save();

                throw new RuntimeException('Cloudflare token expired. Reconnect your account.');
            }

            try {
                app(OAuthService::class)->refreshConnection($connection);
            } catch (\Throwable $e) {
                $connection->status = 'expired';
                $connection->save();

                throw new RuntimeException('Cloudflare token expired. Reconnect your account.', 0, $e);
            }
        };

        try {
            $lock = Cache::lock('flareweber.cf_refresh.' . $connection->id, 30);
            $lock->block(20, $refresh);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            // Cache store without lock support (or lock timeout): refresh anyway.
            $refresh();
        }
    }

    public function connection(): CloudflareConnection
    {
        return $this->connection;
    }

    public function get(string $path, array $query = [], bool $throwOnError = true): array
    {
        return $this->request('get', $path, ['query' => $query], $throwOnError);
    }

    public function post(string $path, array $body = [], bool $throwOnError = true): array
    {
        return $this->request('post', $path, ['json' => $body], $throwOnError);
    }

    public function put(string $path, array $body = [], bool $throwOnError = true): array
    {
        return $this->request('put', $path, ['json' => $body], $throwOnError);
    }

    public function patch(string $path, array $body = [], bool $throwOnError = true): array
    {
        return $this->request('patch', $path, ['json' => $body], $throwOnError);
    }

    public function delete(string $path, bool $throwOnError = true): array
    {
        return $this->request('delete', $path, [], $throwOnError);
    }

    /**
     * Whether a GET on the path succeeds. Used for endpoints that do not
     * return JSON (e.g. GET workers/scripts/{name} returns the script body).
     */
    public function exists(string $path): bool
    {
        try {
            return $this->client()->get($this->apiBase . $path)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Multipart/form-data request used by the Workers upload endpoints.
     *
     * @param array<int, array{name: string, contents: string, filename?: string|null, headers?: array<string,string>}> $parts
     * @param array<string, string|int|bool> $query
     */
    public function multipart(
        string $method,
        string $path,
        array $parts,
        array $query = [],
        ?string $bearerToken = null,
        bool $throwOnError = true
    ): array {
        $request = $bearerToken !== null
            ? Http::withToken($bearerToken)->acceptJson()->timeout(300)
            : $this->client(300);

        foreach ($parts as $part) {
            $request = $request->attach(
                $part['name'],
                $part['contents'],
                $part['filename'] ?? null,
                $part['headers'] ?? []
            );
        }

        $url = $this->apiBase . $path;
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $this->payload($request->{$method}($url), strtoupper($method), $path, $throwOnError);
    }

    /**
     * PUT a raw request body with an explicit Content-Type. Used for the R2
     * object endpoint, where the body is the object bytes rather than JSON.
     */
    public function putRaw(string $path, string $body, string $contentType, bool $throwOnError = true): array
    {
        $response = $this->client(300)
            ->withHeader('Content-Type', $contentType)
            ->withBody($body, $contentType)
            ->put($this->apiBase . $path);

        return $this->payload($response, 'PUT', $path, $throwOnError);
    }

    /**
     * List every object key in an R2 bucket. The API returns `result[]`
     * (objects with `key`) and `result_info.cursor` / `result_info.is_truncated`.
     *
     * @return array<int, string>
     */
    public function listR2Keys(string $accountId, string $bucket): array
    {
        $keys = [];
        $cursor = null;

        do {
            $query = ['per_page' => 1000];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }

            $payload = $this->get("/accounts/{$accountId}/r2/buckets/{$bucket}/objects", $query);

            foreach ($payload['result'] ?? [] as $object) {
                if (is_array($object) && isset($object['key'])) {
                    $keys[] = (string) $object['key'];
                }
            }

            $info = $payload['result_info'] ?? [];
            $cursor = !empty($info['is_truncated']) && !empty($info['cursor']) ? (string) $info['cursor'] : null;
        } while ($cursor !== null);

        return $keys;
    }

    public function accounts(): array
    {
        $accounts = [];
        $page = 1;

        do {
            $payload = $this->get('/accounts', ['page' => $page, 'per_page' => 50]);
            $result = $payload['result'] ?? [];
            foreach ($result as $account) {
                $accounts[] = $account;
            }

            $info = $payload['result_info'] ?? [];
            $more = isset($info['total_pages']) && $page < (int) $info['total_pages'];
            $page++;
        } while ($more && $page <= 20);

        return $accounts;
    }

    public function verifyToken(): bool
    {
        $response = $this->request('get', '/user/tokens/verify', [], throwOnError: false);

        return ($response['success'] ?? false) && ($response['result']['status'] ?? '') === 'active';
    }

    private function request(string $method, string $path, array $options = [], bool $throwOnError = true): array
    {
        $response = $this->client()->{$method}($this->apiBase . $path, $options['json'] ?? $options['query'] ?? []);

        return $this->payload($response, strtoupper($method), $path, $throwOnError);
    }

    private function payload(Response $response, string $method, string $path, bool $throwOnError): array
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if ($throwOnError && (!$response->successful() || ($payload !== [] && ($payload['success'] ?? true) === false))) {
            throw new RuntimeException(
                "Cloudflare API error on {$method} {$path}: " . $this->describeErrors($response, $payload)
            );
        }

        return $payload;
    }

    private function describeErrors(Response $response, array $payload): string
    {
        $messages = [];

        foreach ($payload['errors'] ?? [] as $error) {
            if (is_array($error) && isset($error['message'])) {
                $messages[] = (isset($error['code']) ? "[{$error['code']}] " : '') . $error['message'];
            }
        }

        if ($messages === []) {
            $messages[] = 'HTTP ' . $response->status();
        }

        return implode('; ', $messages);
    }

    private function client(int $timeout = 30): PendingRequest
    {
        return Http::withToken((string) $this->connection->access_token)
            ->acceptJson()
            ->timeout($timeout);
    }
}
