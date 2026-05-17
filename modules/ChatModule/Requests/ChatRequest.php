<?php

declare(strict_types=1);

namespace Modules\ChatModule\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Chat Request
 *
 * Handles validation and authorization for the /api/chat endpoint.
 * Ensures the question is present and within length limits, validates
 * optional session_id and LLM model existence, and validates nested
 * document_filter parameters (document IDs, date ranges).
 * Authorisation is open (returns true) — access control is handled
 * by the auth.token middleware at the route level.
 */
class ChatRequest extends FormRequest
{
    /**
     * Authorize the request
     *
     * All authorization is handled by the auth.token middleware on the
     * route, so the form request always permits access.
     *
     * @return bool Always true
     *
     * @example $request->authorize() → true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules
     *
     * Validates: question (required, string, max:1000), session_id (nullable,
     * exists:chat_sessions), stream (nullable, boolean), document_filter
     * (nullable, array with nested document_ids and date_from/date_to), and
     * llm_model_id (nullable, exists:ai_models).
     *
     * @return array<string, array<int, mixed>> The validation rules keyed by field name.
     *                                          Example: ['question' => ['required', 'string', 'max:1000']]
     */
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
            'llm_model_id' => [
                'nullable',
                'string',
                'exists:ai_models,id',
            ],
        ];
    }

    /**
     * Get custom validation error messages
     *
     * Provides localised/readable error messages for the validation rules.
     *
     * @return array<string, string> Custom messages keyed by rule.
     *                               Example: ['question.required' => 'A question is required.']
     */
    public function messages(): array
    {
        return [
            'question.required' => 'A question is required.',
            'question.max' => 'Question must not exceed 1000 characters.',
            'session_id.exists' => 'Chat session not found.',
        ];
    }
}
