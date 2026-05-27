<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class UploadTaskDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('team_id') && $this->user()) {
            $teams = $this->user()->teams()->limit(2)->get();
            if ($teams->count() === 1) {
                $this->merge(['team_id' => $teams->first()->id]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'file'    => ['required', 'file', 'max:10240'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
        ];
    }

    public function teamId(): int
    {
        return (int) $this->input('team_id');
    }
}
