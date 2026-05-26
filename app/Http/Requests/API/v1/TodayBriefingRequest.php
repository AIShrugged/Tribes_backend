<?php

namespace App\Http\Requests\API\v1;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class TodayBriefingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date'            => ['nullable', 'date_format:Y-m-d'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    public function getDate(): Carbon
    {
        $dateString = $this->input('date');

        if ($dateString) {
            return Carbon::createFromFormat('Y-m-d', $dateString, config('app.timezone'))
                ->startOfDay();
        }

        return Carbon::today(config('app.timezone'));
    }

    public function getOrganizationId(): ?int
    {
        return $this->validated('organization_id') !== null
            ? (int) $this->validated('organization_id')
            : null;
    }
}
