<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MethodologyRequest;
use App\Http\Resources\API\v1\MethodologyResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\GenerateMethodologySchemeJob;
use App\Models\Methodology;
use App\Models\UserMethodology;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MethodologyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(MethodologyRequest $request): ApiResponse
    {
        $methodologies = Methodology::owned(Auth::id());

        $count = $methodologies->count();

        $methodologies = $methodologies->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(MethodologyResource::collection($methodologies), $count);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MethodologyRequest $request): ApiResponse
    {
        try {
            DB::beginTransaction();

            $methodology = Auth::user()
                ->activeMethodology()
                ->create($request->getStoreData());

            UserMethodology::updateOrCreate(
                ['user_id' => Auth::id()],
                ['methodology_id' => $methodology->id]
            );

            GenerateMethodologySchemeJob::dispatch($methodology);

            DB::commit();

            return ApiResponse::success(
                data: MethodologyResource::make($methodology)
            );
        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(MethodologyRequest $request): ApiResponse
    {
        $methodology = Methodology::owned(Auth::id())
            ->findOrFail($request->getMethodologyId());

        return ApiResponse::success(
            data: MethodologyResource::make($methodology)
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(MethodologyRequest $request): ApiResponse
    {
        $methodology = Methodology::owned(Auth::id())
            ->findOrFail($request->getMethodologyId());

        if ($methodology->followups()->exists()) {
            throw new AppException('The methodology is used by one or more follow-ups.', 'METHODOLOGY_LOCK_UPDATE');
        }

        if ($methodology->isDefault()) {
            throw new AppException('Unable to update default methodology.', 'METHODOLOGY_LOCK_DEFAULT');
        }

        $methodology = $methodology->update($request->getUpdateData());

        return ApiResponse::success(
            data: MethodologyResource::make($methodology)
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MethodologyRequest $request): ApiResponse
    {
        $methodology = Methodology::owned(Auth::id())
            ->findOrFail($request->getMethodologyId());

        if ($methodology->followups()->exists()) {
            throw new AppException('The methodology is used by one or more follow-ups.', 'METHODOLOGY_LOCK_UPDATE');
        }

        if ($methodology->isDefault()) {
            throw new AppException('Unable to delete default methodology.', 'METHODOLOGY_LOCK_DEFAULT');
        }

        $methodology->delete();

        return ApiResponse::success();
    }

    public function active(): ApiResponse
    {
        $methodology = Auth::user()->activeMethodologyOrDefault();

        return ApiResponse::success(
            data: MethodologyResource::make($methodology)
        );
    }
}
