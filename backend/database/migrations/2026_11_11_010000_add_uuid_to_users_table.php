<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('uuid')->nullable()->unique()->after('id');
        });

        foreach (DB::table('users')->whereNull('uuid')->cursor() as $user) {
            DB::table('users')->where('id', $user->id)->update(['uuid' => (string) Str::ulid()]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('uuid');
        });
    }
};
