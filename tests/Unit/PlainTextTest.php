<?php

namespace Tests\Unit;

use App\Support\PlainText;
use Tests\TestCase;

class PlainTextTest extends TestCase
{
    public function test_from_html_removes_style_blocks_and_keeps_readable_text(): void
    {
        $html = <<<'HTML'
        <html><head><style>body{margin:0;padding:8px;} p{line-height:1.15;}</style></head><body>
        <p>Жіноча футболка Pobedov Freedom — твоя щоденна свобода бути собою!</p>
        <p>М’яка, дихаюча бавовна дарує відчуття натуральності.</p>
        </body></html>
        HTML;

        $text = PlainText::fromHtml($html);

        $this->assertStringNotContainsString('body{margin:0', $text ?? '');
        $this->assertStringNotContainsString('line-height', $text ?? '');
        $this->assertStringContainsString('Жіноча футболка Pobedov Freedom', $text ?? '');
        $this->assertStringContainsString('М’яка, дихаюча бавовна', $text ?? '');
    }

    public function test_paragraphs_split_description_into_blocks(): void
    {
        $paragraphs = PlainText::paragraphs("<p>Перший абзац.</p><p>Другий абзац.</p>");

        $this->assertSame(['Перший абзац.', 'Другий абзац.'], $paragraphs);
    }

    public function test_from_html_removes_bare_css_rules_without_style_tags(): void
    {
        $css = 'body{margin:0;padding:8px;} p{line-height:1.15;margin:0;white-space:pre-wrap;} ol,ul{margin-top:0;margin-bottom:0;} img{border:none;} li>p{display:inline;} ';
        $html = $css.$css.'Поло Pobedov Royal — елегантна та стильна модель.';

        $text = PlainText::fromHtml($html);

        $this->assertStringNotContainsString('body{margin:0', $text ?? '');
        $this->assertStringNotContainsString('white-space:pre-wrap', $text ?? '');
        $this->assertStringContainsString('Поло Pobedov Royal', $text ?? '');
    }
}
