<?php

namespace FlareWeber\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        return view('flareweber::admin', ['bootstrap' => $this->bootstrap($request)]);
    }

    /**
     * Data the SPA needs before its first API call. Everything else is
     * fetched through the JSON endpoints.
     *
     * @return array<string, mixed>
     */
    private function bootstrap(Request $request): array
    {
        $cloudflare = (array) config('flareweber.cloudflare', []);
        $stripe = (array) config('flareweber.stripe', []);

        return [
            'csrf' => csrf_token(),
            'app_url' => rtrim((string) url('/'), '/'),
            'base' => '/flareweber',
            'api' => '/flareweber/api',
            'classic_admin_url' => '/admin',
            'cloudflare_oauth_available' => !empty($cloudflare['oauth_client_id']) && !empty($cloudflare['oauth_secret']),
            'stripe_oauth_available' => !empty($stripe['client_id']) && !empty($stripe['secret_key']),
            'desktop' => $this->desktopToken() !== '',
            'user_name' => $this->userName($request),
        ];
    }

    private function desktopToken(): string
    {
        $token = getenv('FLAREWEBER_DESKTOP_TOKEN');

        if ($token === false || $token === '') {
            $token = $_SERVER['FLAREWEBER_DESKTOP_TOKEN'] ?? $_ENV['FLAREWEBER_DESKTOP_TOKEN'] ?? '';
        }

        return trim((string) $token);
    }

    private function userName(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            return 'Admin';
        }

        $name = '';

        if (function_exists('user_name')) {
            try {
                $name = (string) user_name($user->id, 'full');
            } catch (\Throwable) {
                $name = '';
            }
        }

        $name = trim($name);

        if ($name === '') {
            $name = trim((string) (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
        }

        return $name !== '' ? $name : (string) ($user->username ?? $user->email ?? 'Admin');
    }
}
