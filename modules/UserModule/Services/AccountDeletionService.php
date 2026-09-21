<?php

declare(strict_types=1);

namespace Modules\UserModule\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Models\RagAnswerCache;
use Modules\DocumentModule\Models\Document;

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
     * @param  User  $user  The user whose documents are erased. Example: User::find($id)
     */
    private function purgeDocuments(User $user): void
    {
        $documents = Document::where('user_id', $user->id)->get();

        foreach ($documents as $document) {
            $chunkIds = $document->chunks()->pluck('id')->all();

            if ($chunkIds !== []) {
                // pgvector shard tables (if present on this installation).
                foreach (['ve_384', 've_768', 've_1024', 've_1536', 've_3072'] as $table) {
                    try {
                        DB::table($table)->whereIn('chunk_id', $chunkIds)->delete();
                    } catch (\Throwable) {
                        // Shard table may not exist — skip.
                    }
                }
                // SQLite fallback store.
                DB::table('vector_embeddings')->whereIn('chunk_id', $chunkIds)->delete();
            }

            $document->chunks()->delete();

            if ($document->file_path !== null) {
                Storage::delete($document->file_path);
            }

            $document->delete();
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
