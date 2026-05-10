<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;

class LLMService implements LLMServiceInterface
{
    private LLMProviderInterface $provider;

    private int $maxContextTokens;

    public function __construct(
        LLMProviderInterface $provider,
        int $maxContextTokens = 4000,
    ) {
        $this->provider = $provider;
        $this->maxContextTokens = $maxContextTokens;
    }

    public function complete(string $systemPrompt, string $userPrompt, array $context, array $options = []): LLMResponseInterface
    {
        $assembledPrompt = $this->assemblePrompt($userPrompt, $context);

        return $this->provider->complete($systemPrompt, $assembledPrompt, $options);
    }

    public function completeStream(string $systemPrompt, string $userPrompt, array $context, array $options = []): Generator
    {
        $assembledPrompt = $this->assemblePrompt($userPrompt, $context);

        return $this->provider->completeStream($systemPrompt, $assembledPrompt, $options);
    }

    private function assemblePrompt(string $userPrompt, array $context): string
    {
        $contextStr = $this->buildContextString($context);

        return "Context:\n---\n{$contextStr}\n---\n\nQuestion: {$userPrompt}\n\nAnswer:";
    }

    private function buildContextString(array $chunks): string
    {
        $parts = [];
        $totalTokens = 0;

        foreach ($chunks as $chunk) {
            $title = $chunk->document_title ?? ($chunk['document_title'] ?? 'Unknown');
            $content = $chunk->content ?? ($chunk['content'] ?? '');
            $page = $chunk->page_number ?? ($chunk['page_number'] ?? null);

            $sourceLabel = "[Source: {$title}]";
            $text = $page !== null
                ? "{$sourceLabel}, Page {$page}\n{$content}"
                : "{$sourceLabel}\n{$content}";

            $tokens = $this->provider->countTokens($text);

            if ($totalTokens + $tokens > $this->maxContextTokens) {
                break;
            }

            $parts[] = $text;
            $totalTokens += $tokens;
        }

        return implode("\n\n---\n\n", $parts);
    }
}
