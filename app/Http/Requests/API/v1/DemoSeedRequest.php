<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class DemoSeedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'teams_count'        => ['nullable', 'integer', 'min:1', 'max:3'],
            'employees_per_team' => ['nullable', 'integer', 'min:3', 'max:10'],
            'meetings_per_team'  => ['nullable', 'integer', 'min:1', 'max:6'],
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'teams_count' => [
                'description' => 'How many demo teams to create.',
                'example' => 1,
            ],
            'employees_per_team' => [
                'description' => 'How many demo employees to create per team.',
                'example' => 7,
            ],
            'meetings_per_team' => [
                'description' => 'How many demo meetings to generate for each team.',
                'example' => 3,
            ],
        ];
    }

    public function getParams(): array
    {
        return [
            'teams_count'        => (int) $this->input('teams_count', 1),
            'employees_per_team' => (int) $this->input('employees_per_team', 7),
            'meetings_per_team'  => (int) $this->input('meetings_per_team', 3),
        ];
    }
}
