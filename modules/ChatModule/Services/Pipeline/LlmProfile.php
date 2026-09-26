<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Modules\ChatModule\Services\SemanticCacheService;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Models\AiModel;

/**
 * The LLM answering one request, with its generation parameters.
 *
 * Everything that depends on "which model" — the client, temperature,
 * max_tokens, think, num_ctx and the semantic-cache hash — is resolved here
 * once, so a per-request model override cannot apply only halfway.
 */
final class LlmProfile
{
    public function __construct(
        public readonly LLMServiceInterface $llm,
        public readonly float $temperature,
        public readonly int $maxTokens,
        public readonly ?bool $think,
        public readonly int $numCtx,
        public readonly string $cacheHash,
    ) {}

    /**
     * Build a profile for a model (or the config defaults when null).
     *
     * @param  LLMServiceInterface  $llm  Client for this model
     * @param  AiModel|null  $model  The model record, null when unconfigured
     * @param  int  $maxTokens  Resolved max generation tokens. Example: 4096
     * @param  bool|null  $think  Resolved think flag, null to let the provider decide
     */
    public static function for(LLMServiceInterface $llm, ?AiModel $model, int $maxTokens, ?bool $think): self
    {
        $temperature = AiModel::resolveTemperature($model);

        return new self(
            llm: $llm,
            temperature: $temperature,
            maxTokens: $maxTokens,
            think: $think,
            numCtx: $model?->maxContextTokens() ?? (int) config('rag.llm.max_context_tokens', 32768),
            cacheHash: SemanticCacheService::modelHash(AiModel::resolveModelName($model, 'llm'), $temperature, $maxTokens),
        );
    }

    /**
     * Provider options for a call with this profile.
     *
     * A request-level `think` wins over the model's setting; when neither is
     * set the provider auto-detects (e.g. off for Qwen reasoning models).
     *
     * @param  array  $requestOptions  Pipeline request options. Example: ["think" => true]
     * @param  int|null  $maxTokens  Override max tokens (e.g. 500 for query expansion). Example: 500
     * @return array Example: ["temperature" => 0.3, "max_tokens" => 4096, "num_ctx" => 32768, "think" => false]
     */
    public function options(array $requestOptions = [], ?int $maxTokens = null): array
    {
        $options = [
            'temperature' => $this->temperature,
            'max_tokens' => $maxTokens ?? $this->maxTokens,
            'num_ctx' => $this->numCtx,
        ];

        $think = isset($requestOptions['think']) ? (bool) $requestOptions['think'] : $this->think;
        if ($think !== null) {
            $options['think'] = $think;
        }

        return $options;
    }
}
