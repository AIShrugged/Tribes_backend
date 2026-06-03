<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\PersonRequest;
use App\Http\Resources\API\v1\PersonResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PersonController extends Controller
{
    public function index(PersonRequest $request): ApiResponse
    {
        $user = $request->user();
        $organizationIds = $user->organizations()->pluck('organizations.id');
        $teamIds = $user->teams()->pluck('teams.id');
        $organizationId = $request->getOrganizationId();

        $query = User::query()
            ->where(function (Builder $builder) use ($organizationIds, $teamIds, $user): void {
                $builder->whereKey($user->id);

                if ($organizationIds->isNotEmpty()) {
                    $builder->orWhereHas('organizations', function (Builder $relation) use ($organizationIds): void {
                        $relation->whereIn('organizations.id', $organizationIds);
                    });
                }

                if ($teamIds->isNotEmpty()) {
                    $builder->orWhereHas('teams', function (Builder $relation) use ($teamIds): void {
                        $relation->whereIn('teams.id', $teamIds);
                    });
                }
            })
            ->orderBy('name')
            ->orderBy('id');

        if ($organizationId !== null) {
            abort_unless(
                $organizationIds->contains($organizationId),
                403,
            );

            $query->whereHas('organizations', function (Builder $relation) use ($organizationId): void {
                $relation->whereKey($organizationId);
            });
        }

        $count = (clone $query)->count();
        $persons = $query->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(PersonResource::collection($persons), $count);
    }
}
