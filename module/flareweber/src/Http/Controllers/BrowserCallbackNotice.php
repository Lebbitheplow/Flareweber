<?php

namespace FlareWeber\Http\Controllers;

/**
 * Landing page for OAuth callbacks that arrive in the user's system browser
 * (desktop flow). The app itself learns the outcome by polling the handoff
 * token, so this page is only a human-facing confirmation.
 */
trait BrowserCallbackNotice
{
    private function donePage(string $headline, int $status = 200): string
    {
        $safe = htmlspecialchars($headline, ENT_QUOTES);

        return '<!doctype html><meta charset="utf-8"><title>FlareWeber</title>'
            . '<body style="font:16px/1.6 system-ui,sans-serif;max-width:32rem;margin:18vh auto;padding:0 1.5rem">'
            . "<h2>{$safe}</h2><p>You can close this window and return to FlareWeber.</p></body>";
    }
}
