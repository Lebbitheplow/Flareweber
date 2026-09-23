<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Support\Handoff;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class HandoffTest extends TestCase
{
    private function handoff(): Handoff
    {
        return new Handoff(new Repository(new ArrayStore()));
    }

    public function testStartThenPullStateConsumesTheEntry(): void
    {
        $handoff = $this->handoff();
        $handoff->start('state-1', 'token-1', ['verifier' => 'v']);

        $this->assertSame(['status' => 'pending'], $handoff->result('token-1'));
        $this->assertSame(['verifier' => 'v', 'handoff' => 'token-1'], $handoff->pullState('state-1'));
        $this->assertNull($handoff->pullState('state-1'));
    }

    public function testResultRoundTripAndUnknownTokens(): void
    {
        $handoff = $this->handoff();

        $this->assertNull($handoff->result('nope'));

        $handoff->putResult('t', ['status' => 'connected', 'connection_id' => 4]);

        $this->assertSame(['status' => 'connected', 'connection_id' => 4], $handoff->result('t'));
    }

    public function testSanitizeDropsUpstreamBodies(): void
    {
        $this->assertSame(
            'Cloudflare API error on PUT /accounts/x',
            Handoff::sanitize('Cloudflare API error on PUT /accounts/x: {"errors":[{"code":10000,"message":"secret"}]}')
        );
        $this->assertSame('Token exchange failed', Handoff::sanitize('Token exchange failed: <html>oops</html>'));
        $this->assertSame('The request failed.', Handoff::sanitize('{"raw":true}'));
        $this->assertSame(
            ['status' => 'error', 'message' => 'Nope', 'service' => 'stripe'],
            Handoff::errorPayload('Nope: {"x":1}', 'stripe')
        );
    }

    public function testSanitizeTruncatesLongMessages(): void
    {
        $long = str_repeat('a', 500);

        $this->assertSame(200, strlen(Handoff::sanitize($long)));
        $this->assertStringEndsWith('...', Handoff::sanitize($long));
    }
}
