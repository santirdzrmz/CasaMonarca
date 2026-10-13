<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department attribution: users are assigned one or more departments and
     * every modifying request records which one the actor declared.
     */
    public function up(): void
    {
        Schema::create('user_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('department', 40);
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'department']);
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->string('acting_department', 40)->nullable()->after('actor_role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['acting_department']);
            $table->dropColumn('acting_department');
        });

        Schema::dropIfExists('user_departments');
    }
};
