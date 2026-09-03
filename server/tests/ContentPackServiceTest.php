<?php

declare(strict_types=1);

namespace FamilyQuiz\Tests;

use FamilyQuiz\Services\ContentPackService;
use PHPUnit\Framework\TestCase;

final class ContentPackServiceTest extends TestCase
{
    public function testRewritesHostedMediaToPackRelative(): void
    {
        $html = '<p><img src="/familyquiz/media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/abc-def.jpg" alt=""></p>';
        $this->assertSame(
            '<p><img src="media/abc-def.jpg" alt=""></p>',
            ContentPackService::toPackHtml($html)
        );
    }

    public function testRestoresPackRelativeMedia(): void
    {
        $html = '<p><img src="media/abc-def.jpg"></p>';
        $out = ContentPackService::fromPackHtml($html, [
            'abc-def.jpg' => '/familyquiz/media/newproj/111.jpg',
        ]);
        $this->assertSame('<p><img src="/familyquiz/media/newproj/111.jpg"></p>', $out);
    }

    public function testExtractsBasenames(): void
    {
        $html = '<img src="/media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/aaaa-bbbb.png"><img src="media/cccc-dddd.webp">';
        $names = ContentPackService::extractMediaBasenames($html);
        sort($names);
        $this->assertSame(['aaaa-bbbb.png', 'cccc-dddd.webp'], $names);
    }
}
