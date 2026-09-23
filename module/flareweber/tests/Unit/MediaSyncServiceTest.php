<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Deploy\MediaSyncService;
use PHPUnit\Framework\TestCase;

class MediaSyncServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw-media-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/default/.hidden', 0775, true);
        mkdir($this->dir . '/pixum', 0775, true);
        file_put_contents($this->dir . '/default/hero.jpg', 'jpeg-bytes');
        file_put_contents($this->dir . '/default/notes.php', '<?php');
        file_put_contents($this->dir . '/default/page.html', '<p>');
        file_put_contents($this->dir . '/default/.DS_Store', 'x');
        file_put_contents($this->dir . '/default/.hidden/secret.png', 'x');
        file_put_contents($this->dir . '/pixum/big.png', str_repeat('x', 2048));
        file_put_contents($this->dir . '/pixum/clip.mp4', 'mp4');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function testIsSyncableAppliesAllowlistAndDotfileRules(): void
    {
        $this->assertTrue(MediaSyncService::isSyncable('default/hero.jpg'));
        $this->assertTrue(MediaSyncService::isSyncable('docs/Brochure.PDF'));
        $this->assertFalse(MediaSyncService::isSyncable('default/notes.php'));
        $this->assertFalse(MediaSyncService::isSyncable('default/page.html'));
        $this->assertFalse(MediaSyncService::isSyncable('default/.htaccess'));
        $this->assertFalse(MediaSyncService::isSyncable('.hidden/a.png'));
        $this->assertFalse(MediaSyncService::isSyncable('default/.DS_Store'));
        $this->assertFalse(MediaSyncService::isSyncable('default/archive.tar.gz'));
    }

    public function testScanProducesMediaPrefixedKeysWithStreamedHashes(): void
    {
        $service = new MediaSyncService($this->createMock(CloudflareClient::class), 'acc');

        $skipped = 0;
        $files = $service->scan($this->dir, 1024, $skipped);

        $this->assertSame(['media/default/hero.jpg', 'media/pixum/clip.mp4'], array_keys($files));
        $this->assertSame(1, $skipped, 'oversize files are counted, not synced');
        $this->assertSame(hash('sha256', 'jpeg-bytes'), $files['media/default/hero.jpg']['hash']);
        $this->assertSame('image/jpeg', $files['media/default/hero.jpg']['content_type']);
        $this->assertSame('video/mp4', $files['media/pixum/clip.mp4']['content_type']);
        $this->assertSame(10, $files['media/default/hero.jpg']['size']);
    }
}
