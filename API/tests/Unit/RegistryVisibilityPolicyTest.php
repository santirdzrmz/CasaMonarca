<?php

namespace Tests\Unit;

use App\Services\Registry\RegistryVisibilityPolicy;
use PHPUnit\Framework\TestCase;

class RegistryVisibilityPolicyTest extends TestCase
{
    private const QUEUE = ['pending_review', 'changes_requested'];

    private function level(string $role, string $status, bool $owner): string
    {
        return (new RegistryVisibilityPolicy)->level($role, $status, $owner, self::QUEUE);
    }

    public function test_admin_and_coordinator_see_everything_after_submission(): void
    {
        foreach (['admin', 'coordinator'] as $role) {
            foreach (['pending_review', 'pending_approval', 'approved', 'rejected'] as $status) {
                $this->assertSame('full', $this->level($role, $status, false));
            }
        }
    }

    public function test_volunteer_never_sees_content_and_only_sees_own_entries(): void
    {
        $this->assertSame('metadata', $this->level('volunteer', 'pending_review', true));
        $this->assertSame('none', $this->level('volunteer', 'pending_review', false));
        $this->assertSame('none', $this->level('volunteer', 'approved', false));
    }

    public function test_operator_sees_content_only_of_others_in_the_review_queue(): void
    {
        $this->assertSame('full', $this->level('non_coordinator', 'pending_review', false));
        $this->assertSame('full', $this->level('non_coordinator', 'changes_requested', false));
        $this->assertSame('metadata', $this->level('non_coordinator', 'approved', false));
        $this->assertSame('metadata', $this->level('non_coordinator', 'pending_approval', false));
    }

    public function test_operator_cannot_read_content_of_what_they_registered(): void
    {
        $this->assertSame('metadata', $this->level('non_coordinator', 'pending_review', true));
    }

    public function test_drafts_belong_only_to_their_owner(): void
    {
        foreach (['admin', 'coordinator', 'non_coordinator', 'volunteer'] as $role) {
            $this->assertSame('full', $this->level($role, 'draft', true));
            $this->assertSame('none', $this->level($role, 'draft', false));
        }
    }

    public function test_unknown_roles_see_nothing(): void
    {
        $this->assertSame('none', $this->level('guest', 'approved', false));
    }
}
