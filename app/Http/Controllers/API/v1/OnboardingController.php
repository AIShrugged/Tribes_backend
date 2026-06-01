<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AcceptOrganizationStructureRequest;
use App\Http\Requests\API\v1\GenerateOrganizationStructureRequest;
use App\Http\Responses\ApiResponse;
use App\Jobs\GenerateOrganizationStructureJob;
use App\Jobs\IndexOrganizationAttachmentJob;
use App\Jobs\IndexOrganizationLinkJob;
use App\Models\IssueAttachment;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\OrganizationIssueType;
use App\Models\OrganizationLink;
use App\Models\OrganizationOnboardingDraft;
use App\Models\User;
use App\Support\IssueDescriptionFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function generate(
        GenerateOrganizationStructureRequest $request,
        Organization $organization,
    ): ApiResponse {
        Gate::authorize('onboard', $organization);

        OrganizationOnboardingDraft::where('organization_id', $organization->id)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => 'Replaced by new generation']);

        $draft = OrganizationOnboardingDraft::create([
            'organization_id' => $organization->id,
            'user_id'         => $request->user()->id,
            'status'          => 'pending',
            'payload'         => $request->only(['description', 'upload_token', 'links', 'template']),
        ]);

        GenerateOrganizationStructureJob::dispatch($draft->id);

        return ApiResponse::success('Success', ['draft_id' => $draft->id, 'status' => 'pending']);
    }

    public function latestDraft(Request $request, Organization $organization): ApiResponse
    {
        Gate::authorize('view', $organization);

        $draft = OrganizationOnboardingDraft::where('organization_id', $organization->id)
            ->latest()
            ->firstOrFail();

        return ApiResponse::success('Success', [
            'id'     => $draft->id,
            'status' => $draft->status,
            'result' => $draft->result,
            'error'  => $draft->error,
        ]);
    }

    public function accept(
        AcceptOrganizationStructureRequest $request,
        Organization $organization,
    ): ApiResponse {
        Gate::authorize('onboard', $organization);

        $epicType = OrganizationIssueType::where('base_type', 'epic')
            ->where(fn($q) => $q->where('organization_id', $organization->id)->orWhereNull('organization_id'))
            ->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$organization->id])
            ->where('is_active', true)
            ->first();

        if (!$epicType) {
            return ApiResponse::error('Тип эпика не настроен в системе', null, 422);
        }

        $userId   = $request->user()->id;
        $orgData  = $request->input('organization');
        $goals    = $request->input('goals');
        $team     = $this->normalizeOnboardingTeam($request->input('team', []));
        $template = $request->input('template');

        $draft = OrganizationOnboardingDraft::where('organization_id', $organization->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $draftLinks       = array_filter((array) ($draft?->payload['links'] ?? []));
        $draftUploadToken = $draft?->payload['upload_token'] ?? null;

        $draftAttachments = $draftUploadToken
            ? IssueAttachment::whereNull('issue_id')
                ->where('upload_token', $draftUploadToken)
                ->where('organization_id', $organization->id)
                ->get()
            : collect();

        DB::transaction(function () use ($organization, $orgData, $goals, $team, $template, $epicType, $userId, $draftLinks, $draftAttachments): void {
            $team = $this->ensureTeamUsers($organization, $team);

            $organization->update([
                'name'         => $orgData['name'],
                'context'      => $orgData['description'],
                'team_map'     => $team ?: null,
                'template'     => $template,
                'onboarded_at' => now(),
            ]);

            foreach ($draftLinks as $url) {
                $link = OrganizationLink::firstOrCreate([
                    'organization_id' => $organization->id,
                    'url'             => $url,
                ]);
                IndexOrganizationLinkJob::dispatch($link->id);
            }

            foreach ($draftAttachments as $attachment) {
                IndexOrganizationAttachmentJob::dispatch($attachment->id);
            }

            foreach ($goals as $goal) {
                $epic = Issue::create([
                    'user_id'         => $userId,
                    'organization_id' => $organization->id,
                    'team_id'         => null,
                    'issue_type_id'   => $epicType->id,
                    'type'            => Issue::TYPE_EPIC,
                    'name'            => $goal['title'],
                    'description'     => $goal['description'] ?? null,
                    'status'          => 'open',
                ]);

                foreach ($goal['tasks'] ?? [] as $task) {
                    Issue::create([
                        'user_id'         => $userId,
                        'organization_id' => $organization->id,
                        'team_id'         => null,
                        'epic_id'         => $epic->id,
                        'name'            => $task['title'],
                        'description'     => IssueDescriptionFormatter::onboardingTask(
                            $task['description'] ?? null,
                            $goal['title'],
                            $goal['description'] ?? null,
                        ),
                        'type'            => $task['type'] ?? Issue::TYPE_DEVELOPMENT,
                        'priority'        => $task['priority'] ?? Issue::PRIORITY_NORMAL,
                        'status'          => 'open',
                    ]);
                }
            }
        });

        return ApiResponse::success('Success', $organization->refresh()->only(['id', 'name', 'slug', 'context', 'template', 'onboarded_at']));
    }

    private function normalizeOnboardingTeam(array $team): array
    {
        return array_values(array_filter(array_map(function (array $member): array {
            $name = trim((string) ($member['name'] ?? ''));
            $email = $this->normalizeEmail($member['email'] ?? null)
                ?? $this->fallbackEmailForName($name);

            return array_merge($member, [
                'name'  => $name,
                'email' => $email,
                'role'  => in_array($member['role'] ?? '', ['manager', 'employee'], true)
                    ? $member['role']
                    : 'employee',
            ]);
        }, $team), fn(array $member): bool => $member['name'] !== ''));
    }

    private function ensureTeamUsers(Organization $organization, array $team): array
    {
        return array_map(function (array $member) use ($organization): array {
            $email = (string) ($member['email'] ?? '');

            if ($email === '') {
                return $member;
            }

            $user = User::firstOrNew(['email' => $email]);

            if (!$user->exists) {
                $user->forceFill([
                    'name'              => $member['name'],
                    'password'          => Str::random(32),
                    'email_verified_at' => now(),
                ])->save();
            }

            $organization->users()->syncWithoutDetaching([
                $user->id => ['role' => $member['role'] ?? 'employee'],
            ]);

            return array_merge($member, [
                'already_in_system' => true,
                'system_user_id'    => $user->id,
            ]);
        }, $team);
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $email = strtolower(trim((string) $value));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    private function fallbackEmailForName(string $name): string
    {
        $username = Str::slug($name, '.');

        if ($username === '') {
            $username = 'user';
        }

        return "{$username}@shrugged.ai";
    }
}
