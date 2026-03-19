<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Team;
use App\Services\Workspace\WorkspaceProvisioningService;
use PHPUnit\Framework\TestCase;

class WorkspaceProvisioningServiceTest extends TestCase
{
    public function test_builds_expected_root_prefixes_for_shared_and_user_workspaces(): void
    {
        $service = new WorkspaceProvisioningService;
        $organization = new Organization(['id' => 10]);
        $team = new Team(['id' => 20, 'organization_id' => 10]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildRootPrefix');
        $method->setAccessible(true);

        $this->assertSame(
            'workspaces/orgs/10/shared/company-docs',
            $method->invoke($service, $organization, null, 'org_shared', 'company-docs')
        );
        $this->assertSame(
            'workspaces/orgs/10/teams/20/shared/team-docs',
            $method->invoke($service, $organization, $team, 'team_shared', 'team-docs')
        );
        $this->assertSame(
            'workspaces/orgs/10/teams/20/users/alice-space',
            $method->invoke($service, $organization, $team, 'user_team_private', 'alice-space')
        );
        $this->assertSame(
            'workspaces/orgs/10/teams/20/personal-shared/alice-shared',
            $method->invoke($service, $organization, $team, 'personal_shared', 'alice-shared')
        );
    }
}
