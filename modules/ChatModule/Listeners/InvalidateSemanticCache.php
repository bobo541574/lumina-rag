<?php

declare(strict_types=1);

namespace Modules\ChatModule\Listeners;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\ChatModule\Models\DocumentVersion;
use Modules\ChatModule\Models\KnowledgeVersion;
use Modules\ChatModule\Services\SemanticCacheService;

/**
 * Invalidate semantic cache on knowledge changes.
 *
 * Fine-grained (Phase 2): when the event names specific documents, only those
 * documents' versions are bumped and only cache entries referencing them are
 * removed — unrelated cached answers stay valid. When no documents are named
 * (e.g. a bulk re-embed), the coarse global version is bumped, invalidating the
 * whole corpus namespace as a safety net. Cached meta (users/projects) that
 * FilterExtractor relies on is refreshed in both cases.
 */
class InvalidateSemanticCache
{
    private SemanticCacheService $semanticCache;

    private CacheRepository $cache;

    public function __construct(SemanticCacheService $semanticCache, CacheRepository $cache)
    {
        $this->semanticCache = $semanticCache;
        $this->cache = $cache;
    }

    public function handle(KnowledgeChanged $event): void
    {
        if ($event->documentIds !== []) {
            // Fine-grained: bump only the changed documents' versions and drop
            // any cache entries that reference them. The global version is left
            // untouched so answers about unrelated documents remain cached.
            DocumentVersion::bumpMany($event->documentIds);
            $this->semanticCache->invalidateForDocuments($event->documentIds);
        } else {
            // Coarse-grained: no specific documents known, invalidate the whole
            // corpus. Because cached answers are keyed by the global version,
            // bumping renders them all as misses.
            KnowledgeVersion::bump();
            $this->semanticCache->flush();
        }

        // Drop cached meta lookups so the next query reflects the new corpus.
        $this->cache->forget('rag:users');
        $this->cache->forget('rag:projects');
    }
}
