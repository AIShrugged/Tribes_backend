<?php

namespace App\Services;

use App\Domain\DTO\TranscriptEntryDTO;

class RecallTranscriptParser
{
    public function getSpeakers(array $transcript): array
    {
        $speakers = [];

        foreach ($transcript as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $participant = $entry['participant'] ?? null;
            if (!is_array($participant)) {
                continue;
            }

            $name = $participant['name'] ?? null;
            if ($name) {
                $speakers[] = $name;
            }
        }

        return array_values(array_unique($speakers));
    }

    /**
     * Основной метод парсинга транскрипта.
     *
     * @param array<int,array<string,mixed>> $transcript
     * @return TranscriptEntryDTO[]
     */
    public function parse(array $transcript): array
    {
        $keepers = $this->coalesceTranscriptEntries($transcript);

        $result = [];

        foreach ($keepers as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $participant = $entry['participant'] ?? null;
            $words = isset($entry['words']) && is_array($entry['words']) ? $entry['words'] : [];

            if (!is_array($participant) || ($participant['name'] ?? '') === '' || $words === []) {
                continue;
            }

            $paragraph = $this->buildParagraphFromWords($words);
            if ($paragraph === '') {
                continue;
            }

            [$firstWord, $lastWord] = $this->findBoundaryWords($words);

            $dto = $this->createDtoFromEntry($participant, $paragraph, $firstWord, $lastWord);
            if ($dto !== null) {
                $result[] = $dto;
            }
        }

        return $result;
    }

    /**
     * Склеивает подряд идущие элементы с одинаковым участником.
     *
     * @param array<int,array<string,mixed>> $transcript
     * @return array<int,array<string,mixed>>
     */
    private function coalesceTranscriptEntries(array $transcript): array
    {
        $keepers = [];

        foreach ($transcript as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $participant = $entry['participant'] ?? null;
            $words = isset($entry['words']) && is_array($entry['words']) ? $entry['words'] : [];

            if (!is_array($participant)) {
                continue;
            }

            $name = $participant['name'] ?? null;
            if (!$name || $words === []) {
                // пропускаем невалидные строки
                continue;
            }

            $key = $participant['id'] ?? $participant['name'];

            $lastIndex = count($keepers) - 1;
            /** @var array<string,mixed>|null $last */
            $last = $lastIndex >= 0 ? $keepers[$lastIndex] : null;

            $lastKey = null;
            if (is_array($last)) {
                $lastParticipant = $last['participant'] ?? null;
                if (is_array($lastParticipant)) {
                    $lastKey = $lastParticipant['id'] ?? ($lastParticipant['name'] ?? null);
                }
            }

            if ($last !== null && $key !== null && $key === $lastKey) {
                // добавляем слова к предыдущей записи
                $lastWords = isset($keepers[$lastIndex]['words']) && is_array($keepers[$lastIndex]['words'])
                    ? $keepers[$lastIndex]['words']
                    : [];

                $keepers[$lastIndex]['words'] = array_merge($lastWords, $words);
            } else {
                // сохраняем оригинальный массив, чтобы не потерять неизвестные поля
                $keepers[] = $entry;
            }
        }

        return $keepers;
    }

    /**
     * Собирает текст абзаца из массива слов.
     *
     * @param array<int,array<string,mixed>> $words
     */
    private function buildParagraphFromWords(array $words): string
    {
        $pieces = [];

        foreach ($words as $w) {
            if (!is_array($w)) {
                continue;
            }
            $text = isset($w['text']) ? trim((string)$w['text']) : '';
            if ($text !== '') {
                $pieces[] = $text;
            }
        }

        return trim(implode(' ', $pieces));
    }

    /**
     * Ищет первое слово с start_timestamp и последнее с end_timestamp.
     *
     * @param array<int,array<string,mixed>> $words
     * @return array{0: ?array, 1: ?array}
     */
    private function findBoundaryWords(array $words): array
    {
        $first = null;
        foreach ($words as $w) {
            if (!is_array($w)) {
                continue;
            }
            if (!empty($w['start_timestamp']) && is_array($w['start_timestamp'])) {
                $first = $w;
                break;
            }
        }

        $last = null;
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $w = $words[$i] ?? null;
            if (!is_array($w)) {
                continue;
            }
            if (!empty($w['end_timestamp']) && is_array($w['end_timestamp'])) {
                $last = $w;
                break;
            }
        }

        return [$first, $last];
    }

    /**
     * Собирает DTO из участника, текста и граничных слов.
     *
     * @param array<string,mixed> $participant
     * @param string $paragraph
     * @param array<string,mixed>|null $firstWord
     * @param array<string,mixed>|null $lastWord
     */
    private function createDtoFromEntry(
        array $participant,
        string $paragraph,
        ?array $firstWord,
        ?array $lastWord
    ): ?TranscriptEntryDTO {
        if (($participant['name'] ?? '') === '' || $paragraph === '') {
            return null;
        }

        $startRel = $firstWord['start_timestamp']['relative'] ?? null;
        $startAbs = $firstWord['start_timestamp']['absolute'] ?? null;
        $endRel = $lastWord['end_timestamp']['relative'] ?? null;
        $endAbs = $lastWord['end_timestamp']['absolute'] ?? null;

        $durationSeconds = $this->calculateDuration($startRel, $endRel, $startAbs, $endAbs);

        return new TranscriptEntryDTO(
            speaker: $participant['name'],
            paragraph: $paragraph,
            startRelative: is_numeric($startRel) ? (float)$startRel : null,
            startAbsolute: is_string($startAbs) ? $startAbs : null,
            endRelative: is_numeric($endRel) ? (float)$endRel : null,
            endAbsolute: is_string($endAbs) ? $endAbs : null,
            durationSeconds: $durationSeconds,
        );
    }

    /**
     * Считает длительность по относительным или абсолютным таймстампам.
     */
    private function calculateDuration(
        mixed $startRel,
        mixed $endRel,
        mixed $startAbs,
        mixed $endAbs
    ): ?float {
        // 1) по относительным секундам
        if (is_numeric($startRel) && is_numeric($endRel)) {
            return (float)$endRel - (float)$startRel;
        }

        // 2) по абсолютным ISO-дейтам
        if (is_string($startAbs) && is_string($endAbs)) {
            $startTime = strtotime($startAbs);
            $endTime = strtotime($endAbs);

            if ($startTime !== false && $endTime !== false) {
                return (float)($endTime - $startTime);
            }
        }

        return null;
    }
}
