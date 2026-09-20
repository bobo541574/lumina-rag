<?php

declare(strict_types=1);

namespace Modules\ChatModule\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * KnowledgeChanged event
 *
 * Broadcast whenever the underlying document corpus changes in a way that could
 * invalidate previously-generated answers: a document finishes processing,
 * is deleted, has its metadata updated, or is re-embedded.
 *
 * DocumentModule dispatches this (without depending on ChatModule at runtime —
 * just a string event name) and a ChatModule listener increments the durable
 * knowledge version, which invalidates all cached RAG answers.
 *
 * @param  array  $documentIds  Optional ULIDs that changed, enabling fine-grained invalidation.
 *                              Example: ["01JAR...", "01JAS..."]
 */
class KnowledgeChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array  $documentIds  Document ULIDs that changed. Example: ["01JAR..."]
     */
    public function __construct(public array $documentIds = []) {}
}
