<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public keys of each user who holds keys (coordinators and admins). Private keys live in their key file.
        Schema::create('registry_user_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('public_key', 64);
            $table->string('signing_public_key', 64);
            $table->string('fingerprint', 32);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Domain private keys (pk:cand-N, pk:proc-N) encrypted with the user's public key.
        Schema::create('registry_key_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('kek_id', 64);
            $table->text('wrapped');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Operator session key (Ks_pub). The private half lives only in the cache for the session lifetime.
        Schema::create('registry_access_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('session_hash', 64);
            $table->string('public_key', 64);
            $table->string('fingerprint', 32);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'session_hash']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Temporary, signed access of an operator to ONE record (registry entry or ARCO request).
        // status: requested -> granted | denied; granted -> revoked.
        Schema::create('registry_access_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('session_id');
            $table->string('resource_type', 16);
            $table->unsignedBigInteger('resource_id');
            $table->string('status', 12)->index();
            $table->string('reason', 255)->nullable();
            $table->text('wrapped_deks')->nullable();
            $table->unsignedBigInteger('signed_by')->nullable();
            $table->text('signature')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('registry_access_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_access_grants');
        Schema::dropIfExists('registry_access_sessions');
        Schema::dropIfExists('registry_key_grants');
        Schema::dropIfExists('registry_user_keys');
    }
};
