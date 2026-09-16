<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('old_slug')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('current_value');
        });

        $this->updateImportedProducts();
        $this->updateOrders();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('product_slug_redirects');
    }

    private function updateImportedProducts(): void
    {
        $usedSlugs = DB::table('products')
            ->whereNull('external_id')
            ->pluck('slug')
            ->filter()
            ->flip()
            ->map(fn (): bool => true)
            ->all();
        $updates = [];
        $redirects = [];

        DB::table('products')
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->chunkById(1000, function ($products) use (&$updates, &$redirects, &$usedSlugs): void {
                foreach ($products as $product) {
                    $nameWithoutExternalId = preg_replace(
                        '/(?<!\d)'.preg_quote((string) $product->external_id, '/').'(?!\d)/u',
                        ' ',
                        $product->name,
                    ) ?: $product->name;
                    $base = Str::limit(Str::slug($nameWithoutExternalId) ?: 'tovar', 220, '');
                    $slug = $base;
                    $suffix = 2;

                    while (isset($usedSlugs[$slug])) {
                        $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
                        $suffix++;
                    }

                    $usedSlugs[$slug] = true;
                    $updates[] = [
                        'id' => $product->id,
                        'slug' => $slug,
                        'slug_ru' => $slug,
                        'sku' => Product::siteSkuForId((int) $product->id),
                    ];

                    if ($product->slug !== $slug) {
                        $redirects[] = [
                            'old_slug' => $product->slug,
                            'product_id' => $product->id,
                        ];
                    }
                }

                $this->updateProductIdentities($updates);
                DB::table('product_slug_redirects')->insertOrIgnore($redirects);
                $updates = [];
                $redirects = [];
            });
    }

    private function updateOrders(): void
    {
        $orders = DB::table('orders')->orderBy('id')->get(['id']);

        if ($orders->isNotEmpty()) {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update([
                    'number' => (string) (100000 + (int) $order->id),
                ]);
            }
        }

        DB::table('order_number_sequences')->insert([
            'id' => 1,
            'current_value' => 100000 + (int) $orders->max('id'),
        ]);
    }

    private function updateProductIdentities(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach (['slug', 'slug_ru', 'sku'] as $column) {
            $case = "CASE id\n";

            foreach ($updates as $update) {
                $case .= "WHEN ? THEN ?\n";
                $bindings[] = $update['id'];
                $bindings[] = $update[$column];
            }

            $cases[$column] = $case.'END';
        }

        $ids = array_column($updates, 'id');
        $bindings = [...$bindings, ...$ids];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::update(
            "UPDATE products SET slug = {$cases['slug']}, slug_ru = {$cases['slug_ru']}, sku = {$cases['sku']} WHERE id IN ({$placeholders})",
            $bindings,
        );
    }
};
