<?php

declare(strict_types=1);

namespace Modules\UserModule\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Models\RagAnswerCache;
use Modules\DocumentModule\Models\Document;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Account Deletion Service
 *
 * Erases all personal data a user accumulated across the platform, satisfying
 * privacy erasure / retention obligations (ISO 27701:7.2.5, 7.3.4, 8.2.4 and
 * ISO 27002:8.10 information deletion). Deletes in dependency order:
 * vectors → document chunks/files → documents → chat messages/sessions →
 * semantic cache entries → user record. Operations are destructive.
 */
class AccountDeletionService
{
    /**
     * @param  VectorStoreInterface  $vectorStore  Vector store that owns the embeddings. Example: app(VectorStoreInterface::class)
     */
    public function __construct(private VectorStoreInterface $vectorStore) {}

    /**
     * Purge every resource owned by the user, then delete the account.
     *
     * @param  User  $user  The user to erase. Example: User::find($id)
     */
    public function delete(User $user): void
    {
        $this->purgeDocuments($user);
        $this->purgeChat($user);
        $this->purgeSemanticCache($user);

        $userId = $user->id;
        $user->delete();

        Log::channel('security')->info('user.deleted', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Remove the user's documents, their chunks, and all stored vectors.
     *
     * Includes previously soft-deleted documents and hard-deletes the rows,
     * since erasure must not leave document metadata behind.
     *
     * @param  User  $user  The user whose documents are erased. Example: User::find($id)
     */
    private function purgeDocuments(User $user): void
    {
        $documents = Document::withTrashed()->where('user_id', $user->id)->get();

        foreach ($documents as $document) {
            $this->vectorStore->deleteByDocumentId($document->id);
            $document->chunks()->delete();

            if ($document->file_path !== null) {
                Storage::delete($document->file_path);
            }

            $document->forceDelete();
        }

        if ($documents->isNotEmpty()) {
            // Other users' cached answers may cite these documents.
            KnowledgeChanged::dispatch($documents->pluck('id')->all());
        }
    }

    /**
     * Remove the user's chat sessions and their messages.
     *
     * @param  User  $user  The user whose chats are erased. Example: User::find($id)
     */
    private function purgeChat(User $user): void
    {
        $sessions = ChatSession::where('user_id', $user->id)->get();

        foreach ($sessions as $session) {
            $session->messages()->delete();
            $session->delete();
        }
    }

    /**
     * Remove all semantic-cache answers scoped to the user.
     *
     * @param  User  $user  The user whose cached answers are erased. Example: User::find($id)
     */
    private function purgeSemanticCache(User $user): void
    {
        RagAnswerCache::where('user_id', $user->id)->delete();
    }
}
