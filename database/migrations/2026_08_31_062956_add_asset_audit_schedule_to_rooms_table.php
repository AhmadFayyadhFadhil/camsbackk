<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('asset_audit_interval')->default('bimonthly')->after('is_active'); // monthly, bimonthly, quarterly, custom
            $table->unsignedSmallInteger('asset_audit_interval_days')->default(60)->after('asset_audit_interval');
            $table->dateTime('last_asset_audit_at')->nullable()->after('asset_audit_interval_days');
            $table->date('next_asset_audit_due')->nullable()->after('last_asset_audit_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'asset_audit_interval',
                'asset_audit_interval_days',
                'last_asset_audit_at',
                'next_asset_audit_due',
            ]);
        });
    }
};
