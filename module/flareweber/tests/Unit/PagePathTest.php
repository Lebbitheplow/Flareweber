<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Compiler\PagePath;
use PHPUnit\Framework\TestCase;

class PagePathTest extends TestCase
{
    public function testNormalizeDecodesCollapsesAndStripsTrailingSlash(): void
    {
        $this->assertSame('/', PagePath::normalize(''));
        $this->assertSame('/', PagePath::normalize('///'));
        $this->assertSame('/about', PagePath::normalize('/about/'));
        $this->assertSame("/caf\u{e9}/menu", PagePath::normalize('/caf%C3%A9//menu/'));
        $this->assertSame('/About', PagePath::normalize('/About'), 'paths stay case-sensitive');
    }

    public function testPaginationQueryFoldsIntoPath(): void
    {
        $parts = PagePath::fromUrl('/blog?current_page=2');
        $this->assertSame('/blog/page/2', $parts['path']);
        $this->assertSame('/blog?current_page=2', $parts['request']);
        $this->assertSame('', $parts['query']);

        $parts = PagePath::fromUrl('/shop/?page=3&color=red#top');
        $this->assertSame('/shop/page/3', $parts['path']);
        $this->assertSame('/shop?page=3', $parts['request']);
        $this->assertSame('color=red', $parts['query']);
        $this->assertSame('#top', $parts['fragment']);

        $parts = PagePath::fromUrl('/blog?page=1');
        $this->assertSame('/blog', $parts['path'], 'page 1 is the listing itself');
        $this->assertSame('/blog', $parts['request']);

        $parts = PagePath::fromUrl('/?current_page=2');
        $this->assertSame('/page/2', $parts['path']);
        $this->assertSame('/?current_page=2', $parts['request']);

        $parts = PagePath::fromUrl('/blog/?current_page3373826946=2');
        $this->assertSame('/blog/page/2', $parts['path'], 'Microweber module paging params carry a module suffix');
        $this->assertSame('/blog?current_page3373826946=2', $parts['request']);

        $this->assertTrue(PagePath::isPaginationParam('page'));
        $this->assertTrue(PagePath::isPaginationParam('current_page12'));
        $this->assertFalse(PagePath::isPaginationParam('pages'));
        $this->assertFalse(PagePath::isPaginationParam('homepage'));
    }

    public function testOutputAndFile(): void
    {
        $this->assertSame('/', PagePath::output('/'));
        $this->assertSame('/about/', PagePath::output('/about'));
        $this->assertSame('index.html', PagePath::file('/'));
        $this->assertSame('blog/page/2/index.html', PagePath::file('/blog/page/2/'));
    }

    public function testSkipMatchesWholeSegmentsOnly(): void
    {
        $skip = ['admin', 'api'];
        $this->assertTrue(PagePath::isSkipped('/admin', $skip));
        $this->assertTrue(PagePath::isSkipped('/api/products', $skip));
        $this->assertFalse(PagePath::isSkipped('/administration', $skip));
        $this->assertFalse(PagePath::isSkipped('/apiary', $skip));
        $this->assertFalse(PagePath::isSkipped('/Admin', $skip), 'case-sensitive');
    }

    public function testHasExtension(): void
    {
        $this->assertTrue(PagePath::hasExtension('/userfiles/x/a.css'));
        $this->assertFalse(PagePath::hasExtension('/about'));
        $this->assertFalse(PagePath::hasExtension('/v1.2/docs'));
    }
}
