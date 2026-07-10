<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class JoinBotNowRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
            'organization_id'   => ['required', 'integer', 'exists:organizations,id'],
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);
    }

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }

    public function getOrganizationId(): int
    {
        return (int) $this->input('organization_id');
    }

    public function bodyParameters(): array
    {
        return [
            'organization_id' => [
                'description' => 'Organization the bot is connected from. The meeting then appears '
                    .'in that organization\'s calendar.',
                'example'     => 42,
            ],
        ];
    }
}
