<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\RebuildCriticalPathJob;
use App\Models\CriticalPathGraph;
use App\Services\CriticalPath\CriticalPathService;
use Illuminate\Http\Request;

class CriticalPathController extends Controller
{
    public function __construct(
        private readonly CriticalPathService $service,
    ) {}

    public function show(Request $request): ApiResponse
    {
        $teamId = $request->integer('team_id') ?: null;
        $orgId = $request->integer('organization_id') ?: null;

        $graph = $this->service->getGraphWithNodes($teamId, $orgId);

        if (! $graph) {
            return ApiResponse::error('Критический путь ещё не вычислен. Запустите rebuild.', status: 404);
        }

        return ApiResponse::success(data: $this->formatGraph($graph));
    }

    public function rebuild(Request $request): ApiResponse
    {
        $teamId = $request->integer('team_id') ?: null;
        $orgId = $request->integer('organization_id') ?: null;

        // Immediately mark as computing for the frontend to start polling
        $graph = CriticalPathGraph::updateOrCreate(
            ['team_id' => $teamId, 'organization_id' => $orgId],
            ['status' => 'computing', 'computed_at' => null],
        );

        RebuildCriticalPathJob::dispatch($teamId, $orgId);

        return ApiResponse::success(
            data: ['status' => 'computing', 'graph_id' => $graph->id],
            status: 202,
        );
    }

    private function formatGraph(CriticalPathGraph $graph): array
    {
        $nodes = $graph->nodes
            ->where('node_type', 'issue')
            ->map(fn ($node) => [
                'node_id' => $node->id,
                'node_type' => $node->node_type,
                'issue_id' => $node->issue_id,
                'issue_name' => $node->issue?->name,
                'status' => $node->issue?->status,
                'priority' => $node->issue?->priority,
                'due_date' => $node->issue?->due_date?->toDateString(),
                'duration_days' => $node->duration_days,
                'early_start' => $node->early_start,
                'early_finish' => $node->early_finish,
                'late_start' => $node->late_start,
                'late_finish' => $node->late_finish,
                'slack' => $node->slack,
                'is_critical' => $node->is_critical,
            ])
            ->sortBy('early_start')
            ->values()
            ->all();

        $issueNodeIds = array_flip(array_column($nodes, 'node_id'));

        $edges = $graph->edges
            ->filter(fn ($edge) =>
                isset($issueNodeIds[$edge->from_node_id]) &&
                isset($issueNodeIds[$edge->to_node_id])
            )
            ->map(fn ($edge) => [
                'from_node_id' => $edge->from_node_id,
                'to_node_id' => $edge->to_node_id,
                'edge_type' => $edge->edge_type,
            ])
            ->values()
            ->all();

        $projectDuration = collect($nodes)->max('early_finish') ?? 0;

        return [
            'graph_id' => $graph->id,
            'team_id' => $graph->team_id,
            'organization_id' => $graph->organization_id,
            'status' => $graph->status,
            'computed_at' => $graph->computed_at?->toIso8601String(),
            'project_duration_days' => $projectDuration,
            'nodes' => $graph->status === 'ready' ? $nodes : [],
            'edges' => $graph->status === 'ready' ? $edges : [],
        ];
    }
}
