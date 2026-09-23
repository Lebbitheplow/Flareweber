<?php

namespace FlareWeber\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Desktop auto-login (contract H). Electron generates a random token per
 * launch and hands it to PHP as FLAREWEBER_DESKTOP_TOKEN; presenting the same
 * token here logs in the first Microweber admin and opens the admin SPA.
 * Anything else is a 404 so the route does not reveal whether it is armed.
 */
class DesktopSessionController extends Controller
{
    public function __invoke(Request $request)
    {
        $expected = $this->expectedToken();
        $provided = (string) $request->query('token', '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            abort(404);
        }

        $admin = DB::table('users')
            ->where('is_admin', 1)
            ->where('is_active', 1)
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            abort(404);
        }

        Auth::loginUsingId($admin->id);

        // Let Microweber set up its own session state (USER_ID, events).
        if (app()->bound('user_manager')) {
            try {
                app('user_manager')->make_logged((int) $admin->id);
            } catch (\Throwable) {
                // Auth::loginUsingId already established the Laravel session.
            }
        }

        $request->session()->regenerate();

        return redirect('/flareweber/admin');
    }

    private function expectedToken(): string
    {
        $token = $_SERVER['FLAREWEBER_DESKTOP_TOKEN'] ?? $_ENV['FLAREWEBER_DESKTOP_TOKEN'] ?? getenv('FLAREWEBER_DESKTOP_TOKEN');

        if (!is_string($token) || $token === '') {
            $token = (string) env('FLAREWEBER_DESKTOP_TOKEN', '');
        }

        return trim($token);
    }
}
