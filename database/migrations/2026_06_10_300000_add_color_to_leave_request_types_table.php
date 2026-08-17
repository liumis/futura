<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const DEFAULT_COLORS = [
        'Kasmetinės atostogos' => '#3b82f6',
        'Nedarbingumas' => '#ef4444',
        'Neapmokamos atostogos' => '#f97316',
        'Tėvadienis / Mamadienis' => '#a855f7',
        'Komandiruotė' => '#14b8a6',
        'Darbas nuotoliu' => '#22c55e',
        'Kita' => '#6b7280',
    ];

    public function up(): void
    {
        Schema::table('leave_request_types', function (Blueprint $table) {
            $table->string('color', 7)->default('#6b7280')->after('name');
        });

        foreach (self::DEFAULT_COLORS as $name => $color) {
            DB::table('leave_request_types')
                ->where('name', $name)
                ->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_request_types', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
