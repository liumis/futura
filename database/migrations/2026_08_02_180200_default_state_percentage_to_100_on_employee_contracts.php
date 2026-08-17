<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employee_contracts')
            ->whereNull('state_percentage')
            ->update(['state_percentage' => 100]);
    }

    public function down(): void
    {
        //
    }
};
