<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Models\Site;
use FlareWeber\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StripeConnectController extends Controller
{
    public function connect(Site $site, StripeConnectService $stripe)
    {
        return redirect()->away($stripe->authorizeUrl($site));
    }

    public function callback(Request $request, StripeConnectService $stripe, Site $site)
    {
        $data = $request->validate(['code' => 'required|string', 'state' => 'required|string']);

        $stripe->completeConnect($site, $data['code'], $data['state']);

        return redirect('/flareweber/sites/' . $site->id);
    }
}
