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
     * Get sources
     *
     * @authenticated
     *
     * @param SourceRequest $request
     * @return ApiResponse
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
