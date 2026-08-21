<?php

declare(strict_types=1);

namespace FamilyQuiz\Services;

use FamilyQuiz\Support\IframeSanitizer;
use HTMLPurifier;
use HTMLPurifier_Config;

final class SanitizerService
{
    private HTMLPurifier $purifier;

    public function __construct(private IframeSanitizer $iframes)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.DefinitionID', 'family-quiz-1');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3',
            'ul', 'ol', 'li', 'blockquote',
            'a[href|title|target|rel|class]',
            'img[src|alt|title|width|height|class]',
            'hr', 'span[class]', 'div[class]',
            'table', 'thead', 'tbody', 'tr', 'td[colspan|rowspan]', 'th[colspan|rowspan]',
            'audio[src|controls|preload|class]',
            'video[src|controls|preload|poster|width|height|class]',
            'source[src|type]',
            'figure', 'figcaption',
        ]));
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);

        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('audio', 'Block', 'Optional: #PCDATA | source', 'Common', [
                'src' => 'URI',
                'controls' => 'Bool',
                'preload' => 'Enum#none,metadata,auto',
            ]);
            $def->addElement('video', 'Block', 'Optional: #PCDATA | source', 'Common', [
                'src' => 'URI',
                'controls' => 'Bool',
                'preload' => 'Enum#none,metadata,auto',
                'poster' => 'URI',
                'width' => 'Length',
                'height' => 'Length',
            ]);
            $def->addElement('source', 'Inline', 'Empty', 'Common', [
                'src' => 'URI',
                'type' => 'Text',
            ]);
            $def->addElement('figure', 'Block', 'Optional: #PCDATA | Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
        }

        $this->purifier = new HTMLPurifier($config);
    }

    public function clean(string $html): string
    {
        [$stripped, $tokens] = $this->iframes->extract($html);
        $clean = $this->purifier->purify($stripped);
        $restored = $this->iframes->restore($clean, $tokens);
        return $this->forceExternalLinks($restored);
    }

    private function forceExternalLinks(string $html): string
    {
        return preg_replace_callback(
            '/<a\s+([^>]*href=["\'](https?:\/\/[^"\']+)["\'][^>]*)>/i',
            static function (array $m): string {
                $attrs = $m[1];
                if (!preg_match('/\btarget=/i', $attrs)) {
                    $attrs .= ' target="_blank"';
                }
                if (!preg_match('/\brel=/i', $attrs)) {
                    $attrs .= ' rel="noopener noreferrer"';
                }
                return '<a ' . $attrs . '>';
            },
            $html
        ) ?? $html;
    }
}
