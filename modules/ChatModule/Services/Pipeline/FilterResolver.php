<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\DocumentModule\Models\Document;

/**
 * Decides which documents a question may search.
 *
 * Combines filters extracted from the question, filters inherited from the
 * previous exchange (follow-ups like "what about last week?"), and tenant
 * isolation (ISO 27001/27701: a user may only search their own documents).
 */
class FilterResolver
{
    public function __construct(private FilterExtractor $filterExtractor) {}

    /**
     * Resolve the search filters for a question.
     *
     * @param  array  $extracted  FilterExtractor::extract() output for the question. Example: ["project" => "Orion"]
     * @param  ChatSession  $session  Session the question belongs to (user message already saved)
     * @param  string|null  $userId  Requesting user, null for system contexts. Example: "01J..."
     * @return array Filters for the vector store. Example: ["user_ids" => ["01J..."], "project" => "Orion"]
     */
    public function resolve(array $extracted, ChatSession $session, ?string $userId): array
    {
        $filters = $this->inheritFromPreviousExchange($extracted, $session);

        return $this->applyTenantIsolation($filters, $userId);
    }

    /**
     * Constrain filters to the user's own documents.
     *
     * Forces user_ids to the requester and drops document_ids they do not own.
     * No-op when `rag.security.tenant_isolation` is off.
     *
     * @param  array  $filters  Filters to constrain. Example: ["document_ids" => ["01J..."]]
     * @param  string|null  $userId  Requesting user. Example: "01J..."
     * @return array Tenant-safe filters
     */
    public function applyTenantIsolation(array $filters, ?string $userId): array
    {
        if (! config('rag.security.tenant_isolation', true)) {
            return $filters;
        }

        if ($userId !== null) {
            $filters['user_ids'] = [$userId];
        }

        if (isset($filters['document_ids'])) {
            $query = Document::whereIn('id', (array) $filters['document_ids']);
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            $filters['document_ids'] = $query->pluck('id')->all();
        }

        return $filters;
    }

    /**
     * Carry user/project/date filters and cited documents over from the
     * previous exchange when the question names no user or project.
     *
     * Dates and cited documents are only inherited when the previous answer
     * was not a refusal, so a failed lookup does not narrow the next one.
     *
     * @param  array  $filters  Filters extracted from the current question
     * @param  ChatSession  $session  The chat session
     * @return array Filters including inherited scope
     */
    private function inheritFromPreviousExchange(array $filters, ChatSession $session): array
    {
        if (! empty($filters['user_ids']) || ! empty($filters['project'])) {
            return $filters;
        }

        $previousQuestion = ChatMessage::where('session_id', $session->id)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->skip(1)
            ->value('content');

        if ($previousQuestion === null) {
            return $filters;
        }

        $previousAnswer = ChatMessage::where('session_id', $session->id)
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();
        $wasRefusal = $previousAnswer !== null && empty($previousAnswer->sources);

        $inherited = $this->filterExtractor->extract($previousQuestion);
        foreach (['user_ids', 'project'] as $key) {
            if (isset($inherited[$key])) {
                $filters[$key] = $inherited[$key];
            }
        }

        if ($wasRefusal) {
            return $filters;
        }

        if (isset($inherited['report_date_from']) && ! isset($filters['report_date_from'])) {
            $filters['report_date_from'] = $inherited['report_date_from'];
            $filters['report_date_to'] = $inherited['report_date_to'];
        }

        // Scope the follow-up to the documents the previous answer cited.
        $citedIds = array_values(array_filter(array_column((array) ($previousAnswer?->sources ?? []), 'document_id')));
        if ($citedIds !== []) {
            $filters['document_ids'] = $citedIds;
        }

        return $filters;
    }
}
