<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shelter stay: a registration is "in the shelter" until staff record the date the person left
     * (egreso) and why. The reason is free text and is stored encrypted with the app key.
     */
    public function up(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->date('exited_at')->nullable()->after('pending_payload_json')->index();
            $table->text('exit_reason')->nullable()->after('exited_at');
            $table->foreignId('exited_by')->nullable()->after('exit_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exited_by');
            $table->dropIndex(['exited_at']);
            $table->dropColumn(['exited_at', 'exit_reason']);
        });
    }
};
