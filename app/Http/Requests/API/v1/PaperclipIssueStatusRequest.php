<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class PaperclipIssueStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:done,blocked,failed'],
            'last_comment' => ['nullable', 'string', 'min:1', 'max:10000', 'required_without:comment'],
            'comment' => ['nullable', 'string', 'min:1', 'max:10000', 'required_without:last_comment'],
            'artifacts' => ['nullable', 'array'],
        ];
    }

    public function getStatus(): string
    {
        return (string) $this->input('status');
    }

    public function getComment(): string
    {
        $comment = $this->input('last_comment');

        if (! is_string($comment) || trim($comment) === '') {
            $comment = $this->input('comment');
        }

        return trim((string) $comment);
    }

    public function getArtifacts(): array
    {
        $artifacts = $this->input('artifacts');

        return is_array($artifacts) ? $artifacts : [];
    }
}
