<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Defense against drift on the org-user membership invariant.
 *
 * Any new attach/detach to `organization_user` must go through
 * `OrganizationMembershipService`. Direct calls like
 * `$organization->users()->attach()` or `->syncWithoutDetaching()` bypass
 * the default-team invariant and silently break team-scoped pipelines.
 *
 * This test grep-scans app/ for offenders. Legitimate exceptions can be
 * whitelisted by adding `// @membership-allow direct attach` on the same
 * line or in the same file.
 *
 * Long term (>15 attach sites total), escalate this check to a PHPStan rule.
 * See plan docs/plans/2026-05-28-feat-default-team-per-organization-plan.md
 * (Phase 5d, Future Considerations).
 */
class DirectOrgAttachIsForbiddenTest extends TestCase
{
    private const ALLOWED_FILES = [
        // The service is the canonical attach path.
        'OrganizationMembershipService.php',
        // TeamController writes to team_user, NOT organization_user — different
        // pivot, separate invariant.
        'TeamController.php',
        // Methodology::syncTeams writes to teams.methodology_id, not the pivot.
        'Methodology.php',
    ];

    #[Test]
    public function direct_org_user_attach_only_appears_in_allowed_files(): void
    {
        $offenders = [];
        $appPath = base_path('app');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = file_get_contents($path);

            // Skip if file is whitelisted.
            $basename = basename($path);
            if (in_array($basename, self::ALLOWED_FILES, true)) {
                continue;
            }

            // Skip file if it carries a global allowance marker.
            if (str_contains($contents, '@membership-allow')) {
                continue;
            }

            // Look for direct pivot writes on $org->users() or
            // $organization->users(). These are the typical bypass shapes.
            $pattern = '/\$\w*(?:organization|org)\w*->users\(\)->(attach|syncWithoutDetaching|sync|detach)\b/i';

            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = $path.': '.$match[0];
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            'Direct organization_user pivot writes found outside OrganizationMembershipService. '
            .'Route the attach/detach through the service, or add `// @membership-allow direct attach` '
            ."on the same line/file if the bypass is intentional.\n\n"
            ."Offenders:\n - ".implode("\n - ", $offenders)
        );
    }
}
