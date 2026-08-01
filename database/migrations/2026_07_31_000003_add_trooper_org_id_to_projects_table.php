<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Trooper organization owns this project. Lets the admin
     * provisioning API be idempotent (same org re-provisions safely) while
     * detecting slug collisions between different orgs (409).
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('trooper_org_id', 128)->nullable()->index()->after('default_environment');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('trooper_org_id');
        });
    }
};
