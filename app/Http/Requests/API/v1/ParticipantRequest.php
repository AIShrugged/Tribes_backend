<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class ParticipantRequest extends FormRequest
{
    use PaginatedRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'calendar_event_id' => 'required|integer|exists:calendar_events,id',
        ];

        if ($this->route()->getName() === 'calendar-events.participants.index') {
            $rules += [
                'offset' => ['nullable', 'integer', 'min:0'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ];
        }

        if ($this->route()->getName() === 'calendar-events.participants.set-profile') {
            $rules += [
                'participant_id' => 'required|integer|exists:participants,id',
                'profile_id' => 'required|integer|exists:profiles,id',
            ];
        }

        return $rules;
    }

    protected function prepareForValidation()
    {
        $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);

        if ($this->route()->getName() === 'calendar-events.participants.set-profile') {
            $this->merge(['participant_id' => $this->route('participant_id')]);
        }
    }

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }

    public function getParticipantId(): int
    {
        return $this->input('participant_id');
    }

    public function getProfileId(): int
    {
        return $this->input('profile_id');
    }

    public function bodyParameters(): array
    {
        return [
            'profile_id' => [
                'description' => 'The Profile ID to link to this participant.',
                'example'     => 3,
            ],
        ];
    }

}
