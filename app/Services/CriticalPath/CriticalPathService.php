<?php

namespace App\Services\CriticalPath;

use App\Jobs\NotifyCriticalPathJob;
use App\Jobs\RebuildCriticalPathJob;
use App\Models\CriticalPathEdge;
use App\Models\CriticalPathGraph;
use App\Models\CriticalPathNode;
use App\Models\Issue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CriticalPathService
{
    public function __construct(
        private readonly CriticalPathLlmAnalyzer $llmAnalyzer,
        private readonly CriticalPathComputer $computer,
    ) {}

    public function rebuild(?int $teamId, ?int $organizationId): CriticalPathGraph
    {
        $graph = $this->upsertGraph($teamId, $organizationId, 'computing');

        try {
            $issues = $this->loadScopedIssues($teamId, $organizationId);

            $explicitPairs = $this->collectExplicitBlockerPairs($issues);

            $llmResult = $this->llmAnalyzer->analyze($issues, $explicitPairs);

            $this->persistGraphFromLlmResult($graph, $issues, $explicitPairs, $llmResult);

            $this->computer->compute($graph->fresh());
        } catch (CriticalPathCycleException $e) {
            Log::warning('CriticalPathService: cycle detected', [
                'graph_id' => $graph->id,
                'team_id' => $teamId,
                'org_id' => $organizationId,
            ]);
            $graph->update(['status' => 'failed']);
        } catch (\Throwable $e) {
            Log::error('CriticalPathService: rebuild failed', [
                'graph_id' => $graph->id,
                'error' => $e->getMessage(),
            ]);
            $graph->update(['status' => 'failed']);

            throw $e;
        }

        return $graph->fresh();
    }

    public function getGraphForScope(?int $teamId, ?int $organizationId): ?CriticalPathGraph
    {
        return CriticalPathGraph::where('team_id', $teamId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function getGraphWithNodes(?int $teamId, ?int $organizationId): ?CriticalPathGraph
    {
        return CriticalPathGraph::where('team_id', $teamId)
            ->where('organization_id', $organizationId)
            ->with([
                'nodes' => fn ($q) => $q->with('issue:id,name,status,priority,due_date,assignee_id')
                    ->where('node_type', 'issue'),
                'edges',
            ])
            ->first();
    }

    public function incrementalRebuild(int $organizationId, array $pendingIssueIds): void
    {
        $graph = CriticalPathGraph::where('organization_id', $organizationId)
            ->whereNull('team_id')
            ->where('status', 'ready')
            ->first();

        if (! $graph) {
            RebuildCriticalPathJob::dispatch(null, $organizationId);

            return;
        }

        $graph->update(['status' => 'computing']);

        try {
            $pendingIssues = Issue::whereIn('id', $pendingIssueIds)
                ->whereIn('status', ['open', 'in_progress'])
                ->with(['blockedBy:id', 'blocking:id'])
                ->get();

            if ($pendingIssues->isEmpty()) {
                $graph->update(['status' => 'ready']);

                return;
            }

            $explicitPairs = $this->collectExplicitBlockerPairs($pendingIssues);
            $llmResult = $this->llmAnalyzer->analyze($pendingIssues, $explicitPairs);

            DB::transaction(function () use ($graph, $pendingIssues, $llmResult): void {
                $allNodeMap = CriticalPathNode::where('graph_id', $graph->id)
                    ->where('node_type', 'issue')
                    ->whereNotNull('issue_id')
                    ->pluck('id', 'issue_id');

                $existingForPending = $allNodeMap->only($pendingIssues->pluck('id')->all());

                foreach ($pendingIssues as $issue) {
                    $duration = $llmResult['durations'][$issue->id] ?? 1.0;

                    if ($existingForPending->has($issue->id)) {
                        CriticalPathNode::where('id', $existingForPending[$issue->id])
                            ->update(['duration_days' => $duration]);
                    } else {
                        $node = CriticalPathNode::create([
                            'graph_id' => $graph->id,
                            'issue_id' => $issue->id,
                            'node_type' => 'issue',
                            'duration_days' => $duration,
                        ]);
                        $allNodeMap[$issue->id] = $node->id;

                        foreach ($issue->blockedBy as $blocker) {
                            if ($allNodeMap->has($blocker->id)) {
                                CriticalPathEdge::firstOrCreate([
                                    'graph_id' => $graph->id,
                                    'from_node_id' => $allNodeMap[$blocker->id],
                                    'to_node_id' => $node->id,
                                ], ['edge_type' => 'explicit']);
                            }
                        }

                        foreach ($issue->blocking as $blocked) {
                            if ($allNodeMap->has($blocked->id)) {
                                CriticalPathEdge::firstOrCreate([
                                    'graph_id' => $graph->id,
                                    'from_node_id' => $node->id,
                                    'to_node_id' => $allNodeMap[$blocked->id],
                                ], ['edge_type' => 'explicit']);
                            }
                        }
                    }
                }

                foreach ($llmResult['implicit_edges'] as $edge) {
                    $fromNodeId = $allNodeMap[$edge['from']] ?? null;
                    $toNodeId = $allNodeMap[$edge['to']] ?? null;
                    if ($fromNodeId && $toNodeId) {
                        CriticalPathEdge::firstOrCreate([
                            'graph_id' => $graph->id,
                            'from_node_id' => $fromNodeId,
                            'to_node_id' => $toNodeId,
                        ], ['edge_type' => 'implicit']);
                    }
                }

                $this->rebuildSentinelEdges($graph);
            });

            $this->computer->compute($graph->fresh());
        } catch (\Throwable $e) {
            Log::warning('CriticalPathService: incremental rebuild failed', [
                'org_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            $graph->update(['status' => 'failed']);

            return;
        }

        // Notify off the scheduler tick: a dedicated tries=1 job owns the Telegram send,
        // and notifyTeam debounces if the critical path did not actually change.
        $fresh = $graph->fresh();
        if ($fresh && $fresh->isReady()) {
            NotifyCriticalPathJob::dispatch($fresh->id);
        }
    }

    private function rebuildSentinelEdges(CriticalPathGraph $graph): void
    {
        CriticalPathEdge::where('graph_id', $graph->id)
            ->where('edge_type', 'sentinel')
            ->delete();

        $startNode = CriticalPathNode::where('graph_id', $graph->id)->where('node_type', 'start')->first();
        $endNode = CriticalPathNode::where('graph_id', $graph->id)->where('node_type', 'end')->first();

        if (! $startNode || ! $endNode) {
            return;
        }

        $allNodeIds = CriticalPathNode::where('graph_id', $graph->id)
            ->where('node_type', 'issue')
            ->pluck('id')
            ->all();

        if (empty($allNodeIds)) {
            CriticalPathEdge::create([
                'graph_id' => $graph->id,
                'from_node_id' => $startNode->id,
                'to_node_id' => $endNode->id,
                'edge_type' => 'sentinel',
            ]);

            return;
        }

        $nonSentinelEdges = CriticalPathEdge::where('graph_id', $graph->id)
            ->where('edge_type', '!=', 'sentinel')
            ->get(['from_node_id', 'to_node_id']);

        $hasIncoming = array_fill_keys($allNodeIds, false);
        $hasOutgoing = array_fill_keys($allNodeIds, false);

        foreach ($nonSentinelEdges as $edge) {
            if (isset($hasIncoming[$edge->to_node_id])) {
                $hasIncoming[$edge->to_node_id] = true;
            }
            if (isset($hasOutgoing[$edge->from_node_id])) {
                $hasOutgoing[$edge->from_node_id] = true;
            }
        }

        foreach ($allNodeIds as $nodeId) {
            if (! $hasIncoming[$nodeId]) {
                CriticalPathEdge::create([
                    'graph_id' => $graph->id,
                    'from_node_id' => $startNode->id,
                    'to_node_id' => $nodeId,
                    'edge_type' => 'sentinel',
                ]);
            }

            if (! $hasOutgoing[$nodeId]) {
                CriticalPathEdge::create([
                    'graph_id' => $graph->id,
                    'from_node_id' => $nodeId,
                    'to_node_id' => $endNode->id,
                    'edge_type' => 'sentinel',
                ]);
            }
        }
    }

    private function loadScopedIssues(?int $teamId, ?int $organizationId): Collection
    {
        return Issue::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when(! $teamId && $organizationId, function ($q) use ($organizationId): void {
                // Include issues directly linked to org AND issues in teams that belong to this org
                $q->where(function ($inner) use ($organizationId): void {
                    $inner->where('organization_id', $organizationId)
                        ->orWhereHas('team', fn ($t) => $t->where('organization_id', $organizationId));
                });
            })
            ->whereIn('status', ['open', 'in_progress'])
            ->with(['blockedBy:id', 'blocking:id', 'assignee:id,name'])
            ->get();
    }

    private function collectExplicitBlockerPairs(Collection $issues): array
    {
        $issueIds = $issues->pluck('id')->flip();
        $pairs = [];

        foreach ($issues as $issue) {
            foreach ($issue->blockedBy as $blocker) {
                if ($issueIds->has($blocker->id)) {
                    $pairs[] = ['from' => $blocker->id, 'to' => $issue->id];
                }
            }
        }

        // Epic dependency: each child issue must complete before its parent epic
        foreach ($issues as $issue) {
            if ($issue->epic_id !== null && $issueIds->has($issue->epic_id)) {
                $pairs[] = ['from' => $issue->id, 'to' => $issue->epic_id];
            }
        }

        return $pairs;
    }

    private function upsertGraph(?int $teamId, ?int $organizationId, string $status = 'pending'): CriticalPathGraph
    {
        return CriticalPathGraph::updateOrCreate(
            ['team_id' => $teamId, 'organization_id' => $organizationId],
            ['status' => $status, 'computed_at' => null],
        );
    }

    private function persistGraphFromLlmResult(
        CriticalPathGraph $graph,
        Collection $issues,
        array $explicitPairs,
        array $llmResult,
    ): void {
        DB::transaction(function () use ($graph, $issues, $explicitPairs, $llmResult): void {
            $graph->nodes()->delete();

            $startNode = CriticalPathNode::create([
                'graph_id' => $graph->id,
                'issue_id' => null,
                'node_type' => 'start',
                'duration_days' => 0.0,
            ]);

            $endNode = CriticalPathNode::create([
                'graph_id' => $graph->id,
                'issue_id' => null,
                'node_type' => 'end',
                'duration_days' => 0.0,
            ]);

            $nodeByIssueId = [];
            foreach ($issues as $issue) {
                $duration = $llmResult['durations'][$issue->id] ?? 1.0;

                $node = CriticalPathNode::create([
                    'graph_id' => $graph->id,
                    'issue_id' => $issue->id,
                    'node_type' => 'issue',
                    'duration_days' => $duration,
                ]);

                $nodeByIssueId[$issue->id] = $node->id;
            }

            // Combine explicit + implicit edges, deduplicated by (from, to)
            $explicitSet = [];
            $edgesToCreate = [];
            foreach ($explicitPairs as $pair) {
                $key = $pair['from'].'_'.$pair['to'];
                if (isset($nodeByIssueId[$pair['from']], $nodeByIssueId[$pair['to']])) {
                    $explicitSet[$key] = true;
                    $edgesToCreate[$key] = [
                        'graph_id' => $graph->id,
                        'from_node_id' => $nodeByIssueId[$pair['from']],
                        'to_node_id' => $nodeByIssueId[$pair['to']],
                        'edge_type' => 'explicit',
                    ];
                }
            }

            foreach ($llmResult['implicit_edges'] as $edge) {
                $key = $edge['from'].'_'.$edge['to'];
                if (
                    ! isset($explicitSet[$key])
                    && isset($nodeByIssueId[$edge['from']], $nodeByIssueId[$edge['to']])
                ) {
                    $edgesToCreate[$key] = [
                        'graph_id' => $graph->id,
                        'from_node_id' => $nodeByIssueId[$edge['from']],
                        'to_node_id' => $nodeByIssueId[$edge['to']],
                        'edge_type' => 'implicit',
                    ];
                }
            }

            foreach ($edgesToCreate as $edge) {
                CriticalPathEdge::create($edge);
            }

            // Sentinel edges: START → nodes with no predecessors, nodes with no successors → END
            $hasIncoming = array_fill_keys(array_values($nodeByIssueId), false);
            $hasOutgoing = array_fill_keys(array_values($nodeByIssueId), false);

            foreach ($edgesToCreate as $edge) {
                $hasIncoming[$edge['to_node_id']] = true;
                $hasOutgoing[$edge['from_node_id']] = true;
            }

            foreach ($nodeByIssueId as $nodeId) {
                if (! $hasIncoming[$nodeId]) {
                    CriticalPathEdge::create([
                        'graph_id' => $graph->id,
                        'from_node_id' => $startNode->id,
                        'to_node_id' => $nodeId,
                        'edge_type' => 'sentinel',
                    ]);
                }

                if (! $hasOutgoing[$nodeId]) {
                    CriticalPathEdge::create([
                        'graph_id' => $graph->id,
                        'from_node_id' => $nodeId,
                        'to_node_id' => $endNode->id,
                        'edge_type' => 'sentinel',
                    ]);
                }
            }

            // If no issue nodes, still wire START → END
            if (empty($nodeByIssueId)) {
                CriticalPathEdge::create([
                    'graph_id' => $graph->id,
                    'from_node_id' => $startNode->id,
                    'to_node_id' => $endNode->id,
                    'edge_type' => 'sentinel',
                ]);
            }
        });
    }
}
