<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * registry_user_keys keeps only each user's CURRENT public key. This table keeps every public key a user
     * ever had, with when it was enrolled and when and why it stopped being valid, so re-enrolling a user
     * no longer erases the previous key.
     */
    public function up(): void
    {
        Schema::create('registry_user_key_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('public_key', 64);
            $table->string('signing_public_key', 64);
            $table->string('fingerprint', 32)->index();
            $table->timestamp('enrolled_at');
            $table->timestamp('retired_at')->nullable();
            $table->string('retired_reason', 16)->nullable(); // replaced | revoked
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        foreach (DB::table('registry_user_keys')->get() as $key) {
            DB::table('registry_user_key_history')->insert([
                'user_id' => $key->user_id,
                'public_key' => $key->public_key,
                'signing_public_key' => $key->signing_public_key,
                'fingerprint' => $key->fingerprint,
                'enrolled_at' => $key->created_at ?? now(),
                'retired_at' => $key->revoked_at,
                'retired_reason' => $key->revoked_at ? 'revoked' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_user_key_history');
    }
};
