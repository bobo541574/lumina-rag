<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,docx,txt,csv,md',
                'max:51200',
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'embedding_model' => [
                'nullable',
                'string',
                'max:255',
            ],
            'embedding_model_id' => [
                'nullable',
                'string',
                'exists:ai_models,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A document file is required.',
            'file.file' => 'The uploaded file is invalid.',
            'file.mimes' => 'Only PDF, DOCX, TXT, CSV, and Markdown files are allowed.',
            'file.max' => 'File size must not exceed 50MB.',
            'title.max' => 'Title must not exceed 255 characters.',
            'embedding_model_id.exists' => 'The selected embedding model does not exist.',
        ];
    }
}
