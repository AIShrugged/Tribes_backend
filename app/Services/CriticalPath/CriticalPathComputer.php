<?php

namespace App\Services\CriticalPath;

use App\Models\CriticalPathEdge;
use App\Models\CriticalPathGraph;
use App\Models\CriticalPathNode;
use Illuminate\Support\Facades\DB;

class CriticalPathComputer
{
    public function compute(CriticalPathGraph $graph): void
    {
        $nodes = CriticalPathNode::where('graph_id', $graph->id)
            ->get()
            ->keyBy('id');

        $edges = CriticalPathEdge::where('graph_id', $graph->id)->get();

        if ($nodes->isEmpty()) {
            return;
        }

        [$successors, $predecessors] = $this->buildAdjacency($nodes->keys()->all(), $edges);

        $sorted = $this->topologicalSort($nodes->keys()->all(), $successors, $predecessors);

        $es = [];
        $ef = [];
        $this->forwardPass($sorted, $nodes, $predecessors, $ef, $es);

        $ls = [];
        $lf = [];
        $this->backwardPass($sorted, $nodes, $successors, $ef, $ls, $lf);

        $results = [];
        foreach ($nodes as $nodeId => $node) {
            $slack = isset($ls[$nodeId], $es[$nodeId]) ? round($ls[$nodeId] - $es[$nodeId], 4) : null;
            $isCritical = $slack !== null && $slack <= 0.0001;

            $results[] = [
                'id' => $nodeId,
                'early_start' => $es[$nodeId] ?? null,
                'early_finish' => $ef[$nodeId] ?? null,
                'late_start' => $ls[$nodeId] ?? null,
                'late_finish' => $lf[$nodeId] ?? null,
                'slack' => $slack,
                'is_critical' => $isCritical,
            ];
        }

        DB::transaction(function () use ($graph, $results): void {
            foreach ($results as $row) {
                CriticalPathNode::where('id', $row['id'])->update([
                    'early_start' => $row['early_start'],
                    'early_finish' => $row['early_finish'],
                    'late_start' => $row['late_start'],
                    'late_finish' => $row['late_finish'],
                    'slack' => $row['slack'],
                    'is_critical' => $row['is_critical'],
                ]);
            }

            $projectDuration = max(array_filter(array_column($results, 'early_finish'), fn ($v) => $v !== null) ?: [0]);

            $graph->update([
                'status' => 'ready',
                'computed_at' => now(),
            ]);
        });
    }

    private function buildAdjacency(array $nodeIds, $edges): array
    {
        $successors = array_fill_keys($nodeIds, []);
        $predecessors = array_fill_keys($nodeIds, []);

        foreach ($edges as $edge) {
            $from = $edge->from_node_id;
            $to = $edge->to_node_id;

            if (isset($successors[$from])) {
                $successors[$from][] = $to;
            }
            if (isset($predecessors[$to])) {
                $predecessors[$to][] = $from;
            }
        }

        return [$successors, $predecessors];
    }

    private function topologicalSort(array $nodeIds, array $successors, array $predecessors): array
    {
        $inDegree = [];
        foreach ($nodeIds as $id) {
            $inDegree[$id] = count($predecessors[$id]);
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $sorted = [];
        while (! empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($successors[$current] as $successor) {
                $inDegree[$successor]--;
                if ($inDegree[$successor] === 0) {
                    $queue[] = $successor;
                }
            }
        }

        if (count($sorted) < count($nodeIds)) {
            throw new CriticalPathCycleException;
        }

        return $sorted;
    }

    private function forwardPass(array $sorted, $nodes, array $predecessors, array &$ef, array &$es): void
    {
        foreach ($sorted as $nodeId) {
            $node = $nodes[$nodeId];

            if ($node->node_type === 'start') {
                $es[$nodeId] = 0.0;
                $ef[$nodeId] = 0.0;

                continue;
            }

            $maxPredEf = 0.0;
            foreach ($predecessors[$nodeId] as $predId) {
                if (isset($ef[$predId]) && $ef[$predId] > $maxPredEf) {
                    $maxPredEf = $ef[$predId];
                }
            }

            $es[$nodeId] = $maxPredEf;
            $ef[$nodeId] = $maxPredEf + (float) $node->duration_days;
        }
    }

    private function backwardPass(array $sorted, $nodes, array $successors, array $ef, array &$ls, array &$lf): void
    {
        $projectDuration = max($ef) ?: 0.0;

        foreach (array_reverse($sorted) as $nodeId) {
            $node = $nodes[$nodeId];

            if ($node->node_type === 'end' || empty($successors[$nodeId])) {
                $lf[$nodeId] = $projectDuration;
                $ls[$nodeId] = $projectDuration - (float) $node->duration_days;

                continue;
            }

            $minSuccLs = PHP_FLOAT_MAX;
            foreach ($successors[$nodeId] as $succId) {
                if (isset($ls[$succId]) && $ls[$succId] < $minSuccLs) {
                    $minSuccLs = $ls[$succId];
                }
            }

            $lf[$nodeId] = $minSuccLs === PHP_FLOAT_MAX ? $projectDuration : $minSuccLs;
            $ls[$nodeId] = $lf[$nodeId] - (float) $node->duration_days;
        }
    }
}
