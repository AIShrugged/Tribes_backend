<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
{
    use PaginatedRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
            'offset'            => ['nullable', 'int', 'min:0'],
            'limit'             => ['nullable', 'int', 'min:1', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);
    }

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }
}
