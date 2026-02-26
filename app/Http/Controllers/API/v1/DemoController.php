<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\DemoSeedRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Demo\DemoDataService;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    public function __construct(private readonly DemoDataService $demoService)
    {
    }

    /**
     * Start demo data generation
     *
     * @group Demo
     *
     * Initiates background generation of demo data for the authenticated user.
     * The current user becomes manager of the generated demo organization.
     * Returns 409 if generation is already in progress or already exists.
     *
     * @bodyParam teams_count int Number of teams to generate. Min: 1, Max: 3. Defaults to 1. Example: 1
     * @bodyParam employees_per_team int Number of employees per team. Min: 3, Max: 10. Defaults to 7. Example: 5
     * @bodyParam meetings_per_team int Number of meetings per team. Min: 1, Max: 6. Defaults to 3. Example: 2
     *
     * @response 202 scenario="Accepted" {"success":true,"data":{"status":"pending","progress_percent":null,"current_step_label":null},"message":"Demo generation started","status":202,"meta":[]}
     * @response 409 scenario="Already exists" {"success":false,"data":null,"message":"Demo generation already in progress.","status":409,"meta":{"error_code":"DEMO_ALREADY_IN_PROGRESS"}}
     */
    public function seed(DemoSeedRequest $request): ApiResponse
    {
        $user       = $request->user();
        $generation = $this->demoService->getForUser($user);

        if ($generation && $generation->isInProgress()) {
            throw new AppException('Demo generation already in progress.', 'DEMO_ALREADY_IN_PROGRESS', 409);
        }

        if ($generation) {
            // Previous generation exists but is ready/failed — destroy it before starting new
            $this->demoService->destroy($user);
        }

        $generation = $this->demoService->initiate($user, $request->getParams());

        return ApiResponse::success(
            data: [
                'status'             => $generation->status,
                'progress_percent'   => $generation->progress_percent,
                'current_step_label' => $generation->current_step_label,
            ],
            status: 202,
            message: 'Demo generation started'
        );
    }

    /**
     * Get demo generation status
     *
     * @group Demo
     *
     * Returns the current status of demo data generation for the authenticated user.
     * Poll this endpoint every 3-5 seconds until status is "ready" or "failed".
     *
     * @response 200 scenario="Generating" {"success":true,"data":{"status":"generating","progress_percent":45,"current_step_label":"Генерация транскрипций встреч (2/3)...","error":null,"completed_at":null},"message":"Success","status":200,"meta":[]}
     * @response 200 scenario="Ready" {"success":true,"data":{"status":"ready","progress_percent":100,"current_step_label":"Готово!","error":null,"completed_at":"2026-02-26T12:00:00+03:00"},"message":"Success","status":200,"meta":[]}
     * @response 200 scenario="Failed" {"success":true,"data":{"status":"failed","progress_percent":20,"current_step_label":null,"error":"Generation error description","completed_at":null},"message":"Success","status":200,"meta":[]}
     * @response 404 scenario="No generation" {"success":false,"data":null,"message":"No demo generation found.","status":404,"meta":[]}
     */
    public function status(Request $request): ApiResponse
    {
        $generation = $this->demoService->getForUser($request->user());

        if (!$generation) {
            return ApiResponse::notFound('No demo generation found.');
        }

        return ApiResponse::success(data: [
            'status'             => $generation->status,
            'progress_percent'   => $generation->progress_percent,
            'current_step_label' => $generation->current_step_label,
            'error'              => $generation->error,
            'completed_at'       => $generation->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * Delete demo data
     *
     * @group Demo
     *
     * Permanently deletes all demo data generated for the authenticated user:
     * organization, teams, demo users, meetings, transcripts, insights, followups.
     *
     * @response 200 scenario="Deleted" {"success":true,"data":null,"message":"Demo data deleted","status":200,"meta":[]}
     * @response 404 scenario="Not found" {"success":false,"data":null,"message":"No demo generation found.","status":404,"meta":[]}
     */
    public function destroy(Request $request): ApiResponse
    {
        $generation = $this->demoService->getForUser($request->user());

        if (!$generation) {
            return ApiResponse::notFound('No demo generation found.');
        }

        $this->demoService->destroy($request->user());

        return ApiResponse::success(message: 'Demo data deleted');
    }
}
