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

class FollowupExportController extends Controller
{
    public function __construct(
        private readonly FollowupExporterFactory $exporterFactory,
        private readonly FollowupAccessService $accessService
    ) {
    }

    public function export(FollowupExportRequest $request): HttpResponse
    {
        $followup = Followup::with('calendarEvent.source')->findOrFail($request->getFollowupId());

        $ownerId = $followup->calendarEvent->source->user_id;
        $accessibleUserIds = $this->accessService->getAccessibleUserIds(Auth::user());

        if (!in_array($ownerId, $accessibleUserIds)) {
            abort(403, 'Access denied');
        }

        $exporter = $this->exporterFactory->make($request->getExportFormat());

        $content = $exporter->export($followup);
        $fileName = $exporter->getFileName($followup);
        $contentType = $exporter->getContentType();

        return new Response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
