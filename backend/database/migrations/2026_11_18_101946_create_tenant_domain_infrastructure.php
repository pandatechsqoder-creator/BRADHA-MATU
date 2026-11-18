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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('slug', 63)->nullable()->unique()->after('name');
            $table->string('country', 2)->nullable()->after('address');
            $table->string('county')->nullable()->after('country');
        });

        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('slug', 63)->unique();
            $table->string('subdomain', 63)->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('status')->default('allocated');
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('domain_reservations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('slug', 63)->unique();
            $table->string('business_name');
            $table->string('owner_email')->nullable();
            $table->string('registration_attempt_uuid', 26)->nullable()->index();
            $table->string('status')->default('reserved');
            $table->timestamp('expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('reserved_domain_names', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('reason')->nullable();
            $table->boolean('blocked')->default(true);
            $table->timestamps();
        });

        Schema::create('domain_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('current_slug', 63);
            $table->string('requested_slug', 63);
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('domain_allocation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('slug', 63);
            $table->string('action');
            $table->string('source')->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['slug', 'created_at']);
            $table->index(['tenant_id', 'action']);
        });

        Schema::create('domain_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        foreach (['www', 'api', 'app', 'admin', 'mail', 'smtp', 'ftp', 'cdn', 'billing', 'support', 'status', 'secure', 'portal', 'auth', 'login', 'register', 'signup', 'pandatech', 'brathamatu'] as $slug) {
            DB::table('reserved_domain_names')->insert([
                'slug' => $slug,
                'reason' => 'Reserved platform or impersonation name',
                'blocked' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('tenants')->get(['id', 'name']) as $tenant) {
            $slug = Str::slug((string) $tenant->name, '');
            $slug = substr(preg_replace('/[^a-z0-9]/', '', strtolower($slug)) ?: 'tenant', 0, 63);
            if ($slug === '') {
                $slug = 'tenant';
            }
            $base = $slug;
            $number = 2;
            while (DB::table('tenants')->where('slug', $slug)->exists() || DB::table('tenant_domains')->where('slug', $slug)->exists()) {
                $slug = substr($base, 0, 58).$number;
                $number++;
            }

            DB::table('tenants')->where('id', $tenant->id)->update(['slug' => $slug]);
            DB::table('tenant_domains')->insert([
                'uuid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'slug' => $slug,
                'subdomain' => $slug,
                'status' => 'allocated',
                'allocated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_settings');
        Schema::dropIfExists('domain_allocation_logs');
        Schema::dropIfExists('domain_change_requests');
        Schema::dropIfExists('reserved_domain_names');
        Schema::dropIfExists('domain_reservations');
        Schema::dropIfExists('tenant_domains');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'country', 'county']);
        });
    }
};
