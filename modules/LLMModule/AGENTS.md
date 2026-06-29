# LLM Module

## Overview
Manages communication with Large Language Model APIs. Provides a provider-agnostic interface for text completion and streaming responses. Handles prompt construction, context injection, and response parsing.

## Responsibility Boundaries

### This Module OWNS:
- LLM provider abstraction (OpenAI, Ollama, Gemini, Claude, DeepSeek)
- Prompt template rendering
- Text completion (streaming and non-streaming)
- Response parsing and token counting
- Provider configuration and failover

### This Module DOES NOT OWN:
- Question processing or session management (→ ChatModule)
- Context retrieval (→ VectorStoreModule)
- Response storage (→ ChatModule)

## Service Contract

### LLMProviderInterface
```php
interface LLMProviderInterface
{
    public function complete(string $systemPrompt, string $userPrompt, array $options): LLMResponse;
    public function completeStream(string $systemPrompt, string $userPrompt, array $options): Generator;
    public function getModelName(): string;
    public function countTokens(string $text): int;
}
```

### LLMResponse Object
```php
class LLMResponse
{
    public string $content;
    public int $promptTokens;
    public int $completionTokens;
    public int $totalTokens;
    public string $model;
    public ?string $finishReason;
}
```

### LLMService
Orchestrates provider calls with prompt management.

**Method: complete(string $systemPrompt, string $userPrompt, array $context, array $options = []): LLMResponse**

**Method: completeStream(string $systemPrompt, string $userPrompt, array $context, array $options = []): Generator**

## Supported Providers

### OpenAI
- Models: gpt-4o (default)
- Streaming: Native SSE support via raw curl
- Token limit: 128K
- Temperature: dynamic from AiModel column (fallback 0.3)

### Ollama
- Models: qwen3.5:9b, gemma4:e4b, qwen2.5-coder (local)
- Streaming: SSE via raw curl
- Token limit: depends on model; `num_ctx` from AiModel `max_context_tokens`
- `think` param: auto-disabled for Qwen reasoning models unless overridden
- Base URL: http://localhost:11434

### Gemini
- Models: gemini-2.5-flash (default)
- Streaming: SSE via raw curl
- Token limit: 1M
- Base URL: https://generativelanguage.googleapis.com/v1beta

### Claude
- Models: claude-sonnet-4-5-20250929 (default)
- Streaming: SSE via raw curl
- Token limit: 200K
- Base URL: https://api.anthropic.com/v1

### DeepSeek
- Models: deepseek-chat (default)
- Streaming: SSE via raw curl
- Token limit: 128K
- Base URL: https://api.deepseek.com/v1

## Prompt Template System

### System Prompt Template
```
You are a helpful AI assistant. Answer the user's question based ONLY on the provided context.

Rules:
1. If the context contains the answer, provide it clearly and concisely.
2. If the context does NOT contain enough information, say exactly: "I cannot answer this based on the available documents."
3. Do not use any knowledge outside the provided context.
4. Cite sources when possible by referencing the document title.
```

### User Prompt Template
```
Context:
---
{context}
---

Question: {question}

Answer:
```

### Template Variables
- `{context}`: Concatenated document chunks with source labels
- `{question}`: User's original question

### Template Rendering
- Context is truncated if exceeding `max_context_tokens` (default: 4000)
- Chunks are separated with clear visual breaks
- Source labels included: `[Source: Document Title, Page X]`

## Response Handling

### Normal Responses
```php
// Success
$response = $llmService->complete($systemPrompt, $userPrompt, $context);
$answer = $response->content;
$tokens = $response->totalTokens;
```

### Streaming Responses
```
event: chunk
data: {"content": "The revenue"}

event: chunk
data: {"content": " in Q3 was"}

event: chunk
data: {"content": " $45.2 million."}

event: done
data: {"finish_reason": "stop", "total_tokens": 150}
```

### Error Responses
- Timeout: dynamic from AiModel `timeout` column (default 60s)
- Rate limit → Retry with backoff
- Content filter → Safe fallback message
- Context too long → Automatic truncation

## Context Window Management

### Token Budget Allocation
- System prompt: ~100 tokens
- Context: Up to AiModel `max_context_tokens` (default 4000); Ollama receives `num_ctx` from this value
- Question: Up to 500 tokens
- Response: Up to AiModel `max_tokens` (default 4096)
- Total budget: varies per model

### Context Truncation Strategy
1. Sort chunks by similarity score (highest first)
2. Add chunks until approaching token limit
3. Stop adding when next chunk would exceed budget
4. Note truncation in response metadata

## Performance & Cost Optimization

### Token Efficiency
- Concise system prompt
- Chunk content stored efficiently (no redundant headers)
- Cache repeated identical prompts

### Cost Tracking
- Log token usage per request
- Track costs by model and provider
- Alerts on unusual usage spikes

### Latency Targets
- Non-streaming: < 5 seconds
- First stream token: < 1 second
- Complete stream: < 10 seconds

## Error Handling & Fallback

### Retry Strategy
1. Network error → Retry immediately (3 attempts)
2. Rate limit → Retry after Retry-After header delay
3. Server error (5xx) → Exponential backoff
4. Timeout → Fail with partial response if streamed

### Fallback Chain
1. Primary provider (per AiModel registry)
2. If unavailable, return error to ChatModule (no automatic cross-provider fallback)

### Graceful Degradation
- If streaming fails → Fall back to non-streaming response
- If context too large → Truncate and note in response
- If model unavailable → Clear error message to user

### Performance Considerations

---

## Code Documentation Standards

All classes and methods must include comprehensive PHPDoc blocks.

### Requirements:
1.  **Title & Detailed Description**: Clear explanation of purpose.
2.  **Parameters**: `@param {type} $name Description. Example: {example}`.
3.  **Return Type**: `@return {type} Description. Example: {example}`.
4.  **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.

---

## Testing Strategy

### Unit Tests
- Prompt template rendering → Correct substitution
- Context truncation → Stays within token budget
- Response parsing → Correct tokens extraction
- Provider switching → Correct model selection

### Integration Tests
- Real API call → Successful response
- Streaming → Chunks received correctly
- Error handling → Timeout produces error response
- Token counting → Accurate count for billing