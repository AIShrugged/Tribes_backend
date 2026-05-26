<?php

namespace App\Support;

class IssueDescriptionFormatter
{
    public static function hasTaskSections(?string $description): bool
    {
        $description = (string) $description;

        return str_contains($description, '## Context')
            && str_contains($description, '## Steps')
            && str_contains($description, '## Definition of done');
    }

    public static function onboardingTask(
        ?string $description,
        string $goalTitle,
        ?string $goalDescription = null,
    ): string {
        if (self::hasTaskSections($description)) {
            return trim((string) $description);
        }

        $contextParts = [];

        if (trim($goalTitle) !== '') {
            $contextParts[] = "Задача создана при онбординге организации в рамках цели «{$goalTitle}».";
        } else {
            $contextParts[] = 'Задача создана при онбординге организации.';
        }

        if (!blank($goalDescription)) {
            $contextParts[] = trim((string) $goalDescription);
        }

        if (!blank($description)) {
            $contextParts[] = trim((string) $description);
        }

        $context = implode(' ', $contextParts);

        return <<<MD
## Context
{$context}

## Steps
1. Уточнить текущий статус, ограничения и ожидаемый результат.
2. Выполнить необходимые изменения или организационные действия.
3. Зафиксировать результат и сообщить команде о завершении.

## Definition of done
Задача завершена, когда результат принят ответственными участниками и обновлён в трекере.
MD;
    }
}
