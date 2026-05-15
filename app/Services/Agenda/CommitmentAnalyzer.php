<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class CommitmentAnalyzer
{
    public function parseCommitment(string $raw, ?Carbon $meetingDate): array
    {
        $person = '?';
        $commitment = $raw;
        $deadline = null;

        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $raw, $m)) {
            $person = $m[1];
            $commitment = $m[2];
        } elseif (preg_match('/^([^:]+?):\s*(.*)$/s', $raw, $m)) {
            $person = trim($m[1]);
            $commitment = $m[2];
        }

        if (preg_match('/\(([^)]+)\)\s*$/', $commitment, $m)) {
            $deadlineText = mb_strtolower(trim($m[1]));
            $commitment = trim(preg_replace('/\([^)]+\)\s*$/', '', $commitment));

            if ($meetingDate && str_contains($deadlineText, 'завтра')) {
                $deadline = $meetingDate->copy()->addDay()->format('d.m.Y');
            } elseif ($deadlineText !== 'без срока') {
                $deadline = $m[1];
            }
        }

        return [
            'person'     => $person,
            'commitment' => trim($commitment),
            'deadline'   => $deadline,
        ];
    }

    public function extractCommitmentsFromSummary(?string $summary): array
    {
        if (!$summary) {
            return [];
        }

        $commitments = [];

        if (preg_match('/=== COMMITMENTS ===(.*?)(?:===|\z)/s', $summary, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim(ltrim(trim($line), '-'));
                if ($line !== '') {
                    $commitments[] = $line;
                }
            }
        }

        if (empty($commitments) && preg_match('/### Следующие шаги\n(.*?)(?:###|===|\z)/s', $summary, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim(ltrim(trim($line), '-'));
                if ($line !== '') {
                    $commitments[] = $line;
                }
            }
        }

        if (empty($commitments) && preg_match_all('/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|/m', $summary, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $who      = trim($row[1]);
                $what     = trim($row[2]);
                $deadline = trim($row[3]);

                if ($who === 'Кто' || str_starts_with($who, '-')) {
                    continue;
                }

                $deadlineStr = ($deadline && $deadline !== '-') ? " ({$deadline})" : ' (без срока)';
                $commitments[] = "[{$who}] {$what}{$deadlineStr}";
            }
        }

        return $commitments;
    }

    public function matchCommitmentStatus(string $person, string $commitment, Collection $issues): string
    {
        return $this->matchCommitmentToIssue($person, $commitment, $issues)['status'];
    }

    /**
     * Same matching as {@see self::matchCommitmentStatus} but also returns the matched
     * Issue id so callers (agenda renderer) can link the commitment to its issue page.
     *
     * @return array{status: string, issue_id: ?int}
     */
    public function matchCommitmentToIssue(string $person, string $commitment, Collection $issues): array
    {
        $nameVariants = $this->getNameVariants($person);

        $personIssues = $issues->filter(function ($issue) use ($nameVariants) {
            $assignee = mb_strtolower($issue->assignee?->name ?? $issue->assignee_name ?? '');
            foreach ($nameVariants as $variant) {
                if (str_contains($assignee, $variant)) {
                    return true;
                }
            }
            return false;
        });

        if ($personIssues->isEmpty()) {
            return ['status' => 'ожидание', 'issue_id' => null];
        }

        $commitmentWords = array_filter(
            preg_split('/[\s,.:;]+/u', mb_strtolower($commitment)),
            fn ($w) => mb_strlen($w) > 3,
        );

        foreach ($personIssues as $issue) {
            $issueName  = mb_strtolower($issue->name);
            $matchCount = 0;
            foreach ($commitmentWords as $word) {
                if (str_contains($issueName, $word)) {
                    $matchCount++;
                }
            }
            if ($matchCount >= 2 || ($matchCount >= 1 && count($commitmentWords) <= 2)) {
                $status = match ($issue->status) {
                    'done'        => 'готово',
                    'in_progress' => 'в работе',
                    'cancelled'   => 'отменено',
                    default       => 'ожидание',
                };
                return ['status' => $status, 'issue_id' => $issue->id];
            }
        }

        $hasInProgress = $personIssues->contains('status', 'in_progress');
        return [
            'status'   => $hasInProgress ? 'в работе' : 'ожидание',
            'issue_id' => null,
        ];
    }

    private function getNameVariants(string $person): array
    {
        $person = mb_strtolower(trim($person));
        $map = [
            'борис'      => ['boris', 'борис'],
            'борь'       => ['boris', 'борис'],
            'boris'      => ['boris', 'борис'],
            'иван'       => ['ivan', 'иван', 'zakharov', 'захаров'],
            'ivan'       => ['ivan', 'иван', 'zakharov'],
            'константин' => ['konstantin', 'константин', 'kupreychenko', 'купрейченко', 'костя'],
            'костя'      => ['konstantin', 'константин', 'kupreychenko', 'костя'],
            'konstantin' => ['konstantin', 'константин', 'kupreychenko'],
            'слава'      => ['slava', 'слава'],
            'slava'      => ['slava', 'слава'],
            'фёдор'      => ['fedor', 'фёдор', 'федор', 'zhernovoy', 'жерновой'],
            'федор'      => ['fedor', 'фёдор', 'федор', 'zhernovoy'],
            'fedor'      => ['fedor', 'фёдор', 'федор', 'zhernovoy'],
            'никита'     => ['nikita', 'никита'],
        ];

        if (isset($map[$person])) {
            return $map[$person];
        }

        $variants = [];
        foreach ($map as $key => $values) {
            if (str_contains($person, $key)) {
                $variants = array_merge($variants, $values);
            }
        }

        return !empty($variants) ? array_unique($variants) : [$person];
    }
}
