<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class RequireBotRequest extends FormRequest
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
            'required_bot'      => ['required', 'boolean'],
            'organization_id'   => ['nullable', 'integer', 'required_if:required_bot,true', 'exists:organizations,id'],
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

    public function getRequiredBot(): bool
    {
        return $this->boolean('required_bot');
    }

    public function getOrganizationId(): ?int
    {
        $value = $this->input('organization_id');

        return $value !== null ? (int) $value : null;
    }

    public function bodyParameters(): array
    {
        return [
            'required_bot' => [
                'description' => 'Whether the recording bot should join the event.',
                'example'     => true,
            ],
            'organization_id' => [
                'description' => 'Organization the bot is connected from. Required when required_bot is true; '
                    .'the meeting then appears in that organization\'s calendar.',
                'example'     => 42,
            ],
        ];
    }
}
