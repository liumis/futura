<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class HistoricalSalesOrderImporter
{
    public const HISTORY_NAME_PREFIX = 'HIST|';

    /**
     * Known Futura short-code → normalized tokens used to match existing customers.
     *
     * @var array<string, list<string>>
     */
    protected array $customerAliases = [
        'ABITEXSRL' => ['ABITEXSRL', 'ABITEX', 'ARITEXSRL', 'ARITEX'],
        'ARIELITALIASRLS' => ['ARIELITALIA', 'ARIEL'],
        'ATSTYLE' => ['ATSTYLE'],
        'AUTOLEDER' => ['AUTOLEDER'],
        'BISKINISBROS' => ['BISKINISBROS', 'BIKINISBROS', 'BIKINIS', 'BISKINIS'],
        'LAURITZON' => ['LAURITZON'],
        'MYSTICEURIMPEX' => ['MYSTICEURIMPEX', 'MYSTIC'],
        'PEDROTAVARES' => ['PEDROTAVARES'],
        'PONCZYNSKI' => ['PONCZYNSKI'],
        'SVETMAR' => ['SVETMAR'],
        'TENATEXTRADE' => ['TENATEXTRADE', 'TENATEX'],
        'TEXIKOM' => ['TEXIKOM', 'TEXKOM'],
        'TYLIGADESBROS' => ['TYLIGADESBROS', 'TYLIGADASBROS', 'TYLIGADES', 'TYLIGADAS', 'VIOTAP'],
        'VINILAS' => ['VINILAS'],
    ];

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{
     *     customers_created: int,
     *     customers_matched: int,
     *     orders_created: int,
     *     orders_skipped: int,
     *     items_created: int,
     *     lines_skipped_no_product: int,
     *     lines_skipped_invalid: int,
     *     unmatched_items: array<string, int>
     * }
     */
    public function import(?array $rows = null, ?string $path = null): array
    {
        $rows ??= $this->loadRows($path);

        $role = Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
        $productsByCode = $this->productLookup();

        $stats = [
            'customers_created' => 0,
            'customers_matched' => 0,
            'orders_created' => 0,
            'orders_skipped' => 0,
            'items_created' => 0,
            'lines_skipped_no_product' => 0,
            'lines_skipped_invalid' => 0,
            'unmatched_items' => [],
        ];

        $groups = [];

        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row);
            if ($normalized === null) {
                $stats['lines_skipped_invalid']++;

                continue;
            }

            $itemCode = strtoupper($normalized['item']);
            $product = $productsByCode[$itemCode] ?? null;
            if ($product === null) {
                $stats['lines_skipped_no_product']++;
                $stats['unmatched_items'][$itemCode] = ($stats['unmatched_items'][$itemCode] ?? 0) + 1;

                continue;
            }

            $groupKey = $normalized['customer_code'].'|'.$normalized['sales_date'];
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'customer_code' => $normalized['customer_code'],
                    'customer' => $normalized['customer'],
                    'sales_date' => $normalized['sales_date'],
                    'lines' => [],
                    'sales_total' => 0.0,
                ];
            }

            $productId = (int) $product->id;
            if (! isset($groups[$groupKey]['lines'][$productId])) {
                $groups[$groupKey]['lines'][$productId] = 0;
            }
            $groups[$groupKey]['lines'][$productId] += $normalized['quantity'];
            $groups[$groupKey]['sales_total'] += $normalized['sales_eur'];
        }

        arsort($stats['unmatched_items']);

        DB::transaction(function () use ($groups, $role, &$stats): void {
            $customerCache = [];

            foreach ($groups as $group) {
                if ($group['lines'] === []) {
                    continue;
                }

                $code = $group['customer_code'];
                if (! isset($customerCache[$code])) {
                    [$user, $created] = $this->resolveCustomer($code, $group['customer'], $role);
                    $customerCache[$code] = $user;
                    if ($created) {
                        $stats['customers_created']++;
                    } else {
                        $stats['customers_matched']++;
                    }
                }

                $user = $customerCache[$code];
                $historyName = $this->historyName($code, $group['sales_date']);

                if (Order::query()->where('name', $historyName)->exists()) {
                    $stats['orders_skipped']++;

                    continue;
                }

                // Create completed history without reserving stock (do not call OrderStockAllocator).
                $order = Order::query()->create([
                    'name' => $historyName,
                    'status' => OrderStatus::Completed,
                    'stock_reserved' => false,
                    'amount' => round($group['sales_total'], 2),
                    'shipping_cost' => 0,
                    'order_date' => Carbon::parse($group['sales_date'])->setTime(12, 0),
                    'user_id' => $user->id,
                ]);

                foreach ($group['lines'] as $productId => $qty) {
                    $product = Product::query()->find($productId);
                    $amount = $product instanceof Product
                        ? $product->rollAmountFromMeters((int) $qty)
                        : (int) $qty;

                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'amount' => $amount,
                    ]);
                    $stats['items_created']++;
                }

                $stats['orders_created']++;
            }
        });

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadRows(?string $path = null): array
    {
        $path ??= database_path('data/historical-sales-orders.json');

        if (! is_file($path)) {
            throw new InvalidArgumentException('Historical sales file not found: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $payload['rows'] ?? $payload;

        if (! is_array($rows)) {
            throw new InvalidArgumentException('Historical sales file has no rows array.');
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{customer_code: string, customer: string, sales_date: string, item: string, quantity: int, sales_eur: float}|null
     */
    protected function normalizeRow(array $row): ?array
    {
        $customer = trim((string) ($row['customer'] ?? $row['Customer'] ?? ''));
        $code = trim((string) ($row['customer_code'] ?? $row['Customer (code)'] ?? ''));
        $date = trim((string) ($row['sales_date'] ?? $row['Sales date'] ?? ''));
        $item = trim((string) ($row['item'] ?? $row['Item'] ?? ''));
        $qtyRaw = $row['quantity'] ?? $row['Quantity'] ?? null;
        $salesRaw = $row['sales_eur'] ?? $row['Sales, €'] ?? $row['Sales, EUR'] ?? null;

        if ($code === '' && $customer !== '') {
            $code = $this->inferCustomerCode($customer);
        }

        if ($code === '' || $customer === '' || $date === '' || $item === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $quantity = (int) preg_replace('/[^\d\-]/', '', (string) $qtyRaw);
        if ($quantity <= 0) {
            return null;
        }

        $sales = $this->parseEuro((string) $salesRaw);
        if ($sales < 0) {
            return null;
        }

        return [
            'customer_code' => strtoupper(preg_replace('/\s+/', '', $code) ?? $code),
            'customer' => $customer,
            'sales_date' => $date,
            'item' => $item,
            'quantity' => $quantity,
            'sales_eur' => $sales,
        ];
    }

    public function inferCustomerCode(string $customerName): string
    {
        $norm = $this->normalizeKey($customerName);

        foreach ($this->customerAliases as $code => $tokens) {
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($norm, $token)) {
                    return $code;
                }
            }
        }

        return $norm !== '' ? $norm : 'UNKNOWN';
    }

    /**
     * @return array{0: User, 1: bool} [user, created]
     */
    public function resolveCustomer(string $code, string $companyName, Role $role): array
    {
        $existing = $this->findCustomer($code, $companyName);
        if ($existing !== null) {
            if (! $existing->hasRole($role)) {
                $existing->assignRole($role);
            }

            return [$existing, false];
        }

        $email = $this->placeholderEmail($code, $companyName);
        $user = User::query()->create([
            'name' => Str::limit($companyName, 255, ''),
            'surname' => null,
            'email' => $email,
            'password' => Str::password(20),
            'company_name' => $companyName,
            'company_code' => $code,
            'company_address' => null,
            'company_shipping_address' => null,
            'company_country' => null,
            'export' => true,
            'invoice_language' => InvoiceLanguage::English->value,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role);

        return [$user, true];
    }

    protected function findCustomer(string $code, string $companyName): ?User
    {
        $tokens = $this->customerAliases[$code] ?? [$this->normalizeKey($code)];
        $nameNorm = $this->normalizeKey($companyName);

        $candidates = User::query()
            ->where(function ($query) use ($code, $companyName): void {
                $query->where('company_code', $code)
                    ->orWhere('company_name', $companyName);
            })
            ->get();

        if ($candidates->isEmpty()) {
            $candidates = User::query()
                ->whereNotNull('company_name')
                ->where('company_name', '!=', '')
                ->get();
        }

        foreach ($candidates as $user) {
            $userNorm = $this->normalizeKey((string) $user->company_name);
            $userCodeNorm = $this->normalizeKey((string) ($user->company_code ?? ''));

            if ($userCodeNorm !== '' && ($userCodeNorm === $this->normalizeKey($code) || in_array($userCodeNorm, $tokens, true))) {
                return $user;
            }

            if ($userNorm === $nameNorm) {
                return $user;
            }

            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($userNorm, $token)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, Product>
     */
    public function productLookup(): array
    {
        $map = [];

        Product::query()
            ->get(['id', 'product_code', 'alternative_code', 'dsv_code'])
            ->each(function (Product $product) use (&$map): void {
                foreach ([$product->product_code, $product->alternative_code, $product->dsv_code] as $code) {
                    if (filled($code)) {
                        $map[strtoupper(trim((string) $code))] = $product;
                    }
                }
            });

        return $map;
    }

    protected function historyName(string $customerCode, string $salesDate): string
    {
        return self::HISTORY_NAME_PREFIX.$customerCode.'|'.$salesDate;
    }

    protected function parseEuro(string $value): float
    {
        $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
        if ($value === '') {
            return 0.0;
        }

        $value = str_replace(',', '.', $value);

        return round((float) $value, 2);
    }

    protected function normalizeKey(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = Str::upper(Str::ascii($value));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    protected function placeholderEmail(string $code, string $companyName): string
    {
        $slug = Str::slug($code ?: $companyName);
        if ($slug === '') {
            $slug = 'customer-'.Str::lower(Str::random(8));
        }

        $email = $slug.'@import.futura.local';
        $base = $email;
        $i = 1;
        while (User::withTrashed()->where('email', $email)->exists()) {
            $email = $slug.'-'.$i.'@import.futura.local';
            $i++;
            if ($i > 50) {
                return $slug.'-'.Str::lower(Str::random(4)).'@import.futura.local';
            }
        }

        return $email;
    }
}
