<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeatureSpecRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['feature' => ['required', 'file', 'max:1024', 'mimes:md,markdown,txt', 'mimetypes:text/plain,text/markdown']];
    }
}
