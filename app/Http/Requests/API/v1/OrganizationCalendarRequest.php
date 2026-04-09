<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class OrganizationCalendarRequest extends FormRequest
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
            'offset'    => ['nullable', 'integer', 'min:0'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function getDateFrom(): ?Carbon
    {
        $value = $this->input('date_from');

        return $value
            ? Carbon::createFromFormat('Y-m-d', $value, config('app.timezone'))->startOfDay()
            : null;
    }

    public function getDateTo(): ?Carbon
    {
        $value = $this->input('date_to');

        return $value
            ? Carbon::createFromFormat('Y-m-d', $value, config('app.timezone'))->endOfDay()
            : null;
    }
}
