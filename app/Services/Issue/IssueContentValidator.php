<?php

namespace App\Services\Issue;

use App\Models\Issue;

class IssueContentValidator
{
    /**
     * Validate Issue.description content. Returns the list of section keys that
     * are missing or empty for this issue's type.
     *
     * @return string[] section keys (e.g. ['context','dod']); empty array means fully complete
     */
    public function validate(Issue $issue): array
    {
        $description = (string) ($issue->description ?? '');
        $required    = $this->requiredSectionsFor($issue);

        if (empty($required)) {
            return [];
        }

        $sections = $this->extractSections($description);

        $missing = [];
        foreach ($required as $sectionKey) {
            if (! $this->sectionPresent($sectionKey, $sections)) {
                $missing[] = $sectionKey;
            }
        }

        return $missing;
    }

    /**
     * @return string[]
     */
    private function requiredSectionsFor(Issue $issue): array
    {
        $perType = (array) config('issue_validation.sections_per_type', []);
        $type    = $issue->type;

        if ($type !== null && array_key_exists($type, $perType)) {
            return $perType[$type];
        }

        return $perType['*'] ?? [];
    }

    /**
     * Walk the markdown and return ['<lowercased_alias>' => '<body_text>'] for every
     * detected section heading. Supports:
     *   ## Контекст            (level 1-6, optional trailing colon, optional whitespace)
     *   **Контекст**           (bold-fallback on its own line)
     *
     * @return array<string,string>
     */
    private function extractSections(string $markdown): array
    {
        $aliasMap = $this->buildAliasMap();
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        $sections = [];
        $currentKey = null;
        $currentBody = '';

        foreach ($lines as $line) {
            $alias = $this->detectHeading($line, $aliasMap);

            if ($alias !== null) {
                if ($currentKey !== null) {
                    $sections[$currentKey] = ($sections[$currentKey] ?? '').$currentBody;
                }
                $currentKey = $alias;
                $currentBody = '';
                continue;
            }

            if ($currentKey !== null) {
                $currentBody .= $line."\n";
            }
        }

        if ($currentKey !== null) {
            $sections[$currentKey] = ($sections[$currentKey] ?? '').$currentBody;
        }

        return $sections;
    }

    /**
     * Try to detect a heading in $line. Returns the section key or null.
     *
     * @param  array<string,string>  $aliasMap  lowercased-alias => section-key
     */
    private function detectHeading(string $line, array $aliasMap): ?string
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        // ## Heading  /  ### Heading  (optional trailing ':')
        if (preg_match('/^#{1,6}\s+(.+?)\s*:?\s*$/u', $trimmed, $m)) {
            $candidate = mb_strtolower(rtrim($m[1], ':'));
            return $aliasMap[$candidate] ?? null;
        }

        // **Heading**  (bold-fallback on its own line, optional trailing ':')
        if (preg_match('/^\*\*(.+?)\*\*\s*:?\s*$/u', $trimmed, $m)) {
            $candidate = mb_strtolower(rtrim($m[1], ':'));
            return $aliasMap[$candidate] ?? null;
        }

        return null;
    }

    /**
     * @param  array<string,string>  $sections  sectionKey => body
     */
    private function sectionPresent(string $key, array $sections): bool
    {
        if (! array_key_exists($key, $sections)) {
            return false;
        }

        $body = trim($sections[$key]);
        if ($body === '') {
            return false;
        }

        $minChars = (int) config('issue_validation.non_empty_min_chars', 3);
        $stripped = preg_replace('/\s+/u', '', $body) ?? '';
        if (mb_strlen($stripped) < $minChars) {
            return false;
        }

        $placeholders = (array) config('issue_validation.placeholders', []);
        foreach ($placeholders as $placeholder) {
            if ($placeholder === '' || $placeholder === null) {
                continue;
            }
            if (mb_strtolower($body) === mb_strtolower((string) $placeholder)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,string>  lowercased-alias => section-key
     */
    private function buildAliasMap(): array
    {
        $aliases = (array) config('issue_validation.heading_aliases', []);
        $map = [];
        foreach ($aliases as $sectionKey => $aliasList) {
            foreach ((array) $aliasList as $alias) {
                $map[mb_strtolower((string) $alias)] = $sectionKey;
            }
        }
        return $map;
    }
}
