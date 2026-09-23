<?php

namespace FlareWeber\Deploy;

use Illuminate\Support\Facades\Http;

/**
 * Post-deploy health probes (contract B):
 *   1. GET {url}/            200 + the compiled FlareWeber generator marker
 *   2. GET {url}/api/health  JSON {ok: true, db: bool, media: bool}
 */
class HealthChecker
{
    public const MARKER = '<meta name="generator" content="FlareWeber">';

    /**
     * @return array{ok: bool, index: bool, api: bool, db: bool, media: bool, https: bool, version: string|null, detail: string}
     */
    public function check(string $url): array
    {
        $base = rtrim($url, '/');

        $index = $this->fetch($base . '/');
        $indexOk = $index['status'] === 200 && str_contains($index['body'], self::MARKER);

        $api = $this->fetch($base . '/api/health');
        $json = json_decode($api['body'], true);
        $json = is_array($json) ? $json : [];
        $apiOk = $api['status'] === 200 && ($json['ok'] ?? false) === true;

        $details = [];
        $details[] = $indexOk
            ? 'index ok'
            : 'index ' . ($index['error'] ?? ($index['status'] === 200 ? 'missing generator marker' : 'HTTP ' . $index['status']));
        $details[] = $apiOk
            ? sprintf('api ok (db %s, media %s)', $this->yesNo($json['db'] ?? false), $this->yesNo($json['media'] ?? false))
            : 'api ' . ($api['error'] ?? 'HTTP ' . $api['status']);

        return [
            'ok' => $indexOk && $apiOk,
            'index' => $indexOk,
            'api' => $apiOk,
            'db' => (bool) ($json['db'] ?? false),
            'media' => (bool) ($json['media'] ?? false),
            'https' => $indexOk && str_starts_with(strtolower($base), 'https://'),
            'version' => isset($json['version']) ? (string) $json['version'] : null,
            'detail' => implode(', ', $details),
        ];
    }

    /**
     * Index-only probe used for the custom domain check.
     *
     * @return array{ok: bool, https: bool, status: int|null, detail: string}
     */
    public function reachable(string $url): array
    {
        $response = $this->fetch(rtrim($url, '/') . '/');
        $ok = $response['status'] === 200 && str_contains($response['body'], self::MARKER);

        return [
            'ok' => $ok,
            'https' => $ok && str_starts_with(strtolower($url), 'https://'),
            'status' => $response['status'],
            'detail' => $ok ? 'ok' : ($response['error'] ?? 'HTTP ' . $response['status']),
        ];
    }

    /** @return array{status: int|null, body: string, error: string|null} */
    private function fetch(string $url): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'FlareWeber-HealthCheck/1.0', 'Accept' => '*/*'])
                ->get($url);

            return ['status' => $response->status(), 'body' => (string) $response->body(), 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => null, 'body' => '', 'error' => $this->shortError($e)];
        }
    }

    private function shortError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/\s+\(see https?:\S+\)/', '', $message) ?? $message;

        return strlen($message) > 120 ? substr($message, 0, 117) . '...' : $message;
    }

    private function yesNo(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
