<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $primarySupplierId = DB::table('suppliers')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->value('id');

        if ($primarySupplierId === null) {
            $primarySupplierId = DB::table('suppliers')->orderBy('id')->value('id');
        }

        if ($primarySupplierId === null) {
            return;
        }

        DB::table('collections')->update(['supplier_id' => $primarySupplierId]);

        $collectionNames = DB::table('collections')->pluck('name');

        $duplicateSupplierIds = DB::table('suppliers')
            ->where('id', '!=', $primarySupplierId)
            ->whereIn('name', $collectionNames)
            ->pluck('id');

        if ($duplicateSupplierIds->isEmpty()) {
            return;
        }

        DB::table('cargos')
            ->whereIn('supplier_id', $duplicateSupplierIds)
            ->update(['supplier_id' => $primarySupplierId]);

        DB::table('suppliers')
            ->whereIn('id', $duplicateSupplierIds)
            ->delete();
    }

    public function down(): void
    {
        // Not reversible: duplicate supplier rows were removed.
    }
};
