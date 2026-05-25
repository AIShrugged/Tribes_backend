<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\LlmPromptRequest;
use App\Http\Resources\API\v1\LlmPromptResource;
use App\Http\Responses\ApiResponse;
use App\Models\LlmPrompt;
use App\Models\Organization;
use App\Services\LlmPromptDefaultRegistry;
use App\Services\LlmPromptProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class OrganizationLlmPromptController extends Controller
{
    use AuthorizesRequests;

    public function index(LlmPromptRequest $request, Organization $organization): ApiResponse
    {
        $this->authorize('view', $organization);

        $query = $organization->llmPrompts()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = mb_strtolower((string) $request->input('search'));
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"]);
                });
            })
            ->when($request->filled('group'), function (Builder $query) use ($request): void {
                $group = trim((string) $request->input('group'));
                if ($group !== '') {
                    $query->where('slug', 'like', $group.'.%');
                }
            });

        $count = $query->count();
        $prompts = $query
            ->orderBy('slug')
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(LlmPromptResource::collection($prompts), $count);
    }

    public function show(LlmPromptRequest $request, Organization $organization, LlmPrompt $llmPrompt): ApiResponse
    {
        $this->authorize('view', $organization);
        $this->assertPromptBelongsToOrganization($llmPrompt, $organization);

        return ApiResponse::success(data: LlmPromptResource::make($llmPrompt));
    }

    public function update(LlmPromptRequest $request, Organization $organization, LlmPrompt $llmPrompt): ApiResponse
    {
        $this->authorizeManager($request, $organization);
        $this->assertPromptBelongsToOrganization($llmPrompt, $organization);

        $llmPrompt->update($request->getUpdateData());

        return ApiResponse::success(data: LlmPromptResource::make($llmPrompt->fresh()));
    }

    public function reset(
        LlmPromptRequest $request,
        Organization $organization,
        LlmPrompt $llmPrompt,
        LlmPromptDefaultRegistry $registry,
    ): ApiResponse {
        $this->authorizeManager($request, $organization);
        $this->assertPromptBelongsToOrganization($llmPrompt, $organization);

        $definition = collect($registry->all())->firstWhere('slug', $llmPrompt->slug);
        abort_unless($definition, 404);

        $llmPrompt->update([
            'name' => $definition['name'],
            'prompt' => $registry->render($definition['view']),
        ]);

        return ApiResponse::success(data: LlmPromptResource::make($llmPrompt->fresh()));
    }

    public function seed(
        LlmPromptRequest $request,
        Organization $organization,
        LlmPromptProvisioningService $provisioning,
    ): ApiResponse {
        $this->authorizeManager($request, $organization);

        $stats = $provisioning->provisionForOrganization(
            $organization,
            overwrite: $request->boolean('overwrite'),
        );

        return ApiResponse::success(data: $stats);
    }

    private function assertPromptBelongsToOrganization(LlmPrompt $prompt, Organization $organization): void
    {
        abort_unless((int) $prompt->organization_id === (int) $organization->id, 404);
    }

    private function authorizeManager(LlmPromptRequest $request, Organization $organization): void
    {
        abort_unless($request->user()?->isOrganizationManager($organization), 403);
    }
}
