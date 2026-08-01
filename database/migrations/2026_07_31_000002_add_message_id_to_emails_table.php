<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the RFC Message-ID of outbound emails so replies to
     * agent-initiated first messages thread by reference instead of relying
     * on the subject + participants fallback (ThreadResolver).
     */
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('message_id')->nullable()->index()->after('ses_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('message_id');
        });
    }
};
