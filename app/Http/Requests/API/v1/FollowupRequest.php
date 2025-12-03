<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class FollowupRequest extends FormRequest
{
    use PaginatedRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        if ($this->route()->getName() === 'calendar-events.followups.index') {
            $rules += [
                'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
                'offset' => ['nullable', 'integer', 'min:0'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ];
        }

        if ($this->route()->getName() === 'followups.show') {
            $rules += [
                'followup_id' => ['required', 'integer', 'exists:followups,id'],
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route()->getName() === 'calendar-events.followups.index') {
            $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);
        }

        if ($this->route()->getName() === 'followups.show') {
            $this->merge(['followup_id' => $this->route('followup_id')]);
        }
    }

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }

    public function getFollowupId(): int
    {
        return $this->input('followup_id');
    }
}
