<?php

namespace FlareWeber\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-browser OAuth handoff.
 *
 * The desktop distribution authorises in the system browser, which shares no
 * cookie jar with the embedded admin window. State, the PKCE verifier and the
 * OAuth result are therefore parked in the shared cache under a random handoff
 * token that the app polls for, instead of in the PHP session.
 */
class Handoff
{
    public const TTL = 900;

    public function __construct(private readonly ?Repository $cache = null)
    {
    }

    /**
     * Remember the data needed to finish an exchange that started in another
     * browser, and open a channel for its result.
     */
    public function start(string $state, string $handoff, array $payload = []): void
    {
        $this->store()->put($this->stateKey($state), $payload + ['handoff' => $handoff], self::TTL);
        $this->store()->put($this->resultKey($handoff), ['status' => 'pending'], self::TTL);
    }

    /** Read and consume the state entry created by start(). */
    public function pullState(string $state): ?array
    {
        $payload = $this->store()->pull($this->stateKey($state));

        return is_array($payload) ? $payload : null;
    }

    public function putResult(string $handoff, array $payload): void
    {
        $this->store()->put($this->resultKey($handoff), $payload, self::TTL);
    }

    public function result(string $handoff): ?array
    {
        $payload = $this->store()->get($this->resultKey($handoff));

        return is_array($payload) ? $payload : null;
    }

    /**
     * Error payload safe to hand to a browser or the desktop poller: never
     * includes upstream response bodies or tokens.
     */
    public static function errorPayload(string $message, ?string $service = null): array
    {
        $payload = ['status' => 'error', 'message' => self::sanitize($message)];

        if ($service !== null) {
            $payload['service'] = $service;
        }

        return $payload;
    }

    /**
     * Strip anything that looks like a raw upstream body (JSON, HTML, long
     * blobs) from a message, keeping only a short human readable sentence.
     */
    public static function sanitize(string $message, int $max = 200): string
    {
        $message = trim($message);

        // Cut at the first JSON/HTML fragment or "body:" style separator.
        $cut = preg_split('/\s*(?:[{\[<]|:\s*\{|\bbody\b)/i', $message, 2);
        $message = trim((string) ($cut[0] ?? ''), " :.\t\n\r");

        if ($message === '') {
            $message = 'The request failed.';
        }

        if (strlen($message) > $max) {
            $message = rtrim(substr($message, 0, $max - 3)) . '...';
        }

        return $message;
    }

    private function store(): Repository
    {
        return $this->cache ?? Cache::store();
    }

    private function stateKey(string $state): string
    {
        return 'flareweber.oauth_state.' . hash('sha256', $state);
    }

    private function resultKey(string $handoff): string
    {
        return 'flareweber.oauth_handoff.' . hash('sha256', $handoff);
    }
}
