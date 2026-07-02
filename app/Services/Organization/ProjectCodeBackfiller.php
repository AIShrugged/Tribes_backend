<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;

/**
 * Backfills project codes for organizations and per-organization numbers/codes for
 * existing issues. Runs once from the schema migration, but is written to be safely
 * re-runnable: it only touches organizations without a code and issues without a number,
 * continuing each organization's sequence from its current last_issue_number.
 */
class ProjectCodeBackfiller
{
    public function __construct(
        private readonly ProjectCodeGenerator $generator,
    ) {}

    public function run(): void
    {
        $codeByOrg = $this->assignOrganizationCodes();
        $this->numberIssues($codeByOrg);
    }

    /**
     * @return array<int, string> organization id => code
     */
    private function assignOrganizationCodes(): array
    {
        $codeByOrg = [];
        $reserved = [];

        foreach (DB::table('organizations')->whereNotNull('code')->get(['id', 'code']) as $organization) {
            $codeByOrg[$organization->id] = $organization->code;
            $reserved[] = $organization->code;
        }

        foreach (DB::table('organizations')->whereNull('code')->orderBy('id')->get(['id', 'name']) as $organization) {
            $code = $this->generator->generate((string) $organization->name, $reserved);
            $reserved[] = $code;
            $codeByOrg[$organization->id] = $code;

            DB::table('organizations')->where('id', $organization->id)->update(['code' => $code]);
        }

        return $codeByOrg;
    }

    /**
     * @param  array<int, string>  $codeByOrg
     */
    private function numberIssues(array $codeByOrg): void
    {
        // Effective organization = issues.organization_id, else the issue's team organization.
        $rows = DB::table('issues')
            ->leftJoin('teams', 'issues.team_id', '=', 'teams.id')
            ->whereNull('issues.number')
            ->orderBy('issues.id')
            ->get([
                'issues.id as id',
                DB::raw('COALESCE(issues.organization_id, teams.organization_id) as eff_org'),
            ]);

        $counters = [];
        foreach ($codeByOrg as $organizationId => $code) {
            $counters[$organizationId] = (int) DB::table('organizations')
                ->where('id', $organizationId)
                ->value('last_issue_number');
        }

        foreach ($rows as $row) {
            $organizationId = $row->eff_org;
            if (! $organizationId || ! isset($codeByOrg[$organizationId])) {
                continue;
            }

            $number = ($counters[$organizationId] ?? 0) + 1;
            $counters[$organizationId] = $number;

            DB::table('issues')->where('id', $row->id)->update([
                'number' => $number,
                'code' => $codeByOrg[$organizationId].'-'.$number,
            ]);
        }

        foreach ($counters as $organizationId => $number) {
            DB::table('organizations')->where('id', $organizationId)->update(['last_issue_number' => $number]);
        }
    }
}
