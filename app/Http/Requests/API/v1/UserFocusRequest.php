<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;

class UserFocusRequest extends ApiResourceRequest
{
    public function updateRules(): array
    {
        return [
            'focus_text' => ['required', 'string', 'min:1', 'max:500'],
            'deadline'   => ['nullable', 'date_format:Y-m-d'],
            'issue_ids'  => ['nullable', 'array'],
            'issue_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function getFocusText(): string
    {
        // strip_tags after trim — removes injected markup from LLM or frontend
        return strip_tags(trim((string) $this->input('focus_text')));
    }

    public function getDeadline(): ?string
    {
        $deadline = $this->input('deadline');

        return $deadline !== null ? (string) $deadline : null;
    }

    public function getIssueIds(): ?array
    {
        $ids = $this->input('issue_ids');

        return is_array($ids) ? array_values(array_map('intval', $ids)) : null;
    }
}