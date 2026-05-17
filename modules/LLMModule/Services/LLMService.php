<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;

/**
 * LLM Service
 *
 * Orchestrates LLM completion calls by assembling prompts from user questions
 * and document context chunks. Delegates the actual API call to the injected
 * LLMProviderInterface implementation.
 *
 * Handles context assembly with automatic token budgeting — chunks are added
 * in order until the maxContextTokens limit is reached, each annotated with
 * source labels (document title, page number, similarity score).
 *
 * Supports both synchronous (complete) and streaming (completeStream) modes.
 *
 * @param LLMProviderInterface $provider The underlying LLM provider. Example: mock(LLMProviderInterface::class)
 * @param int $maxContextTokens Maximum tokens for the assembled context. Example: 4000
 *
 * @throws \RuntimeException Propagated from provider on API failures
 */
class LLMService implements LLMServiceInterface
{
    /** @var LLMProviderInterface The underlying LLM provider */
    private LLMProviderInterface $provider;

    /** @var int Maximum tokens allowed for context assembly */
    private int $maxContextTokens;

    /**
     * @param  LLMProviderInterface  $provider  The LLM provider for completions. Example: new OpenAILLMProvider(...)
     * @param  int  $maxContextTokens  Max context tokens before truncation. Example: 4000
     */
    public function __construct(
        LLMProviderInterface $provider,
        int $maxContextTokens = 4000,
    ) {
        $this->provider = $provider;
        $this->maxContextTokens = $maxContextTokens;
    }

    /**
     * Perform a synchronous LLM completion.
     *
     * Assembles the context chunks into a structured prompt and sends it
     * to the provider for completion.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant. Answer based ONLY on the provided context."
     * @param  string  $userPrompt  The user's question. Example: "What is Project Orion?"
     * @param  array  $context  Array of chunk objects. Example: [["content" => "...", "document_title" => "Report.pdf", "page_number" => 5]]
     * @param  array  $options  Provider options. Example: ["temperature" => 0.3, "max_tokens" => 4096]
     * @return LLMResponseInterface Response with content and token usage. Example: LLMResponse with content "Project Orion is..."
     *
     * @throws \RuntimeException When the provider returns an error
     */
    public function complete(string $systemPrompt, string $userPrompt, array $context, array $options = []): LLMResponseInterface
    {
        $assembledPrompt = $this->assemblePrompt($userPrompt, $context);

        return $this->provider->complete($systemPrompt, $assembledPrompt, $options);
    }

    /**
     * Perform a streaming LLM completion.
     *
     * Assembles context and returns a Generator yielding content chunks
     * as they arrive from the provider.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant..."
     * @param  string  $userPrompt  The user's question. Example: "What is Project Orion?"
     * @param  array  $context  Array of chunk objects. Example: [["content" => "...", "document_title" => "Report.pdf"]]
     * @param  array  $options  Provider options. Example: ["temperature" => 0.3]
     * @return Generator Yields strings of content tokens. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When the provider returns an error during streaming
     */
    public function completeStream(string $systemPrompt, string $userPrompt, array $context, array $options = []): Generator
    {
        $assembledPrompt = $this->assemblePrompt($userPrompt, $context);

        return $this->provider->completeStream($systemPrompt, $assembledPrompt, $options);
    }

    /**
     * Count tokens in a text string via the provider's estimation.
     *
     * @param  string  $text  Input text. Example: "This is a sample sentence."
     * @return int Estimated token count. Example: 8
     */
    public function countTokens(string $text): int
    {
        return $this->provider->countTokens($text);
    }

    /**
     * Assemble context chunks and user prompt into a single prompt string.
     *
     * Format: "Context:\n---\n{context}\n---\n\nQuestion: {userPrompt}\n\nAnswer:"
     *
     * @param  string  $userPrompt  The user's question. Example: "What is Project Orion?"
     * @param  array  $context  Array of chunk objects with content, document_title, etc.
     * @return string The assembled prompt string ready for the provider
     */
    private function assemblePrompt(string $userPrompt, array $context): string
    {
        $contextStr = $this->buildContextString($context);

        return "Context:\n---\n{$contextStr}\n---\n\nQuestion: {$userPrompt}\n\nAnswer:";
    }

    /**
     * Build a formatted context string from chunks.
     *
     * Each chunk is prefixed with a source label. Chunks are added until
     * maxContextTokens is exceeded. Token counting uses the provider's
     * countTokens method.
     *
     * @param  array  $chunks  Array of chunk objects. Example: [["content" => "...", "document_title" => "Report.pdf", "page_number" => 5, "similarity_score" => 0.92]]
     * @return string Concatenated chunk strings with source labels. Example: "[Source: Report.pdf, Page 5]\nContent here..."
     */
    private function buildContextString(array $chunks): string
    {
        $parts = [];
        $totalTokens = 0;

        foreach ($chunks as $chunk) {
            $title = $chunk->document_title ?? 'Unknown';
            $content = $chunk->content ?? '';
            $page = $chunk->page_number ?? null;
            $score = $chunk->similarity_score ?? null;

            $scoreLabel = $score !== null ? ' ('.round((float) $score * 100).'%)' : '';
            $sourceLabel = "[Source: {$title}{$scoreLabel}]";
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
