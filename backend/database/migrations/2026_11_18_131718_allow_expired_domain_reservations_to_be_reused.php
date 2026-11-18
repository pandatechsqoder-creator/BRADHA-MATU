<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_reservations', function (Blueprint $table): void {
            $table->dropUnique('domain_reservations_slug_unique');
            $table->string('active_slug', 63)
                ->nullable()
                ->storedAs("CASE WHEN status = 'reserved' THEN slug ELSE NULL END")
                ->unique();
        });
    }

    public function down(): void
    {
        $hasDuplicateSlugs = DB::table('domain_reservations')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateSlugs) {
            throw new RuntimeException('Cannot restore unique reservation slugs while reservation history contains reused names.');
        }

        Schema::table('domain_reservations', function (Blueprint $table): void {
            $table->dropUnique('domain_reservations_active_slug_unique');
            $table->dropColumn('active_slug');
            $table->unique('slug');
        });
    }
};
