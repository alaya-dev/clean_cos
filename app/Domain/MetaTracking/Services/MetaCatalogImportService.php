<?php

namespace App\Domain\MetaTracking\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class MetaCatalogImportService
{
    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>} */
    public function dryRun(UploadedFile $file): array
    {
        $seenCatalogIds = [];
        $prepared = [];

        foreach ($this->parse($file) as $index => $row) {
            $line = $index + 2;
            $fields = $this->fields($row, $line);
            $catalogId = $fields['meta_catalog_id'];

            if ($catalogId !== null) {
                if (isset($seenCatalogIds[$catalogId])) {
                    throw ValidationException::withMessages([
                        'file' => "L’identifiant catalogue Meta « {$catalogId} » est dupliqué aux lignes {$seenCatalogIds[$catalogId]} et {$line}.",
                    ]);
                }

                $seenCatalogIds[$catalogId] = $line;
            }

            [$product, $ambiguousName] = $this->findProduct($catalogId, $fields['name']);
            $hasConflictingMapping = $product !== null
                && $catalogId !== null
                && $product->meta_catalog_id !== null
                && $product->meta_catalog_id !== $catalogId;
            $isCreatable = $fields['name'] !== null
                && $fields['price_millimes'] !== null
                && $fields['category'] !== null;

            $operation = match (true) {
                $ambiguousName || $hasConflictingMapping => 'conflict',
                $product !== null => 'update',
                $isCreatable => 'create',
                default => 'skipped',
            };

            $prepared[] = [
                'line' => $line,
                'meta_catalog_id' => $catalogId,
                'name' => $fields['name'],
                'price_millimes' => $fields['price_millimes'],
                'description' => $fields['description'],
                'category' => $fields['category'],
                'category_slug' => $fields['category'],
                'provided_fields' => $fields['provided_fields'],
                'product_public_id' => $product?->public_id,
                'operation' => $operation,
                'conflict' => $operation === 'conflict',
                'message' => $this->rowMessage($operation, $ambiguousName, $hasConflictingMapping),
            ];
        }

        return [
            'rows' => $prepared,
            'summary' => [
                'total' => count($prepared),
                'ready' => count(array_filter($prepared, static fn (array $row): bool => in_array($row['operation'], ['update', 'create'], true))),
                'conflicts' => count(array_filter($prepared, static fn (array $row): bool => $row['operation'] === 'conflict')),
                'unmatched' => count(array_filter($prepared, static fn (array $row): bool => $row['operation'] === 'create')),
                'skipped' => count(array_filter($prepared, static fn (array $row): bool => $row['operation'] === 'skipped')),
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows
     * @return array{updated: int, created: int, skipped: int}
     */
    public function commit(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $updated = 0;
            $created = 0;
            $skipped = 0;
            $seenCatalogIds = [];

            foreach ($rows as $row) {
                $operation = (string) ($row['operation'] ?? '');
                if ($operation === 'conflict') {
                    throw ValidationException::withMessages(['rows' => 'Le rapport contient un conflit. Relancez une simulation après correction.']);
                }
                if ($operation === 'skipped') {
                    $skipped++;

                    continue;
                }

                $catalogId = $this->optionalString($row['meta_catalog_id'] ?? null, 120);
                if ($catalogId !== null) {
                    if (isset($seenCatalogIds[$catalogId])) {
                        throw ValidationException::withMessages(['rows' => 'Le rapport contient des identifiants catalogue Meta dupliqués. Relancez une simulation.']);
                    }
                    $seenCatalogIds[$catalogId] = true;
                }

                $provided = $this->providedFields($row);
                $product = isset($row['product_public_id'])
                    ? Product::withTrashed()->where('public_id', $row['product_public_id'])->lockForUpdate()->first()
                    : null;

                if ($product === null) {
                    $product = $this->createProduct($row, $provided);
                    if ($product === null) {
                        $skipped++;

                        continue;
                    }
                    $created++;

                    continue;
                }

                if ($catalogId !== null && $product->meta_catalog_id !== null && $product->meta_catalog_id !== $catalogId) {
                    throw ValidationException::withMessages(['rows' => 'Le rapport tente de remplacer un identifiant catalogue Meta existant. Relancez une simulation après vérification.']);
                }

                $changes = $this->changesForExistingProduct($row, $provided, $catalogId);
                if ($changes !== []) {
                    $product->update($changes);
                }
                $updated++;
            }

            return compact('updated', 'created', 'skipped');
        });
    }

    /** @param array<string, string> $row
     * @return array{meta_catalog_id: ?string, name: ?string, price_millimes: ?int, description: ?string, category: ?string, provided_fields: list<string>}
     */
    private function fields(array $row, int $line): array
    {
        $aliases = $this->normalizeHeaders($row);
        $provided = [];
        $values = [];

        foreach (['meta_catalog_id' => 120, 'name' => 200, 'description' => 10000, 'category' => 190] as $field => $max) {
            $value = $this->optionalString($aliases[$field] ?? null, $max);
            $values[$field] = $value;
            if ($value !== null) {
                $provided[] = $field;
            }
        }

        $price = null;
        if ($this->optionalString($aliases['price'] ?? null, 50) !== null) {
            $price = $this->price($aliases['price'], $line);
            $provided[] = 'price';
        }

        return [
            'meta_catalog_id' => $values['meta_catalog_id'],
            'name' => $values['name'],
            'price_millimes' => $price,
            'description' => $values['description'],
            'category' => $values['category'],
            'provided_fields' => $provided,
        ];
    }

    /** @return array{0: ?Product, 1: bool} */
    private function findProduct(?string $catalogId, ?string $name): array
    {
        if ($catalogId !== null) {
            $mappedProduct = Product::withTrashed()->where('meta_catalog_id', $catalogId)->first();
            if ($mappedProduct !== null || $name === null) {
                return [$mappedProduct, false];
            }
        }
        if ($name === null) {
            return [null, false];
        }

        $matches = Product::withTrashed()->where('name', $name)->limit(2)->get();

        return [$matches->first(), $matches->count() > 1];
    }

    /** @param array<string, mixed> $row
     * @param  list<string>  $provided
     */
    private function createProduct(array $row, array $provided): ?Product
    {
        $name = $this->optionalString($row['name'] ?? null, 200);
        $category = $this->categoryForOrCreate($row['category'] ?? $row['category_slug'] ?? null);
        $price = isset($row['price_millimes']) ? (int) $row['price_millimes'] : null;

        if (! in_array('name', $provided, true) || ! in_array('price', $provided, true) || ! in_array('category', $provided, true) || $name === null || $price === null || $category === null) {
            return null;
        }

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'meta_catalog_id' => $this->optionalString($row['meta_catalog_id'] ?? null, 120),
            'short_description' => in_array('description', $provided, true) ? $this->optionalString($row['description'] ?? null, 10000) : null,
            'regular_price_millimes' => $price,
            'is_active' => false,
            'has_variants' => false,
            'stock_quantity' => 0,
        ]);
    }

    /** @param array<string, mixed> $row
     * @param  list<string>  $provided
     * @return array<string, mixed>
     */
    private function changesForExistingProduct(array $row, array $provided, ?string $catalogId): array
    {
        $changes = [];
        if (in_array('meta_catalog_id', $provided, true) && $catalogId !== null) {
            $changes['meta_catalog_id'] = $catalogId;
        }
        if (in_array('name', $provided, true)) {
            $changes['name'] = $this->optionalString($row['name'] ?? null, 200);
        }
        if (in_array('price', $provided, true)) {
            $changes['regular_price_millimes'] = (int) $row['price_millimes'];
        }
        if (in_array('description', $provided, true)) {
            $changes['short_description'] = $this->optionalString($row['description'] ?? null, 10000);
        }
        if (in_array('category', $provided, true)) {
            $category = $this->categoryForOrCreate($row['category'] ?? $row['category_slug'] ?? null);
            if ($category === null) {
                throw ValidationException::withMessages(['rows' => 'La catégorie importée est invalide.']);
            }
            $changes['category_id'] = $category->id;
        }

        return $changes;
    }

    private function categoryFor(mixed $identifier): ?Category
    {
        $value = $this->optionalString($identifier, 190);
        if ($value === null) {
            return null;
        }

        return Category::query()->where('slug', $value)->orWhere('name', $value)->first();
    }

    private function categoryForOrCreate(mixed $identifier): ?Category
    {
        $value = $this->optionalString($identifier, 190);
        if ($value === null) {
            return null;
        }

        $category = $this->categoryFor($value);
        if ($category !== null) {
            return $category;
        }

        return Category::query()->create([
            'name' => $value,
            'slug' => $this->uniqueCategorySlug($value),
            'is_active' => true,
            'sort_order' => ((int) Category::query()->max('sort_order')) + 1,
        ]);
    }

    /** @param array<string, mixed> $row
     * @return list<string>
     */
    private function providedFields(array $row): array
    {
        if (isset($row['provided_fields']) && is_array($row['provided_fields'])) {
            return array_values(array_intersect(['meta_catalog_id', 'name', 'price', 'description', 'category'], $row['provided_fields']));
        }

        $provided = [];
        foreach (['meta_catalog_id', 'name', 'description', 'category'] as $field) {
            if ($this->optionalString($row[$field] ?? ($field === 'category' ? $row['category_slug'] ?? null : null), 10000) !== null) {
                $provided[] = $field;
            }
        }
        if (array_key_exists('price_millimes', $row) && $row['price_millimes'] !== null) {
            $provided[] = 'price';
        }

        return $provided;
    }

    private function rowMessage(string $operation, bool $ambiguousName, bool $hasConflictingMapping): ?string
    {
        return match ($operation) {
            'create' => 'Un nouveau produit inactif sera créé.',
            'skipped' => 'Ligne ignorée : un nouveau produit exige un nom, un prix et une catégorie.',
            'conflict' => $ambiguousName
                ? 'Conflit : plusieurs produits portent ce nom.'
                : ($hasConflictingMapping ? 'Conflit : le produit possède déjà un autre identifiant catalogue Meta.' : 'Conflit à corriger.'),
            default => null,
        };
    }

    /** @return array<int, array<string, string>> */
    private function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'csv' || $extension === 'txt') {
            return $this->parseCsv($file->getRealPath());
        }
        if ($extension === 'xlsx') {
            return $this->parseXlsx($file->getRealPath());
        }
        throw ValidationException::withMessages(['file' => 'Importez un fichier CSV ou XLSX.']);
    }

    /** @return array<int, array<string, string>> */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw ValidationException::withMessages(['file' => 'Le fichier importé est illisible.']);
        }
        $separator = ',';
        $header = fgetcsv($handle, separator: $separator);
        if ($header === false) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Le CSV doit contenir une ligne d’en-têtes.']);
        }
        if (count($header) === 1) {
            rewind($handle);
            $separator = ';';
            $header = fgetcsv($handle, separator: $separator);
            if ($header === false || count($header) < 2) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Le CSV doit contenir au moins deux colonnes.']);
            }
        }
        $header = array_map(fn ($value): string => strtolower(trim((string) $value)), $header);
        $rows = [];
        while (($values = fgetcsv($handle, separator: $separator)) !== false) {
            if ($values === [null] || count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $normalizedValues = array_map(static fn (mixed $value): string => (string) $value, array_pad($values, count($header), ''));
            $rows[] = array_combine($header, $normalizedValues);
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, array<string, string>> */
    private function parseXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'Le support XLSX est indisponible sur cette installation. Utilisez un CSV.']);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Le fichier XLSX est illisible.']);
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $document = simplexml_load_string($xml);
            if ($document === false) {
                $zip->close();
                throw ValidationException::withMessages(['file' => 'Le fichier XLSX contient des chaînes partagées invalides.']);
            }
            foreach ($document->si as $item) {
                $shared[] = (string) ($item->t ?? implode('', array_map(static fn ($run): string => (string) $run->t, iterator_to_array($item->r ?? []))));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw ValidationException::withMessages(['file' => 'La première feuille XLSX est introuvable.']);
        }
        $document = simplexml_load_string($sheet);
        if ($document === false) {
            throw ValidationException::withMessages(['file' => 'La feuille XLSX est invalide.']);
        }
        $matrix = [];
        foreach ($document->sheetData->row as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                $value = (string) ($cell->v ?? '');
                if ((string) ($cell['t'] ?? '') === 's') {
                    $value = $shared[(int) $value] ?? '';
                }
                $values[] = $value;
            }
            $matrix[] = $values;
        }
        $header = array_map(fn ($value): string => strtolower(trim((string) $value)), $matrix[0] ?? []);

        return array_values(array_filter(array_map(static fn (array $values): array => array_combine($header, array_pad($values, count($header), '')) ?: [], array_slice($matrix, 1))));
    }

    /** @param array<string, string> $row
     * @return array<string, string>
     */
    private function normalizeHeaders(array $row): array
    {
        $aliases = [
            'prix' => 'price',
            'price_tnd' => 'price',
            'description_courte' => 'description',
            'categorie' => 'category',
            'category_slug' => 'category',
            'identifiant_catalogue_meta' => 'meta_catalog_id',
        ];
        $normalized = [];
        foreach ($row as $header => $value) {
            $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? ''));
            $normalized[$aliases[$header] ?? $header] = $value;
        }

        return $normalized;
    }

    private function optionalString(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $normalized = trim((string) $value);

        return $normalized === '' ? null : (mb_strlen($normalized) <= $max ? $normalized : null);
    }

    private function price(mixed $value, int $line): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d{1,3})?$/', $normalized)) {
            throw ValidationException::withMessages(['file' => "Le prix est invalide à la ligne {$line}."]);
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'produit';
        $slug = $base;
        $suffix = 2;
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function uniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categorie';
        $slug = $base;
        $suffix = 2;
        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
