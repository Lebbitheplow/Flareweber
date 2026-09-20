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

    private function client(): PendingRequest
    {
        return Http::withToken($this->connection->access_token)
            ->acceptJson()
            ->timeout(30);
    }
}
