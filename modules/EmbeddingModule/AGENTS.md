# Embedding Module

## Overview
Converts text into dense vector representations. Provides a provider-agnostic interface supporting multiple embedding services with built-in caching and batch processing optimization.

## Responsibility Boundaries

### This Module OWNS:
- Text-to-vector conversion
- Embedding provider abstraction (OpenAI, Voyage AI, etc.)
- Request batching and optimization
- Embedding caching
- Provider configuration management

### This Module DOES NOT OWN:
- Text extraction or chunking (→ DocumentModule)
- Vector storage or search (→ VectorStoreModule)
- LLM interactions (→ LLMModule)

## Service Contract

### EmbeddingProviderInterface
```php
interface EmbeddingProviderInterface
{
    public function embed(string $text): array;           // Single text → vector
    public function embedBatch(array $texts): array;      // Multiple texts → vectors
    public function getDimensions(): int;                  // Vector dimensions
    public function getModelName(): string;                // Model identifier
}
```

### EmbeddingService

**Method: embed(string $text): array**
1. Generate MD5 hash of input text
2. Check cache for hash (TTL: 24 hours)
3. If cache miss:
   - Call provider's embed() method
   - Store result in cache with hash key
4. Return vector as float array

**Method: embedBatch(array $texts): array**
1. Filter out cached texts (by hash)
2. Send only uncached texts to provider
3. Merge cached and fresh results
4. Return vectors in original order
5. Cache new results

**Return Format**: Array of float arrays (1536 dimensions for OpenAI ada-002)

## Supported Providers

### OpenAI
- Model: text-embedding-ada-002
- Dimensions: 1536
- Batch limit: 100 texts per request
- Authentication: API key

### Voyage AI
- Model: voyage-2 (configurable)
- Dimensions: 1024
- Batch limit: 128 texts per request
- Authentication: API key

### Provider Configuration
Configured in `config/rag.php`:
```php
'embedding' => [
    'provider' => 'openai',  // or 'voyage'
    'model' => 'text-embedding-ada-002',
    'dimensions' => 1536,
    'batch_size' => 100,
    'cache_ttl' => 86400,    // 24 hours
]
```

## Caching Strategy

### Cache Key Generation
- Input: Text string
- Method: MD5 hash
- Prefix: `embedding:{provider}:{model}:{hash}`
- Example: `embedding:openai:ada-002:a1b2c3d4e5`

### Cache Implementation
- Storage: Laravel cache (Redis in production, file in dev)
- TTL: 24 hours
- Invalidation: Automatic expiry only (no manual purge)

### Cache Benefits
- Identical chunk texts → no repeat API calls
- Repeated user questions → instant response
- Reduced API costs for common queries

## Error Handling

### API Failures
- Timeout: 30 seconds per request
- Retry: 3 attempts with exponential backoff
- Circuit breaker: Pause requests for 60s after 5 consecutive failures

### Rate Limiting
- Respect API rate limits (OpenAI: 3000 RPM)
- Implement token bucket rate limiter
- Queue excess requests with delay

### Invalid Responses
- Empty vector → throw exception
- Wrong dimensions → throw exception
- Non-200 response → retry or throw

## Performance Considerations

### Batch Processing
- Group single embeds into batches where possible
- Maximum batch size: 100 (OpenAI limit)
- Parallel batch processing when queue workers > 1

### Memory Usage
- Each vector (1536 floats) ≈ 12KB
- 100K vectors ≈ 1.2GB
- Stream to VectorStoreModule, don't accumulate

### Cost Optimization
- Cache eliminates ~30% of API calls (estimated)
- Batch processing reduces API call count
- Avoid re-embedding unchanged documents

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
- embed() with text → Returns correct length vector (1536)
- embed() with same text twice → Second call uses cache
- embedBatch() with 50 texts → Returns 50 vectors in order
- Provider switching → Same text, similar vectors (different models)

### Integration Tests
- Real API call with small text → Successful response
- Cache persistence across requests → Same hash, no API call
- Batch size validation → Error when exceeding limit