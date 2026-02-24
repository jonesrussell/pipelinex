<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CrawlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
            'options' => ['sometimes', 'array'],
            'options.timeout' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'options.wait_for_js' => ['sometimes', 'boolean'],
            'options.include_html' => ['sometimes', 'boolean'],
            'options.include_links' => ['sometimes', 'boolean'],
            'options.include_images' => ['sometimes', 'boolean'],
        ];
    }
}
