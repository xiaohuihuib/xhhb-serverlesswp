<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiProvider\Metadata;

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\CloudflareAiProvider\Provider\CloudflareProvider;

/**
 * Class for the Cloudflare Workers AI model metadata directory.
 *
 * Cloudflare's model catalog is account-accurate: the `…/ai/models/search` endpoint returns the
 * models that the account can actually call, grouped by task. This differs from providers whose
 * catalog lists models that are not provisioned, so no hard allowlist is needed for text models —
 * every "Text Generation" model is surfaced directly from the live catalog (which doubles as the
 * provider availability / API-token check).
 *
 * Image generation is the exception. The catalog lists many "Text-to-Image" models, but they do not
 * share one response schema: the FLUX family returns base64 JSON (`{result:{image}}`), while the
 * classic Stable Diffusion variants stream raw image bytes. Only models whose response this plugin
 * can decode are surfaced, via a small allowlist intersected with the live catalog.
 *
 * The OpenAI-compatible model listing path used by the parent (`models`) is rewritten to Cloudflare's
 * `models/search` endpoint in {@see CloudflareModelMetadataDirectory::createRequest()}.
 *
 * @since 1.0.0
 *
 * @phpstan-type TaskData array{name?: string}
 * @phpstan-type ModelData array{name?: string, description?: string, task?: TaskData|string}
 * @phpstan-type ModelsResponseData array{result?: list<ModelData>}
 */
class CloudflareModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Image generation models that return base64 JSON this plugin can decode.
     *
     * Keys are model IDs; only models present in both this list and the live catalog are surfaced.
     * Classic Stable Diffusion variants are intentionally excluded because they stream raw image
     * bytes rather than base64 JSON.
     *
     * @since 1.0.0
     *
     * @var array<string, bool>
     */
    private const IMAGE_MODELS = [
        '@cf/black-forest-labs/flux-1-schnell' => true,
        '@cf/black-forest-labs/flux-2-dev' => true,
        '@cf/black-forest-labs/flux-2-klein-4b' => true,
        '@cf/black-forest-labs/flux-2-klein-9b' => true,
    ];

    /**
     * Multimodal chat models that accept image input via the OpenAI-compatible Chat Completions API.
     *
     * Unlike the dedicated vision models (LLaVA, Llama 3.2 Vision), which only accept a raw image byte
     * array on the native run endpoint, these models take images as `image_url` content parts on the
     * standard Chat Completions endpoint — the same path used for text — so they reuse the full chat
     * pipeline (system instruction, temperature, etc.).
     *
     * `mistral-small-3.1-24b-instruct` is the preferred vision model for image-understanding abilities
     * such as alt-text generation: it is NOT license-gated (works for every account with no per-account
     * model agreement) and, unlike the dedicated vision models, reliably follows the structured
     * accessibility instruction — returning real alt text for informative images and correctly
     * detecting decorative ones (both verified live against Cloudflare Workers AI).
     *
     * @since 1.0.0
     *
     * @var array<string, bool>
     */
    private const CHAT_VISION_MODELS = [
        '@cf/mistralai/mistral-small-3.1-24b-instruct' => true,
    ];

    /**
     * Default text-generation model.
     *
     * This is the first entry of {@see self::TEXT_MODEL_PRIORITY} and the model the plugin advertises
     * to the WordPress AI plugin's preferred-text-model list, so AI features auto-use Cloudflare once
     * credentials are configured. It favors a fast, reliable, free-tier-friendly model that also
     * supports JSON output and function calling.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_TEXT_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

    /**
     * Preferred ordering for well-known text models.
     *
     * Models listed here are surfaced first, in this order (the first entry is what the WordPress AI
     * plugin auto-selects when no specific model is requested). The default favors a fast, reliable,
     * free-tier-friendly model that also supports JSON output and function calling. Any other text
     * models from the catalog follow, sorted alphabetically.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    private const TEXT_MODEL_PRIORITY = [
        self::DEFAULT_TEXT_MODEL,
        '@cf/meta/llama-3.1-8b-instruct-fp8',
        '@cf/meta/llama-4-scout-17b-16e-instruct',
        '@cf/meta/llama-3.2-3b-instruct',
        '@cf/meta/llama-3.2-1b-instruct',
        '@cf/mistralai/mistral-small-3.1-24b-instruct',
        '@cf/qwen/qwen2.5-coder-32b-instruct',
        '@cf/qwen/qwq-32b',
        '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b',
        '@cf/openai/gpt-oss-120b',
        '@cf/google/gemma-4-26b-a4b-it',
    ];

    /**
     * {@inheritDoc}
     *
     * Overridden so the listing request targets Cloudflare's `models/search` endpoint (with the
     * account ID resolved from the combined API key or the constant) and is authenticated with the
     * token parsed out of that key.
     *
     * @since 1.0.0
     *
     * @return array<string, ModelMetadata> Map of model ID to model metadata.
     */
    protected function sendListModelsRequest(): array
    {
        // Resolve the account ID and the token (the API key may carry a combined "{account_id}:{token}").
        [$accountId, $authentication] = CloudflareProvider::resolveCredentials($this->getRequestAuthentication());

        $request = new Request(
            HttpMethodEnum::GET(),
            CloudflareProvider::aiUrl($accountId, 'models/search?per_page=200'),
            ['Accept' => 'application/json']
        );
        $request = $authentication->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);

        $modelMetadataMap = [];
        foreach ($this->parseResponseToModelMetadataList($response) as $modelMetadata) {
            $modelMetadataMap[$modelMetadata->getId()] = $modelMetadata;
        }
        return $modelMetadataMap;
    }

    /**
     * {@inheritDoc}
     *
     * Not used directly (the listing flow is handled by {@see sendListModelsRequest()}); implemented
     * to satisfy the abstract contract. Builds a request against the resolved account base URL.
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        if ($path === 'models') {
            $path = 'models/search?per_page=200';
        }

        return new Request(
            $method,
            CloudflareProvider::aiUrl(CloudflareProvider::configuredAccountId(), $path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['result']) || !is_array($responseData['result'])) {
            throw ResponseException::fromMissingData('Cloudflare Workers AI', 'result');
        }

        $chatCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $textOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];
        $visionOptions = array_merge(
            array_slice($textOptions, 0, -2),
            [
                new SupportedOption(
                    OptionEnum::inputModalities(),
                    [
                        [ModalityEnum::text()],
                        [ModalityEnum::text(), ModalityEnum::image()],
                    ]
                ),
                new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            ]
        );

        // Image generation (FLUX) capabilities and options. FLUX schnell outputs a fixed square JPEG.
        $imageCapabilities = [
            CapabilityEnum::imageGeneration(),
        ];
        $imageOptions = [
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/jpeg']),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [MediaOrientationEnum::square()]),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1']),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
        ];

        $textModels = [];
        $imageModels = [];

        foreach ($responseData['result'] as $modelData) {
            if (!is_array($modelData) || !isset($modelData['name']) || !is_string($modelData['name'])) {
                continue;
            }
            $modelId = $modelData['name'];
            $task = $this->extractTaskName($modelData['task'] ?? null);

            if ($this->isImageTask($task)) {
                if (!isset(self::IMAGE_MODELS[$modelId])) {
                    continue;
                }
                $imageModels[$modelId] = new ModelMetadata(
                    $modelId,
                    $modelId,
                    $imageCapabilities,
                    $imageOptions
                );
                continue;
            }

            if ($this->isTextTask($task)) {
                $isVision = $this->isVisionModel($modelId);
                $textModels[$modelId] = new ModelMetadata(
                    $modelId,
                    $modelId, // The Cloudflare catalog does not provide a separate display name.
                    $chatCapabilities,
                    $isVision ? $visionOptions : $textOptions
                );
            }
        }

        return $this->orderModels($textModels, $imageModels);
    }

    /**
     * Normalizes the `task` field of a catalog entry into a task name string.
     *
     * The catalog reports the task either as an object (`{"name": "Text Generation"}`) or, in some
     * responses, as a bare string. Both forms are handled.
     *
     * @since 1.0.0
     *
     * @param TaskData|string|null $task The raw task value from the catalog entry.
     * @return string The lowercased task name, or an empty string when unavailable.
     */
    protected function extractTaskName($task): string
    {
        if (is_string($task)) {
            return strtolower($task);
        }
        if (is_array($task) && isset($task['name']) && is_string($task['name'])) {
            return strtolower($task['name']);
        }
        return '';
    }

    /**
     * Determines whether a task name represents text generation.
     *
     * @since 1.0.0
     *
     * @param string $task The lowercased task name.
     * @return bool True for text-generation models.
     */
    protected function isTextTask(string $task): bool
    {
        return strpos($task, 'text generation') !== false;
    }

    /**
     * Determines whether a task name represents image generation.
     *
     * @since 1.0.0
     *
     * @param string $task The lowercased task name.
     * @return bool True for text-to-image models.
     */
    protected function isImageTask(string $task): bool
    {
        return strpos($task, 'text-to-image') !== false || strpos($task, 'image') !== false;
    }

    /**
     * Determines whether a chat model additionally accepts image input.
     *
     * Covers both the dedicated vision models (matched by name) and the multimodal chat models that
     * take images via `image_url` on the Chat Completions endpoint (the {@see self::CHAT_VISION_MODELS}
     * allowlist).
     *
     * @since 1.0.0
     *
     * @param string $modelId The model ID.
     * @return bool True for vision-capable chat models.
     */
    protected function isVisionModel(string $modelId): bool
    {
        if (isset(self::CHAT_VISION_MODELS[$modelId])) {
            return true;
        }
        $id = strtolower($modelId);
        return strpos($id, 'vision') !== false
            || strpos($id, '-vl') !== false
            || strpos($id, 'llava') !== false;
    }

    /**
     * Orders the collected models: preferred text models first, then the remaining text models
     * alphabetically, then image models.
     *
     * @since 1.0.0
     *
     * @param array<string, ModelMetadata> $textModels Map of text model ID to metadata.
     * @param array<string, ModelMetadata> $imageModels Map of image model ID to metadata.
     * @return list<ModelMetadata> The ordered model metadata list.
     */
    protected function orderModels(array $textModels, array $imageModels): array
    {
        $ordered = [];

        // Preferred text models first, in the configured order.
        foreach (self::TEXT_MODEL_PRIORITY as $modelId) {
            if (isset($textModels[$modelId])) {
                $ordered[] = $textModels[$modelId];
                unset($textModels[$modelId]);
            }
        }

        // Remaining text models, alphabetically.
        ksort($textModels);
        foreach ($textModels as $modelMetadata) {
            $ordered[] = $modelMetadata;
        }

        // Image models last.
        ksort($imageModels);
        foreach ($imageModels as $modelMetadata) {
            $ordered[] = $modelMetadata;
        }

        return $ordered;
    }
}
