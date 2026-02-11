<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class MeetingTaskRequest extends FormRequest
{
    use PaginatedRequestTrait;

    public function rules(): array
    {
        $rules = [];

        if ($this->route()->getName() === 'calendar-events.tasks.index') {
            $rules += [
                'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
                'offset'            => ['nullable', 'integer', 'min:0'],
                'limit'             => ['nullable', 'integer', 'min:1', 'max:100'],
            ];
        }

        if ($this->route()->getName() === 'tasks.show') {
            $rules += [
                'task_id' => ['required', 'integer', 'exists:meeting_tasks,id'],
            ];
        }

        if ($this->route()->getName() === 'calendar-events.tasks.generate') {
            $rules += [
                'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->route()->getName(), [
            'calendar-events.tasks.index',
            'calendar-events.tasks.generate',
        ])) {
            $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);
        }

        if ($this->route()->getName() === 'tasks.show') {
            $this->merge(['task_id' => $this->route('task_id')]);
        }
    }

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }

    public function getTaskId(): int
    {
        return $this->input('task_id');
    }
}
