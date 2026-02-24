<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\FollowupExportRequest;
use App\Models\Followup;
use App\Services\Chat\Export\FollowupExporterFactory;
use App\Services\Chat\FollowupAccessService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * @group Followups
 */
class FollowupExportController extends Controller
{
    public function __construct(
        private readonly FollowupExporterFactory $exporterFactory,
        private readonly FollowupAccessService $accessService
    ) {
    }

    /**
     * Export followup
     *
     * Downloads an AI-generated followup as a file in the specified format.
     * The response is a binary file attachment, not JSON.
     *
     * @subgroup Export
     * @authenticated
     *
     * @urlParam followup integer required The Followup ID. Example: 1
     *
     * @response 200 scenario="PDF file" <<binary>>
     * @response 403 scenario="Forbidden" {"message": "Access denied"}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Followup] 1"}
     * @response 422 scenario="Validation error — invalid format" {
     *   "message": "The selected format is invalid.",
     *   "errors": {"format": ["The selected format is invalid."]}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function export(FollowupExportRequest $request): HttpResponse
    {
        $followup = Followup::with('calendarEvent.source')->findOrFail($request->getFollowupId());

        $ownerId = $followup->calendarEvent->source->user_id;
        $accessibleUserIds = $this->accessService->getAccessibleUserIds(Auth::user());

        if (!in_array($ownerId, $accessibleUserIds)) {
            abort(403, 'Access denied');
        }

        $exporter = $this->exporterFactory->make($request->input('format'));

        $content = $exporter->export($followup);
        $fileName = $exporter->getFileName($followup);
        $contentType = $exporter->getContentType();

        return new Response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
