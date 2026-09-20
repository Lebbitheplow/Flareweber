<?php

namespace FlareWeber\Cloudflare;

use FlareWeber\Models\CloudflareConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareClient
{
    public function __construct(
        private readonly CloudflareConnection $connection,
        private readonly string $apiBase
    ) {
    }

    public static function forConnection(CloudflareConnection $connection): self
    {
        return new self($connection, config('flareweber.cloudflare.api_base'));
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

    public function delete(string $path, bool $throwOnError = true): array
    {
        return $this->request('delete', $path, [], $throwOnError);
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
        $request = $this->client(300);

        if ($bearerToken !== null) {
            $request = Http::withToken($bearerToken)->acceptJson()->timeout(300);
        }

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

        $response = $request->{$method}($url);
        $payload = $response->json() ?? [];

        if ($throwOnError && !$response->successful()) {
            $errors = $payload['errors'] ?? [['message' => $response->status() . ' ' . $response->body()]];

            throw new RuntimeException(
                'Cloudflare API error on ' . strtoupper($method) . ' ' . $path . ': '
                . json_encode($errors)
            );
        }

        return $payload;
    }

    /**
     * PUT a raw request body with an explicit Content-Type. Used for the R2
     * object endpoint (PUT /accounts/{acct}/r2/buckets/{bucket}/objects/{key}),
     * where the body is the object bytes rather than JSON.
     */
    public function putRaw(string $path, string $body, string $contentType, bool $throwOnError = true): array
    {
        $response = $this->client(300)
            ->withHeader('Content-Type', $contentType)
            ->withBody($body, $contentType)
            ->put($this->apiBase . $path);

        $payload = $response->json() ?? [];

        if ($throwOnError && !$response->successful()) {
            $errors = $payload['errors'] ?? [['message' => $response->status() . ' ' . $response->body()]];

            throw new RuntimeException(
                'Cloudflare API error on PUT ' . $path . ': ' . json_encode($errors)
            );
        }

        return $payload;
    }

    /**
     * List every object key in an R2 bucket (paginated).
     *
     * @return array<int, string>
     */
    public function listR2Keys(string $accountId, string $bucket): array
    {
        $keys = [];
        $cursor = null;

        do {
            $query = $cursor !== null ? ['cursor' => $cursor] : [];
            $payload = $this->get("/accounts/{$accountId}/r2/buckets/{$bucket}/objects", $query);
            $result = $payload['result'] ?? [];

            foreach ($result['objects'] ?? [] as $object) {
                if (isset($object['key'])) {
                    $keys[] = $object['key'];
                }
            }

            $cursor = !empty($result['truncated']) ? ($result['cursor'] ?? null) : null;
        } while ($cursor);

        return $keys;
    }

    public function accounts(): array
    {
        return $this->get('/accounts')['result'] ?? [];
    }

    public function verifyToken(): bool
    {
        $response = $this->request('get', '/user/tokens/verify', [], throwOnError: false);

        return ($response['success'] ?? false) && ($response['result']['status'] ?? '') === 'active';
    }

    private function request(string $method, string $path, array $options = [], bool $throwOnError = true): array
    {
        $pending = $this->client();

        $response = $pending->{$method}($this->apiBase . $path, $options['json'] ?? $options['query'] ?? []);
        $payload = $response->json() ?? [];

        if ($throwOnError && !$response->successful()) {
            $errors = $payload['errors'] ?? [['message' => $response->status() . ' ' . $response->body()]];

            throw new RuntimeException(
                'Cloudflare API error on ' . strtoupper($method) . ' ' . $path . ': '
                . json_encode($errors)
            );
        }

        return $payload;
    }

    private function client(int $timeout = 30): PendingRequest
    {
        return Http::withToken($this->connection->access_token)
            ->acceptJson()
            ->timeout($timeout);
    }
}
