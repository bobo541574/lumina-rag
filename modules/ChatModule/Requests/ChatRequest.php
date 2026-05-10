<?php

declare(strict_types=1);

namespace Modules\ChatModule\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => [
                'required',
                'string',
                'max:1000',
            ],
            'session_id' => [
                'nullable',
                'string',
                'exists:chat_sessions,id',
            ],
            'stream' => [
                'nullable',
                'boolean',
            ],
            'document_filter' => [
                'nullable',
                'array',
            ],
            'document_filter.document_ids' => [
                'nullable',
                'array',
            ],
            'document_filter.document_ids.*' => [
                'string',
                'exists:documents,id',
            ],
            'document_filter.date_from' => [
                'nullable',
                'date',
            ],
            'document_filter.date_to' => [
                'nullable',
                'date',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => 'A question is required.',
            'question.max' => 'Question must not exceed 1000 characters.',
            'session_id.exists' => 'Chat session not found.',
        ];
    }
}
