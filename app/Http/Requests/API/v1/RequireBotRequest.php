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
        return $this->input('required_bot');
    }
}
