<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDescriptionDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_description_paragraphs_hide_embedded_css_from_feed_html(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'tshirts']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Футболка',
            'slug' => 'tshirt-html-desc',
            'price' => 650,
            'stock' => 3,
            'description' => '<style>body{margin:0;padding:8px;}</style><p>Жіноча футболка Pobedov Freedom.</p>',
        ]);

        $paragraphs = $product->descriptionParagraphs();

        $this->assertCount(1, $paragraphs);
        $this->assertSame('Жіноча футболка Pobedov Freedom.', $paragraphs[0]);
        $this->assertStringNotContainsString('body{margin:0', $product->plainDescription() ?? '');
    }

    public function test_description_paragraphs_hide_bare_css_rules_from_feed_text(): void
    {
        $category = Category::create(['name' => 'Поло', 'slug' => 'polo-shirts']);
        $css = 'body{margin:0;padding:8px;} p{line-height:1.15;margin:0;white-space:pre-wrap;} ol,ul{margin-top:0;margin-bottom:0;} img{border:none;} li>p{display:inline;} ';

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Поло Pobedov Royal',
            'slug' => 'polo-pobedov-royal',
            'price' => 1200,
            'stock' => 3,
            'description' => $css.$css.'Поло Pobedov Royal — елегантна та стильна модель.',
        ]);

        $paragraphs = $product->descriptionParagraphs();

        $this->assertCount(1, $paragraphs);
        $this->assertStringStartsWith('Поло Pobedov Royal', $paragraphs[0]);
        $this->assertStringNotContainsString('body{margin:0', $paragraphs[0]);
    }
}
