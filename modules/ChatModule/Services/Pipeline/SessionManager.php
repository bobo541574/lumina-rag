<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;

class SessionManager
{
public function resolveSession (?string $sessionId, ?string $userId = null): ChatSession
    {
        if ($sessionId !== null) {
            $query = ChatSession::where('id', $sessionId);

            if ($userId !== null) {
                $query->where('user_id', $userId);
            }

            $session = $query->first();
            if ($session === null) {
                throw new \RuntimeException('Chat session not found.');
            }
            if ($session->trashed()) {
                throw new \RuntimeException('Chat session has been deleted.');
            }
            if ($session->last_activity_at < now()->subHours(24)) {
                throw new \RuntimeException('Chat session has expired. Please start a new chat.');
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
public function checkMessageLimit (ChatSession $session, int $maxMessagesPerSession = 100): void
    {
        $count = $session->messages()->count();
        if ($count >= $maxMessagesPerSession) {
            throw new \RuntimeException('Session message limit reached. Please start a new chat.');
        }
    }
public function saveUserMessage (ChatSession $session, string $question): ChatMessage
    {
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $question,
        ]);
    }
public function saveAssistantMessage (ChatSession $session, string $content, array $sources): ChatMessage
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


}
