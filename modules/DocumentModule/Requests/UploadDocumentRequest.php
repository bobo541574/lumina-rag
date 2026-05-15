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
        $availableModels = config('rag.embedding.available_models', []);
        $modelsString = is_array($availableModels) ? implode(',', $availableModels) : (string) $availableModels;

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
                'in:'.$modelsString,
            ],
        ];
    }

    public function messages(): array
    {
        $availableModels = config('rag.embedding.available_models', []);
        $modelsList = is_array($availableModels) ? implode(', ', $availableModels) : (string) $availableModels;

        return [
            'file.required' => 'A document file is required.',
            'file.mimes' => 'File must be one of: PDF, DOCX, TXT, CSV, MD.',
            'file.max' => 'File size must not exceed 50MB.',
            'embedding_model.in' => 'Embedding model must be one of: '.$modelsList.'.',
        ];
    }
}
