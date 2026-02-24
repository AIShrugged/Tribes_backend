<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\SourceRequest;
use App\Http\Resources\API\v1\SourceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SourceController extends Controller
{
    /**
     * List sources
     *
     * Returns a paginated list of calendar sources (Google Calendar integrations) owned by the authenticated user.
     * The total count is returned in the `Items-Count` response header.
     *
     * @authenticated
     *
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 1,
     *       "external_id": "alice@gmail.com",
     *       "identity": "alice@gmail.com",
     *       "type": "google_calendar",
     *       "auth_type": "oauth",
     *       "is_connected": true
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(SourceRequest $request): ApiResponse
    {
        $sources = Source::owned(Auth::id());

        $count = $sources->count();

        $sources = $sources->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(SourceResource::collection($sources), $count);
    }
}
