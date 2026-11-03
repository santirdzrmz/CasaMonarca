<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_password_login_locks_out_after_five_failures(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        // Even the correct password is refused while locked out.
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'login_rate_limited');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', [
            'event_type' => AuditEventType::AuthLoginFailed->value,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
    }

    public function test_successful_password_login_resets_the_failure_counter(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertOk();
        $this->postJson('/logout');

        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    public function test_totp_login_locks_out_and_drops_the_challenge_after_five_failures(): void
    {
        $user = $this->totpUser();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertAccepted();

        $wrongCode = $this->wrongCode();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login/totp', ['code' => $wrongCode])->assertUnprocessable();
        }

        $validCode = app(TotpService::class)->currentCode(self::TOTP_SECRET);

        $this->postJson('/login/totp', ['code' => $validCode])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'totp_rate_limited')
            ->assertSessionMissing('auth.pending_totp_user_id');

        $this->assertGuest();
    }

    public function test_totp_lockout_survives_restarting_the_password_step(): void
    {
        $user = $this->totpUser();
        $wrongCode = $this->wrongCode();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertAccepted();
            $this->postJson('/login/totp', ['code' => $wrongCode])->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertAccepted();
        $this->postJson('/login/totp', ['code' => app(TotpService::class)->currentCode(self::TOTP_SECRET)])
            ->assertStatus(429);
    }

    public function test_a_totp_code_cannot_be_used_twice(): void
    {
        $user = $this->totpUser();
        $code = app(TotpService::class)->currentCode(self::TOTP_SECRET);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertAccepted();
        $this->postJson('/login/totp', ['code' => $code])->assertOk();
        $this->postJson('/logout');

        $this->postJson('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertAccepted();
        $this->postJson('/login/totp', ['code' => $code])->assertUnprocessable();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthTotpChallengeFailed->value,
        ]);
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/csrf-token')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_authenticated_responses_are_not_cacheable(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/me')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function totpUser(): User
    {
        return User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => true,
            'two_factor_secret' => self::TOTP_SECRET,
        ]);
    }

    private function wrongCode(): string
    {
        $totp = app(TotpService::class);

        // Pick a code outside the ±1 step window so it is wrong regardless of timing.
        return $totp->currentCode(self::TOTP_SECRET, time() + 3600);
    }
}
