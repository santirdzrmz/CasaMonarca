<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 of docs/operations/data-at-rest-encryption.md: additive, versioned
     * envelope columns. Plaintext columns stay until backfill and rollback window end.
     */
    public function up(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->longText('payload_json_enc')->nullable()->after('payload_json');
            $table->longText('pending_payload_json_enc')->nullable()->after('pending_payload_json');
        });

        Schema::table('migrant_arco_requests', function (Blueprint $table) {
            $table->longText('original_payload_json_enc')->nullable()->after('original_payload_json');
            $table->longText('proposed_payload_json_enc')->nullable()->after('proposed_payload_json');
        });
    }

    public function down(): void
    {
        Schema::table('migrant_arco_requests', function (Blueprint $table) {
            $table->dropColumn(['original_payload_json_enc', 'proposed_payload_json_enc']);
        });

        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->dropColumn(['payload_json_enc', 'pending_payload_json_enc']);
        });
    }
};
