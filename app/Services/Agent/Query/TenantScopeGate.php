<?php

namespace App\Services\Agent\Query;

use App\Models\User;
use App\Services\Agent\Catalog\CatalogService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single server-side gate that injects tenant scope into every structured
 * query. The acting user comes from the server/job context — NEVER from the LLM.
 *
 * Fail-closed: an entity without a declared Eloquent scope is refused outright;
 * there is no generic fallback that could leak cross-tenant data.
 */
class TenantScopeGate
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function apply(Builder $query, string $entity, User $actor): Builder
    {
        $scope = $this->catalog->scopeMethod($entity);

        if ($scope === null || ! method_exists($query->getModel(), 'scope'.ucfirst($scope))) {
            throw new StructuredQueryException([
                "Сущность '{$entity}' не имеет объявленного tenant-scope — запрос отклонён (fail-closed).",
            ]);
        }

        // Delegates to the model's existing local scope, e.g. Issue::scopeVisibleTo(Builder, User).
        return $query->{$scope}($actor);
    }
}
