<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApiResourceRequest extends FormRequest
{
    public function rules(): array
    {
        $action = $this->route()->getActionMethod();

        $methodName = Str::camel($action . 'rules');

        if (!method_exists($this, $methodName)) {
            Log::info("The given action [{$this->route()->getName()}] has no specific validation rules. Applying empty ruleset.");

            return [];
        }

        return $this->$methodName();
    }

    public function indexRules(): array
    {
        return [
            'offset' => ['nullable', 'int', 'min:0'],
            'limit'  => ['nullable', 'int', 'min:1', 'max:100'],
        ];
    }
}
