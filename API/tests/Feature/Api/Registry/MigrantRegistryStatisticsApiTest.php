<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\UserRole;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrantRegistryStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ATTENTION = 'ra09736a581f046c8aea75da8c905a1b9';
    private const GENDER = 'r8203ecd497f348c58e0f37ee52b0f281';
    private const COUNTRY = 'r64febf36f78743768d7049e10743d53f';
    private const CIVIL = 'rd7dd054b57a449bb8fc272fb5018b270';
    private const LITERACY = 'rf233bf6dce04426e869ab22c29187923';
    private const EDU_ADULT = 'rc4c93ad0575541cc8285754e6d11d9b0';
    private const EDU_MINOR = 'r65ec8fd31b8f421eb0d6c50bdd9115b3';
    private const MEXICO_ENTRY = 'rb5d53bd5db9045dd8fe0b1dc849acdf4';
    private const DESTINATION = 'r2bbdd2ae059d41b988ef4e55381fafde';

    private const URL = '/registry/migrants/statistics';

    public function test_only_admins_and_coordinators_can_read_statistics(): void
    {
        foreach ([UserRole::NonCoordinator, UserRole::Volunteer] as $role) {
            $this->actingAs($this->enrolled($role))->getJson(self::URL)->assertForbidden();
        }

        foreach ([UserRole::Admin, UserRole::Coordinator] as $role) {
            $this->actingAs($this->enrolled($role))->getJson(self::URL)->assertOk();
        }
    }

    public function test_only_approved_registrations_are_counted(): void
    {
        $this->entry(['attention' => '2026-03-02', 'gender' => 'Femenino']);
        $this->entry(['attention' => '2026-03-02', 'gender' => 'Masculino'], 'pending_review');
        $this->entry(['attention' => '2026-03-02', 'gender' => 'Masculino'], 'draft');
        $this->entry(['attention' => '2026-03-02', 'gender' => 'Masculino'], 'rejected');
        $this->entry(['attention' => '2026-03-02', 'gender' => 'Masculino'])->delete();

        $this->actingAs($this->enrolled(UserRole::Admin))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.dimensions.gender.categories', [['value' => 'Femenino', 'count' => 1]]);
    }

    public function test_dimensions_are_aggregated_with_missing_answers_last(): void
    {
        $this->entry(['attention' => '2026-01-10', 'gender' => 'Femenino', 'country' => 'Honduras', 'civil' => 'Soltera / Soltero', 'literacy' => 'Sí', 'eduAdult' => 'Licenciatura', 'destination' => 'Estados Unidos.']);
        $this->entry(['attention' => '2026-01-11', 'gender' => 'Femenino', 'country' => 'Honduras', 'literacy' => 'No', 'eduMinor' => 'Primaria', 'destination' => 'México.']);
        $this->entry(['attention' => '2026-01-12', 'gender' => 'Masculino', 'country' => 'Venezuela (República Bolivariana de)', 'literacy' => 'Sí', 'eduAdult' => 'Sin escolarizar']);

        $data = $this->actingAs($this->enrolled(UserRole::Coordinator))->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(3, $data['total']);
        $this->assertSame([['value' => 'Honduras', 'count' => 2], ['value' => 'Venezuela (República Bolivariana de)', 'count' => 1]], $data['dimensions']['countryOfOrigin']['categories']);
        $this->assertSame(['Sin escolarizar', 'Primaria', 'Licenciatura'], array_column($data['dimensions']['education']['categories'], 'value'));
        $this->assertSame([['value' => 'Sí', 'count' => 2], ['value' => 'No', 'count' => 1]], $data['dimensions']['literacy']['categories']);
        $this->assertSame(['Estados Unidos', 'México', 'Sin respuesta'], array_column($data['dimensions']['finalDestination']['categories'], 'value'));
        $this->assertSame('Sin respuesta', last($data['dimensions']['civilStatus']['categories'])['value']);
    }

    public function test_arrivals_by_month_fill_gaps_and_respect_the_date_range(): void
    {
        $this->entry(['attention' => '2026-01-15']);
        $this->entry(['attention' => '2026-03-01']);
        $this->entry(['attention' => '2026-03-31']);
        $this->entry(['attention' => '2026-05-20']);

        $this->actingAs($this->enrolled(UserRole::Admin))
            ->getJson(self::URL)
            ->assertJsonPath('data.arrivalsByMonth', [
                ['month' => '2026-01', 'count' => 1],
                ['month' => '2026-02', 'count' => 0],
                ['month' => '2026-03', 'count' => 2],
                ['month' => '2026-04', 'count' => 0],
                ['month' => '2026-05', 'count' => 1],
            ]);

        $this->actingAs($this->enrolled(UserRole::Admin))
            ->getJson(self::URL.'?from=2026-03-01&to=2026-03-31')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.arrivalsByMonth', [['month' => '2026-03', 'count' => 2]])
            ->assertJsonPath('data.range.from', '2026-03-01');
    }

    public function test_days_from_mexico_to_monterrey_report_median_bins_and_excluded_records(): void
    {
        $this->entry(['attention' => '2026-02-10', 'mexico' => '2026-02-05']); // 5 days
        $this->entry(['attention' => '2026-02-20', 'mexico' => '2026-02-10']); // 10 days
        $this->entry(['attention' => '2026-04-01', 'mexico' => '2026-02-01']); // 59 days
        $this->entry(['attention' => '2026-02-01', 'mexico' => '2026-03-01']); // inconsistent
        $this->entry(['attention' => '2026-02-01']); // missing

        $summary = $this->actingAs($this->enrolled(UserRole::Admin))->getJson(self::URL)->json('data.mexicoToMonterrey');

        $this->assertSame(3, $summary['count']);
        $this->assertSame(1, $summary['missingDates']);
        $this->assertSame(1, $summary['inconsistentDates']);
        $this->assertEquals(10, $summary['median']);
        $this->assertSame([1, 1, 0, 1, 0, 0, 0, 0], array_column($summary['bins'], 'count'));
    }

    public function test_records_without_attention_date_fall_back_to_creation_date(): void
    {
        $entry = $this->entry([]);
        $entry->forceFill(['created_at' => '2026-06-15 10:00:00'])->save();

        $this->actingAs($this->enrolled(UserRole::Admin))
            ->getJson(self::URL.'?from=2026-06-01&to=2026-06-30')
            ->assertJsonPath('data.total', 1);
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $this->actingAs($this->enrolled(UserRole::Admin))
            ->getJson(self::URL.'?from=2026-05-01&to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function entry(array $values, string $status = 'approved'): MigrantRegistryEntry
    {
        $keys = [
            'attention' => self::ATTENTION, 'gender' => self::GENDER, 'country' => self::COUNTRY,
            'civil' => self::CIVIL, 'literacy' => self::LITERACY, 'eduAdult' => self::EDU_ADULT,
            'eduMinor' => self::EDU_MINOR, 'mexico' => self::MEXICO_ENTRY, 'destination' => self::DESTINATION,
        ];
        $answers = [];

        foreach ($values as $key => $value) {
            $answers[$keys[$key]] = ['value' => $value];
        }

        return MigrantRegistryEntry::query()->create([
            'created_by' => User::factory()->create()->id,
            'created_by_role' => 'volunteer',
            'current_status' => $status,
            'payload_json' => ['schemaVersion' => 2, 'questionnaire' => ['definitionId' => 'test', 'answers' => $answers]],
        ]);
    }

    private function enrolled(UserRole $role): User
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $user->webauthnCredentials()->create([
            'credential_id' => app(Base64UrlService::class)->encode(random_bytes(16)),
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        return $user;
    }
}
