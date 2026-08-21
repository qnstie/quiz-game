<?php

declare(strict_types=1);

namespace FamilyQuiz\Tests;

use FamilyQuiz\Support\IframeSanitizer;
use FamilyQuiz\Services\SanitizerService;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    public function testStripsScriptAndEvents(): void
    {
        $s = new SanitizerService(new IframeSanitizer());
        $out = $s->clean('<p>Hi<script>alert(1)</script></p><img src="x" onerror="alert(1)">');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringContainsString('Hi', $out);
    }

    public function testKeepsAllowlistedIframe(): void
    {
        $s = new SanitizerService(new IframeSanitizer());
        $html = '<p>v</p><iframe src="https://www.youtube.com/embed/abc123"></iframe>';
        $out = $s->clean($html);
        $this->assertStringContainsString('youtube.com/embed/abc123', $out);
        $this->assertStringContainsString('<iframe', $out);
    }

    public function testStripsBadIframeHost(): void
    {
        $s = new SanitizerService(new IframeSanitizer());
        $out = $s->clean('<iframe src="https://evil.example/embed"></iframe><p>ok</p>');
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertStringContainsString('ok', $out);
    }

    public function testStripsJavascriptHref(): void
    {
        $s = new SanitizerService(new IframeSanitizer());
        $out = $s->clean('<a href="javascript:alert(1)">x</a>');
        $this->assertStringNotContainsString('javascript:', $out);
    }
}
