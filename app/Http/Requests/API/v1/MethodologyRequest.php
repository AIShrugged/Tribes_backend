<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class MethodologyRequest extends FormRequest
{
    use PaginatedRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'methodologies.index' => $this->getPaginationRules(),
            'methodologies.show' => [
                'methodology_id' => ['required', 'integer', 'exists:methodologies,id']
            ],
            'methodologies.store' => [
                'name' => ['required', 'string', 'min:3', 'max:255'],
                'text' => ['required', 'string', 'min:3'],
            ],
            'methodologies.update' => [
                'methodology_id' => ['required', 'integer', 'exists:methodologies,id'],
                'name'           => ['required', 'string', 'min:3', 'max:255'],
                'text'           => ['required', 'string', 'min:3'],
            ],
            'methodologies.destroy' => [
                'methodology_id' => ['required', 'integer', 'exists:methodologies,id'],
            ]
        };
    }

    protected function prepareForValidation()
    {
        $this->merge(['methodology_id' => $this->route('id')]);
    }

    public function getMethodologyId(): int
    {
        return $this->input('methodology_id');
    }

    public function getStoreData(): array
    {
        return [
            'user_id' => Auth::id(),
            'name'    => $this->input('name'),
            'text'    => $this->input('text'),
        ];
    }

    public function getUpdateData(): array
    {
        return [
            'name' => $this->input('name'),
            'text' => $this->input('text'),
        ];
    }
}
