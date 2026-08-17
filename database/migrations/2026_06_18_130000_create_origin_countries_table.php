<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origin_countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $now = now();

        $rows = array_map(fn (string $name): array => [
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->countries());

        DB::table('origin_countries')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('origin_countries');
    }

    /**
     * @return list<string>
     */
    private function countries(): array
    {
        return [
            'Albania',
            'Andorra',
            'Armenia',
            'Austria',
            'Azerbaijan',
            'Belarus',
            'Belgium',
            'Bosnia and Herzegovina',
            'Bulgaria',
            'Croatia',
            'Cyprus',
            'Czech Republic',
            'Denmark',
            'Estonia',
            'Finland',
            'France',
            'Georgia',
            'Germany',
            'Greece',
            'Hungary',
            'Iceland',
            'India',
            'Ireland',
            'Italy',
            'Kazakhstan',
            'Kosovo',
            'Latvia',
            'Liechtenstein',
            'Lithuania',
            'Luxembourg',
            'Malta',
            'Moldova',
            'Monaco',
            'Montenegro',
            'Netherlands',
            'North Macedonia',
            'Norway',
            'Poland',
            'Portugal',
            'Romania',
            'San Marino',
            'Serbia',
            'Slovakia',
            'Slovenia',
            'Spain',
            'Sweden',
            'Switzerland',
            'Turkey',
            'Ukraine',
            'United Kingdom',
            'Vatican City',
        ];
    }
};
