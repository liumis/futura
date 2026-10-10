<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_request_types', function (Blueprint $table): void {
            $table->string('timesheet_code', 8)->nullable()->after('color');
            $table->unique('timesheet_code');
        });

        $map = [
            'Kasmetinės atostogos' => 'A',
            'Nedarbingumas' => 'L',
            'Neapmokamos atostogos' => 'NA',
            'Tėvadienis / Mamadienis' => 'M',
            'Komandiruotė' => 'K',
            'Darbas nuotoliu' => null,
            'Kita' => 'KR',
        ];

        foreach ($map as $name => $code) {
            DB::table('leave_request_types')
                ->where('name', $name)
                ->update(['timesheet_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_request_types', function (Blueprint $table): void {
            $table->dropUnique(['timesheet_code']);
            $table->dropColumn('timesheet_code');
        });
    }
};
