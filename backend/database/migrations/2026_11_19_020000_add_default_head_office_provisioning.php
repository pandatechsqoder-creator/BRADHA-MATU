<?php

use App\Models\Tenant;
use App\Services\Tenancy\DefaultHeadOfficeProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('status');
            $table->index(['tenant_id', 'is_default'], 'branches_tenant_default_index');
        });

        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('status');
            $table->index(['tenant_id', 'is_default'], 'stock_locations_tenant_default_index');
        });

        $provisioner = app(DefaultHeadOfficeProvisioner::class);
        Tenant::query()->orderBy('id')->each(
            fn (Tenant $tenant) => $provisioner->provision($tenant),
        );
    }

    public function down(): void
    {
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->dropIndex('stock_locations_tenant_default_index');
            $table->dropColumn('is_default');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropIndex('branches_tenant_default_index');
            $table->dropColumn('is_default');
        });
    }
};
