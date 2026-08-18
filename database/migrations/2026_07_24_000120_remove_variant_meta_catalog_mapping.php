<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $variantRows = DB::table('product_variants')
            ->whereNotNull('meta_catalog_id')
            ->orderBy('product_id')
            ->get(['product_id', 'meta_catalog_id']);
        $productIds = $variantRows->pluck('product_id')->unique()->values();
        $products = DB::table('products')->whereIn('id', $productIds)->get(['id', 'public_id', 'name', 'meta_catalog_id'])->keyBy('id');
        $conflicts = [];
        $parentUpdates = [];

        foreach ($variantRows->groupBy('product_id') as $productId => $rows) {
            $product = $products->get($productId);
            $mappings = $rows
                ->map(static fn (object $row): string => trim((string) $row->meta_catalog_id))
                ->filter(static fn (string $mapping): bool => $mapping !== '')
                ->unique()
                ->values();
            if (! $product || $mappings->count() > 1) {
                $conflicts[] = $this->reference($productId, $product, $mappings);

                continue;
            }
            $mapping = $mappings->first();
            $parentMapping = trim((string) ($product->meta_catalog_id ?? ''));
            if ($mapping !== null && $parentMapping === '') {
                $parentUpdates[$product->id] = $mapping;
            } elseif ($mapping !== null && $parentMapping !== $mapping) {
                $conflicts[] = $this->reference($productId, $product, $mappings, $parentMapping);
            }
        }

        if ($conflicts !== []) {
            throw new RuntimeException('Variant Meta catalogue reconciliation aborted. Conflicts: '.json_encode($conflicts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        DB::transaction(function () use ($parentUpdates): void {
            foreach ($parentUpdates as $productId => $mapping) {
                DB::table('products')->where('id', $productId)->update(['meta_catalog_id' => $mapping]);
            }
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(['meta_catalog_id']);
            $table->dropColumn('meta_catalog_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('meta_catalog_id', 120)->nullable()->unique()->after('public_id');
        });
    }

    /** @return array<string, mixed> */
    private function reference(int|string $productId, ?object $product, Collection $mappings, ?string $parentMapping = null): array
    {
        return [
            'internal_product_id' => $productId,
            'product_public_id' => $product?->public_id,
            'product_name' => $product?->name,
            'variant_mappings' => $mappings->all(),
            'parent_mapping' => $parentMapping,
        ];
    }
};
