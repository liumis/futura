<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Str;

class FuturaCustomerLevelBootstrap
{
    /** @var array<string, float> */
    public const C1_PRICES = [
        'Agnona' => 6.90,
        'Argano' => 7.20,
        'Borga' => 5.60,
        'Paloma' => 6.90,
        'Saramo' => 6.90,
    ];

    /**
     * Vinilas F kainoraštis (€/m) — lower than C1.
     *
     * @var array<string, float>
     */
    public const V_PRICES = [
        'Agnona' => 6.40,
        'Argano' => 6.70,
        'Borga' => 5.10,
        'Paloma' => 6.40,
        'Saramo' => 6.40,
    ];

    /**
     * Representative Karmman domestic list (Altas row) for overlapping collections.
     *
     * @var array<string, float>
     */
    public const K_PRICES = [
        'Agnona' => 8.50,
        'Argano' => 8.50,
        'Borga' => 7.10,
        'Paloma' => 8.50,
        'Saramo' => 8.50,
        'Eraser' => 8.50,
        'Ferrara' => 8.50,
    ];

    /**
     * F kainoraštis clients on standard C1 (Vinilas uses group V).
     *
     * @var list<string>
     */
    public const C1_CUSTOMER_NEEDLES = [
        'atstyle',
        'a & t style',
        'a&t style',
        'autoleder',
        'pedro tavares',
        'pedrotavares',
        'tena tex',
        'tenatex',
        'lauritzon',
        'svetmar',
        'biskinis',
        'bikinis',
        'texikom',
        'texkom',
    ];

    /**
     * @var list<string>
     */
    public const V_CUSTOMER_NEEDLES = [
        'vinilas',
    ];

    /**
     * K kainoraštis / Karmman domestic clients.
     *
     * @var list<string>
     */
    public const K_CUSTOMER_NEEDLES = [
        'altas',
        'jurkus',
        'vikme',
        'vikmė',
        'maudis',
        'forveda',
        'universal',
        'universals',
        'autoresta',
        'termokryt',
        'textelita',
        'ostersjo',
        'östersjö',
        'rugenis',
        'karmman',
    ];

    /**
     * Catalog (€/vnt) list prices from Darbai F → Katalogų kainos.
     *
     * @var array<string, float>
     */
    public const CATALOG_PRODUCT_PRICES = [
        'Agnona' => 2.70,
        'Argano' => 2.10,
        'Borga' => 2.00,
        'Paloma' => 2.10,
        'Saramo' => 1.40,
        'Segtuvas' => 3.00,
    ];

    /**
     * @return array{
     *     c1_id: int,
     *     v_id: int,
     *     k_id: int,
     *     c1_assigned: int,
     *     v_assigned: int,
     *     k_assigned: int,
     *     collections_updated: int,
     *     catalog_products_updated: int
     * }
     */
    public function run(): array
    {
        $c1 = CustomerLevel::query()->firstOrCreate(['name' => 'C1']);
        $v = CustomerLevel::query()->firstOrCreate(['name' => 'V']);
        $k = CustomerLevel::query()->firstOrCreate(['name' => 'K']);

        $this->syncPrices($c1, self::C1_PRICES);
        $this->syncPrices($v, self::V_PRICES);
        $this->syncPrices($k, self::K_PRICES);

        return [
            'c1_id' => (int) $c1->id,
            'v_id' => (int) $v->id,
            'k_id' => (int) $k->id,
            'c1_assigned' => $this->assignLevel($c1, self::C1_CUSTOMER_NEEDLES),
            'v_assigned' => $this->assignLevel($v, self::V_CUSTOMER_NEEDLES),
            'k_assigned' => $this->assignLevel($k, self::K_CUSTOMER_NEEDLES),
            'collections_updated' => $this->applyStandardCollectionPrices(),
            'catalog_products_updated' => $this->applyCatalogProductPrices(),
        ];
    }

    /**
     * Set collection default sell prices from F kainoraštis (€/m).
     */
    public function applyStandardCollectionPrices(): int
    {
        $updated = 0;

        foreach (self::C1_PRICES as $name => $price) {
            $collection = Collection::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->first();
            if ($collection === null) {
                continue;
            }

            if ((float) $collection->price === (float) $price) {
                continue;
            }

            $collection->forceFill(['price' => $price])->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Set Catalog SKU unit prices (products.cost) from F katalogų kainos.
     */
    public function applyCatalogProductPrices(): int
    {
        $catalogTypeId = ProductType::query()->where('key', 'catalog')->value('id');
        if ($catalogTypeId === null) {
            return 0;
        }

        $updated = 0;

        foreach (self::CATALOG_PRODUCT_PRICES as $collectionName => $price) {
            $products = Product::query()
                ->where('product_type_id', $catalogTypeId)
                ->where(function ($query) use ($collectionName): void {
                    $query->whereHas('color.collection', fn ($q) => $q->whereRaw('LOWER(name) = ?', [Str::lower($collectionName)]))
                        ->orWhere('product_code', 'like', '%'.Str::upper($collectionName).'%')
                        ->orWhere('alternative_code', 'like', '%'.Str::upper($collectionName).'%');
                })
                ->get();

            foreach ($products as $product) {
                if ((float) $product->cost === (float) $price) {
                    continue;
                }

                $product->forceFill(['cost' => $price])->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param  array<string, float>  $pricesByCollection
     */
    public function syncPrices(CustomerLevel $level, array $pricesByCollection): void
    {
        $collections = Collection::query()
            ->whereIn('name', array_keys($pricesByCollection))
            ->get()
            ->keyBy(fn (Collection $collection): string => Str::lower($collection->name));

        foreach ($pricesByCollection as $name => $price) {
            $collection = $collections->get(Str::lower($name));
            if ($collection === null) {
                continue;
            }

            CustomerLevelPrice::query()->updateOrCreate(
                [
                    'customer_level_id' => $level->id,
                    'collection_id' => $collection->id,
                ],
                ['price' => $price],
            );
        }
    }

    /**
     * @param  list<string>  $needles
     */
    public function assignLevel(CustomerLevel $level, array $needles): int
    {
        $assigned = 0;

        $customers = User::query()
            ->where(function ($query): void {
                $query->whereHas('roles', fn ($role) => $role->where('name', User::ROLE_CUSTOMER))
                    ->orWhereNotNull('company_code')
                    ->orWhereNotNull('company_name');
            })
            ->get();

        foreach ($customers as $user) {
            $haystack = Str::lower(trim(implode(' ', array_filter([
                (string) $user->company_code,
                (string) $user->company_name,
                (string) $user->name,
            ]))));

            $haystackCompact = preg_replace('/[^a-z0-9]+/', '', $haystack) ?? $haystack;

            foreach ($needles as $needle) {
                $needleCompact = preg_replace('/[^a-z0-9]+/', '', Str::lower($needle)) ?? Str::lower($needle);
                if ($needleCompact !== '' && (
                    str_contains($haystack, Str::lower($needle))
                    || str_contains($haystackCompact, $needleCompact)
                )) {
                    if ((int) $user->customer_level_id !== (int) $level->id) {
                        $user->forceFill(['customer_level_id' => $level->id])->save();
                        $assigned++;
                    }
                    break;
                }
            }
        }

        return $assigned;
    }
}
