<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\ChatModule\Exceptions\ChatSessionException;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;

class SessionManager
{
    private int $sessionTimeoutHours;

    public function __construct()
    {
        $this->sessionTimeoutHours = (int) config('rag.chat.session_timeout_hours', 24);
    }

    public function resolveSession(?string $sessionId, ?string $userId = null): ChatSession
    {
        if ($sessionId !== null) {
            $query = ChatSession::where('id', $sessionId);

            if ($userId !== null) {
                $query->where('user_id', $userId);
            }

            $session = $query->first();
            if ($session === null) {
                throw new ChatSessionException('Chat session not found.');
            }
            if ($session->trashed()) {
                throw new ChatSessionException('Chat session has been deleted.');
            }
            if ($session->last_activity_at < now()->subHours($this->sessionTimeoutHours)) {
                throw new ChatSessionException('Chat session has expired. Please start a new chat.');
            }
            $session->update(['last_activity_at' => now()]);

            return $session;
        }

        return ChatSession::create([
            'title' => 'New Chat',
            'last_activity_at' => now(),
            'user_id' => $userId,
        ]);
    }

    public function checkMessageLimit(ChatSession $session, int $maxMessagesPerSession = 100): void
    {
        $count = $session->messages()->count();
        if ($count >= $maxMessagesPerSession) {
            throw new ChatSessionException('Session message limit reached. Please start a new chat.');
        }
    }

    public function saveUserMessage(ChatSession $session, string $question): ChatMessage
    {
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $question,
        ]);
    }

    public function saveAssistantMessage(ChatSession $session, string $content, array $sources): ChatMessage
    {
        if ($session->title === 'New Chat') {
            $session->update([
                'title' => mb_substr($content, 0, 50),
            ]);
        }
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'sources' => $sources,
        ]);
    }

    /**
     * List the user's sessions, most recently active first.
     *
     * @param  string  $userId  Owner ULID. Example: "01J..."
     * @param  int  $page  Page number (1-based). Example: 1
     * @return array Laravel paginator array (data, current_page, last_page, per_page, total, from, to)
     */
    public function listSessions(string $userId, int $page = 1): array
    {
        return ChatSession::ownedBy($userId)
            ->orderByDesc('last_activity_at')
            ->paginate((int) config('rag.pagination.per_page', 20), ['*'], 'page', $page)
            ->toArray();
    }

    /**
     * Load one of the user's sessions with its messages.
     *
     * @param  string  $id  Session ULID. Example: "01J..."
     * @param  string  $userId  Owner ULID; other users' sessions are not found. Example: "01J..."
     * @return array Session with messages
     *
     * @throws ModelNotFoundException When the user owns no such session
     */
    public function getSession(string $id, string $userId): array
    {
        return ChatSession::ownedBy($userId)->with('messages')->findOrFail($id)->toArray();
    }

    /**
     * Delete one of the user's sessions and its messages.
     *
     * @param  string  $id  Session ULID. Example: "01J..."
     * @param  string  $userId  Owner ULID; other users' sessions are not found. Example: "01J..."
     *
     * @throws ModelNotFoundException When the user owns no such session
     */
    public function deleteSession(string $id, string $userId): void
    {
        $session = ChatSession::ownedBy($userId)->findOrFail($id);
        $session->messages()->delete();
        $session->delete();
    }
}
