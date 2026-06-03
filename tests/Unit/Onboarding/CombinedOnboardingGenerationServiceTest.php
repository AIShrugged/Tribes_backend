<?php

namespace Tests\Unit\Onboarding;

use App\Models\Organization;
use App\Services\Onboarding\CombinedOnboardingGenerationService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class CombinedOnboardingGenerationServiceTest extends TestCase
{
    #[Test]
    public function team_members_not_present_in_source_evidence_are_removed(): void
    {
        $service = app(CombinedOnboardingGenerationService::class);
        $org = new Organization(['name' => 'Tribes']);

        $parsed = $this->invokePrivate($service, 'parseResponse', [
            $org,
            json_encode([
                'organization' => [
                    'name' => 'Tribes',
                    'description' => 'Team context',
                ],
                'goals' => [],
                'team' => [
                    [
                        'name' => 'Konstantin Kupreychenko',
                        'email' => null,
                        'role' => 'employee',
                        'found_in' => ['transcripts'],
                    ],
                    [
                        'name' => 'Kot Stepka',
                        'email' => null,
                        'role' => 'employee',
                        'found_in' => ['transcripts'],
                    ],
                    [
                        'name' => 'Марина',
                        'email' => 'marina@shrugged.ai',
                        'role' => 'employee',
                        'found_in' => ['transcripts'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $evidence = 'Участники,"Boris; Fedor Zhernovoy; Ivan Zakharov; Konstantin Kupreychenko; Slava"';

        $filtered = $this->invokePrivate($service, 'filterTeamByEvidence', [
            $parsed,
            $evidence,
        ]);

        $withEmails = $this->invokePrivate($service, 'normalizeTeamEmailsByEvidence', [
            $filtered,
            $evidence,
        ]);

        $this->assertSame(['Konstantin Kupreychenko'], array_column($withEmails['team'], 'name'));
        $this->assertSame('konstantin.kupreychenko@shrugged.ai', $withEmails['team'][0]['email']);
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }
}
