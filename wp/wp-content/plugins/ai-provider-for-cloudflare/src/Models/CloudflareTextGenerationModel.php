<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\CloudflareAiProvider\Provider\CloudflareProvider;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception text, not browser output.

/**
 * Class for a Cloudflare Workers AI text generation model using the OpenAI-compatible Chat
 * Completions API.
 *
 * Workers AI exposes both a native run endpoint (`…/ai/run/{model}` → `{result:{response}}`) and an
 * OpenAI-compatible endpoint (`…/ai/v1/chat/completions` → `{choices:[{message}], usage}`). This model
 * uses the OpenAI-compatible endpoint so the request/response handling matches the broadly understood
 * Chat Completions schema.
 *
 * Note that not every Workers AI model supports JSON mode (`response_format`) or function calling
 * (`tools`); those parameters are only sent when configured, and unsupported models return an error
 * that surfaces to the caller. JSON mode is supported on a subset of models (the Llama 3.x instruct
 * family, Hermes 2 Pro, and DeepSeek variants).
 *
 * @since 1.0.0
 *
 * @phpstan-type ToolCallData array{
 *     id?: string,
 *     type?: string,
 *     function?: array{name?: string, arguments?: string}
 * }
 * @phpstan-type MessageData array{
 *     role?: string,
 *     content?: ?string,
 *     reasoning_content?: ?string,
 *     tool_calls?: list<ToolCallData>
 * }
 * @phpstan-type ChoiceData array{
 *     index?: int,
 *     message?: MessageData,
 *     finish_reason?: ?string
 * }
 * @phpstan-type UsageData array{
 *     prompt_tokens?: int,
 *     completion_tokens?: int,
 *     total_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     choices?: list<ChoiceData>,
 *     usage?: UsageData
 * }
 */
class CloudflareTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    /**
     * Minimum HTTP request timeout, in seconds, for vision (image input) requests.
     *
     * Vision inference is slower than plain text generation (and the request body carries the image),
     * so a longer timeout than the default is used to avoid premature timeouts.
     *
     * @since 1.0.0
     *
     * @var float
     */
    private const MIN_VISION_TIMEOUT = 60.0;

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        // Resolve the account ID and the token (the API key may carry a combined "{account_id}:{token}").
        [$accountId, $authentication] = CloudflareProvider::resolveCredentials($this->getRequestAuthentication());

        /*
         * Cloudflare has two image-input conventions. The dedicated vision models (LLaVA, Llama 3.2
         * Vision) only accept a raw image byte array on the native run endpoint — the OpenAI-compatible
         * chat endpoint rejects their images with "Unable to add image...". For those models, route an
         * image prompt to the native endpoint. Every other (multimodal chat) model — e.g. Mistral Small
         * 3.1 — takes the image as an `image_url` content part on the Chat Completions endpoint, which is
         * handled by the normal text path below.
         */
        if ($this->usesNativeVisionEndpoint()) {
            $imageBytes = $this->extractImageBytes($prompt);
            if ($imageBytes !== null) {
                return $this->generateVisionResult(
                    $prompt,
                    $imageBytes,
                    $accountId,
                    $authentication,
                    $httpTransporter
                );
            }
        }

        $params = $this->prepareGenerateTextParams($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            CloudflareProvider::aiUrl($accountId, 'v1/chat/completions'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $authentication->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Generates a result for a vision (image input) request via the native run endpoint.
     *
     * @since 1.0.0
     *
     * @param list<Message>                  $prompt          The prompt messages.
     * @param list<int>                      $imageBytes      The image as an array of byte values.
     * @param string                         $accountId       The resolved Cloudflare account ID.
     * @param RequestAuthenticationInterface $authentication  The resolved authentication.
     * @param HttpTransporterInterface       $httpTransporter The HTTP transporter.
     * @return GenerativeAiResult The generated result.
     */
    protected function generateVisionResult(
        array $prompt,
        array $imageBytes,
        string $accountId,
        RequestAuthenticationInterface $authentication,
        HttpTransporterInterface $httpTransporter
    ): GenerativeAiResult {
        $params = $this->prepareVisionParams($prompt, $imageBytes);

        $request = new Request(
            HttpMethodEnum::POST(),
            CloudflareProvider::aiUrl($accountId, 'run/' . $this->metadata()->getId()),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $params,
            $this->resolveVisionRequestOptions()
        );
        $request = $authentication->authenticateRequest($request);

        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseNativeResponseToGenerativeAiResult($response);
    }

    /**
     * Determines whether this model is a dedicated vision model that requires the native run endpoint.
     *
     * Dedicated vision models (LLaVA, Llama 3.2 Vision, uForm) only accept an image as a raw byte array
     * on `…/ai/run/{model}`; they reject the OpenAI-compatible `image_url` content part. Multimodal chat
     * models (e.g. Mistral Small 3.1) instead take images through Chat Completions and return false here.
     *
     * @since 1.0.0
     *
     * @return bool True when image input must go through the native run endpoint.
     */
    protected function usesNativeVisionEndpoint(): bool
    {
        $id = strtolower($this->metadata()->getId());
        return strpos($id, 'llava') !== false
            || strpos($id, 'vision') !== false
            || strpos($id, '-vl') !== false
            || strpos($id, 'uform') !== false;
    }

    /**
     * Extracts the first image in the prompt as an array of byte values, or null when none is present.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return list<int>|null The image bytes, or null when the prompt contains no image.
     */
    protected function extractImageBytes(array $messages): ?array
    {
        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                if (!$part->getType()->isFile()) {
                    continue;
                }
                $file = $part->getFile();
                if ($file === null || !$file->isImage()) {
                    continue;
                }
                $binary = $this->resolveImageBinary($file);
                if ($binary === null || $binary === '') {
                    continue;
                }
                $unpacked = unpack('C*', $binary);
                if ($unpacked === false) {
                    continue;
                }
                return array_values($unpacked);
            }
        }
        return null;
    }

    /**
     * Resolves the raw binary contents of an image file (inline base64, or a remote URL fetched over HTTP).
     *
     * @since 1.0.0
     *
     * @param File $file The image file.
     * @return string|null The binary contents, or null when they cannot be resolved.
     */
    protected function resolveImageBinary(File $file): ?string
    {
        $base64 = $file->getBase64Data();
        if (is_string($base64) && $base64 !== '') {
            $decoded = base64_decode($base64, true);
            return $decoded === false ? null : $decoded;
        }

        if ($file->isRemote()) {
            $url = $file->getUrl();
            if ($url !== null && function_exists('wp_remote_get') && function_exists('wp_remote_retrieve_body')) {
                $resp = wp_remote_get($url, ['timeout' => 30]);
                if (!is_wp_error($resp)) {
                    $body = wp_remote_retrieve_body($resp);
                    return $body !== '' ? $body : null;
                }
            }
        }

        return null;
    }

    /**
     * Prepares the parameters for a native vision request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt     The prompt messages.
     * @param list<int>     $imageBytes The image as an array of byte values.
     * @return array<string, mixed> The request parameters.
     */
    protected function prepareVisionParams(array $prompt, array $imageBytes): array
    {
        $config = $this->getConfig();

        $params = [
            'prompt' => $this->buildVisionPrompt($prompt),
            'image' => $imageBytes,
        ];

        $maxTokens = $config->getMaxTokens();
        // A sensible default so the description is not truncated when the caller sets no limit.
        $params['max_tokens'] = $maxTokens !== null ? $maxTokens : 512;

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        // Custom options may tune the request, but must never override the image payload.
        foreach ($config->getCustomOptions() as $key => $value) {
            if ($key === 'image' || $key === 'prompt') {
                continue;
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Builds a single prompt string for a vision request from the system instruction and text parts.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return string The combined prompt text.
     */
    protected function buildVisionPrompt(array $messages): string
    {
        $segments = [];

        $systemInstruction = $this->getConfig()->getSystemInstruction();
        if ($systemInstruction) {
            $segments[] = $systemInstruction;
        }

        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                if (!$part->getType()->isText() || $part->getChannel()->isThought()) {
                    continue;
                }
                $text = $part->getText();
                if ($text !== null && $text !== '') {
                    $segments[] = $text;
                }
            }
        }

        $prompt = trim(implode("\n\n", $segments));
        return $prompt !== '' ? $prompt : 'Describe this image.';
    }

    /**
     * Resolves request options for a vision request, ensuring a long-enough timeout.
     *
     * @since 1.0.0
     *
     * @return RequestOptions The request options.
     */
    protected function resolveVisionRequestOptions(): RequestOptions
    {
        $existing = $this->getRequestOptions();
        $options = $existing !== null ? RequestOptions::fromArray($existing->toArray()) : new RequestOptions();

        $timeout = $options->getTimeout();
        if ($timeout === null || $timeout < self::MIN_VISION_TIMEOUT) {
            $options->setTimeout(self::MIN_VISION_TIMEOUT);
        }

        return $options;
    }

    /**
     * The keys under which Workers AI native run endpoints return generated text.
     *
     * Text-generation/vision-instruct models (e.g. Llama 3.2 Vision) return `result.response`, while
     * image-to-text models (e.g. LLaVA) return `result.description`. Both are checked so either shape
     * is accepted.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    private const NATIVE_TEXT_KEYS = ['response', 'description'];

    /**
     * Parses a native run-endpoint response into a generative AI result.
     *
     * Accepts either `{result: {response}}` (text/vision-instruct models) or `{result: {description}}`
     * (image-to-text models such as LLaVA).
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the native endpoint.
     * @return GenerativeAiResult The parsed result.
     */
    protected function parseNativeResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $responseData = $response->getData();

        $result = is_array($responseData) && isset($responseData['result']) && is_array($responseData['result'])
            ? $responseData['result']
            : null;
        $text = null;
        if ($result !== null) {
            foreach (self::NATIVE_TEXT_KEYS as $key) {
                if (isset($result[$key]) && is_string($result[$key])) {
                    $text = $result[$key];
                    break;
                }
            }
        }
        if ($text === null) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                'result.' . implode('/', self::NATIVE_TEXT_KEYS)
            );
        }

        $candidate = new Candidate(
            new Message(MessageRoleEnum::model(), [new MessagePart(trim($text))]),
            FinishReasonEnum::stop()
        );

        $usage = ($result !== null && isset($result['usage']) && is_array($result['usage'])) ? $result['usage'] : null;
        if ($usage !== null) {
            $promptTokens = isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])
                ? $usage['prompt_tokens']
                : 0;
            $completionTokens = isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])
                ? $usage['completion_tokens']
                : 0;
            $totalTokens = isset($usage['total_tokens']) && is_int($usage['total_tokens'])
                ? $usage['total_tokens']
                : ($promptTokens + $completionTokens);
            $tokenUsage = new TokenUsage($promptTokens, $completionTokens, $totalTokens);
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        return new GenerativeAiResult(
            'cf-vision-' . substr(md5(uniqid('', true)), 0, 12),
            [$candidate],
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate text for. Either a single message or a list of messages
     *                              from a chat.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $messages = [];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            // Chat Completions has no dedicated field, so the system instruction is a leading message.
            $messages[] = [
                'role' => 'system',
                'content' => $systemInstruction,
            ];
        }

        $messages = array_merge($messages, $this->prepareMessagesParam($prompt));

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $messages,
            'stream' => false,
        ];

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $params['max_tokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $params['stop'] = $stopSequences;
        }

        $presencePenalty = $config->getPresencePenalty();
        if ($presencePenalty !== null) {
            $params['presence_penalty'] = $presencePenalty;
        }

        $frequencyPenalty = $config->getFrequencyPenalty();
        if ($frequencyPenalty !== null) {
            $params['frequency_penalty'] = $frequencyPenalty;
        }

        $outputMimeType = $config->getOutputMimeType();
        $outputSchema = $config->getOutputSchema();
        if ($outputMimeType === 'application/json') {
            if ($outputSchema) {
                $params['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'response_schema',
                        'schema' => $outputSchema,
                        'strict' => true,
                    ],
                ];
            } else {
                $params['response_format'] = ['type' => 'json_object'];
            }
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations);
        }

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options that may be more niche or not yet supported by the SDK.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares the messages parameter for the API request.
     *
     * A single SDK message may translate into multiple Chat Completions messages, because function
     * calls and function responses must be sent as their own messages (with roles `assistant` and
     * `tool` respectively).
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared messages parameter.
     */
    protected function prepareMessagesParam(array $messages): array
    {
        $apiMessages = [];

        foreach ($messages as $message) {
            $role = $message->getRole();
            $roleString = $this->getMessageRoleString($role);

            $contentParts = [];
            $toolCalls = [];

            foreach ($message->getParts() as $part) {
                $type = $part->getType();

                if ($type->isFunctionResponse()) {
                    $functionResponse = $part->getFunctionResponse();
                    if (!$functionResponse) {
                        // This should be impossible due to class internals, but still needs to be checked.
                        throw new RuntimeException(
                            'The function_response typed message part must contain a function response.'
                        );
                    }
                    // Function responses are their own `tool` role message.
                    $apiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $functionResponse->getId(),
                        'content' => (string) json_encode($functionResponse->getResponse()),
                    ];
                    continue;
                }

                if ($type->isFunctionCall()) {
                    $functionCall = $part->getFunctionCall();
                    if (!$functionCall) {
                        // This should be impossible due to class internals, but still needs to be checked.
                        throw new RuntimeException(
                            'The function_call typed message part must contain a function call.'
                        );
                    }
                    $toolCalls[] = [
                        'id' => $functionCall->getId(),
                        'type' => 'function',
                        'function' => [
                            'name' => $functionCall->getName(),
                            'arguments' => (string) json_encode($functionCall->getArgs() ?? new \stdClass()),
                        ],
                    ];
                    continue;
                }

                $contentPart = $this->getMessageContentPart($part);
                if ($contentPart !== null) {
                    $contentParts[] = $contentPart;
                }
            }

            // Emit the textual/visual content message, if any.
            if ($contentParts || !$toolCalls) {
                $apiMessages[] = [
                    'role' => $roleString,
                    'content' => $this->normalizeContent($contentParts),
                ];
            }

            // Emit an assistant message carrying the tool calls, if any.
            if ($toolCalls) {
                $apiMessages[] = [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => $toolCalls,
                ];
            }
        }

        return $apiMessages;
    }

    /**
     * Normalizes a list of content parts into the Chat Completions `content` value.
     *
     * When there is a single text part, a plain string is returned (the most widely supported form).
     * When images are present, the structured array form is returned.
     *
     * @since 1.0.0
     *
     * @param list<array<string, mixed>> $contentParts The content parts.
     * @return string|list<array<string, mixed>> The normalized content.
     */
    protected function normalizeContent(array $contentParts)
    {
        if (count($contentParts) === 0) {
            return '';
        }

        $allText = true;
        foreach ($contentParts as $contentPart) {
            if (($contentPart['type'] ?? '') !== 'text') {
                $allText = false;
                break;
            }
        }

        if ($allText) {
            $text = '';
            foreach ($contentParts as $contentPart) {
                $text .= $contentPart['text'];
            }
            return $text;
        }

        return $contentParts;
    }

    /**
     * Returns the Cloudflare API specific role string for the given message role.
     *
     * @since 1.0.0
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The role for the API request.
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'assistant';
        }
        return 'user';
    }

    /**
     * Returns the Chat Completions content part data for a (text or file) message part.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part to get the data for.
     * @return ?array<string, mixed> The content part data, or null to skip the part.
     * @throws InvalidArgumentException If the message part type or data is unsupported.
     */
    protected function getMessageContentPart(MessagePart $part): ?array
    {
        $type = $part->getType();

        if ($type->isText()) {
            // Internal reasoning ("thought") parts are not sent back to the API.
            if ($part->getChannel()->isThought()) {
                return null;
            }
            return [
                'type' => 'text',
                'text' => $part->getText(),
            ];
        }

        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The file typed message part must contain a file.'
                );
            }
            if (!$file->isImage()) {
                throw new InvalidArgumentException(
                    'Unsupported file type: The Cloudflare Workers AI Chat Completions API only supports image files.'
                );
            }
            if ($file->isRemote()) {
                $fileUrl = $file->getUrl();
                if (!$fileUrl) {
                    // This should be impossible due to class internals, but still needs to be checked.
                    throw new RuntimeException(
                        'The remote file must contain a URL.'
                    );
                }
                return [
                    'type' => 'image_url',
                    'image_url' => ['url' => $fileUrl],
                ];
            }
            $dataUri = $file->getDataUri();
            if (!$dataUri) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The inline file must contain base64 data.'
                );
            }
            return [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUri],
            ];
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unsupported message part type "%s".',
                $type
            )
        );
    }

    /**
     * Prepares the tools parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration> $functionDeclarations The function declarations.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];

        foreach ($functionDeclarations as $functionDeclaration) {
            $parameters = $functionDeclaration->getParameters();
            if ($parameters === null) {
                $parameters = [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ];
            }
            $tools[] = [
                'type' => 'function',
                'function' => array_filter(
                    [
                        'name' => $functionDeclaration->getName(),
                        'description' => $functionDeclaration->getDescription(),
                        'parameters' => $parameters,
                    ],
                    static function ($value) {
                        return $value !== null;
                    }
                ),
            ];
        }

        return $tools;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['choices']) || !$responseData['choices']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'choices');
        }
        if (!is_array($responseData['choices']) || !array_is_list($responseData['choices'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'choices',
                'The value must be an indexed array.'
            );
        }

        $candidates = [];
        foreach ($responseData['choices'] as $index => $choice) {
            if (!is_array($choice)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "choices[{$index}]",
                    'The value must be an associative array.'
                );
            }
            $candidate = $this->parseChoiceToCandidate($choice, (int) $index);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = $responseData['usage'];
            $promptTokens = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;
            $tokenUsage = new TokenUsage(
                $promptTokens,
                $completionTokens,
                $usage['total_tokens'] ?? ($promptTokens + $completionTokens)
            );
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        // Use any other data from the response as provider-specific response metadata.
        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['choices'], $additionalData['usage']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a single choice from the API response into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $choice The choice data from the API response.
     * @param int $index The index of the choice in the choices array.
     * @return Candidate|null The parsed candidate, or null if the choice should be skipped.
     */
    protected function parseChoiceToCandidate(array $choice, int $index): ?Candidate
    {
        $messageData = $choice['message'] ?? null;
        if (!is_array($messageData)) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "choices[{$index}].message"
            );
        }

        $parts = [];
        $hasFunctionCalls = false;

        // Reasoning content, when provided separately, is surfaced as a thought part.
        if (isset($messageData['reasoning_content']) && is_string($messageData['reasoning_content'])) {
            $reasoning = trim($messageData['reasoning_content']);
            if ($reasoning !== '') {
                $parts[] = new MessagePart($reasoning, MessagePartChannelEnum::thought());
            }
        }

        if (isset($messageData['content']) && is_string($messageData['content']) && $messageData['content'] !== '') {
            $segments = $this->splitReasoningFromContent($messageData['content']);
            if ($segments['reasoning'] !== '') {
                $parts[] = new MessagePart($segments['reasoning'], MessagePartChannelEnum::thought());
            }
            if ($segments['text'] !== '') {
                $parts[] = new MessagePart($segments['text']);
            }
        }

        if (isset($messageData['tool_calls']) && is_array($messageData['tool_calls'])) {
            foreach ($messageData['tool_calls'] as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }
                $part = $this->parseToolCallToPart($toolCall);
                if ($part !== null) {
                    $parts[] = $part;
                    $hasFunctionCalls = true;
                }
            }
        }

        if (count($parts) === 0) {
            return null;
        }

        $finishReasonString = isset($choice['finish_reason']) && is_string($choice['finish_reason'])
            ? $choice['finish_reason']
            : 'stop';
        $finishReason = $this->parseFinishReason($finishReasonString, $hasFunctionCalls);

        return new Candidate(new Message(MessageRoleEnum::model(), $parts), $finishReason);
    }

    /**
     * Splits inline `<think>...</think>` reasoning blocks from the user-visible content.
     *
     * @since 1.0.0
     *
     * @param string $content The raw content string.
     * @return array{reasoning: string, text: string} The separated reasoning and text.
     */
    protected function splitReasoningFromContent(string $content): array
    {
        $reasoning = '';

        if (strpos($content, '<think>') !== false) {
            if (preg_match_all('/<think>(.*?)<\/think>/s', $content, $matches)) {
                $reasoning = trim(implode("\n", $matches[1]));
            }
            $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $content);
        }

        return [
            'reasoning' => $reasoning,
            'text' => trim($content),
        ];
    }

    /**
     * Parses a tool call from the API response into a MessagePart.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $toolCall The tool call data.
     * @return MessagePart|null The parsed message part, or null to skip.
     */
    protected function parseToolCallToPart(array $toolCall): ?MessagePart
    {
        if (($toolCall['type'] ?? 'function') !== 'function') {
            return null;
        }

        $function = $toolCall['function'] ?? null;
        if (!is_array($function) || !isset($function['name']) || !is_string($function['name'])) {
            throw new InvalidArgumentException(
                'Tool call has an invalid function shape.'
            );
        }

        $id = isset($toolCall['id']) && is_string($toolCall['id']) ? $toolCall['id'] : '';

        /*
         * Parse and normalize function arguments. The API returns arguments as a JSON
         * string. An empty object "{}" decodes to an empty array, which semantically
         * means "no arguments" and is normalized to null.
         */
        $args = null;
        if (isset($function['arguments']) && is_string($function['arguments']) && $function['arguments'] !== '') {
            $decoded = json_decode($function['arguments'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $args = $decoded;
            }
        }

        return new MessagePart(new FunctionCall($id, $function['name'], $args));
    }

    /**
     * Parses the Chat Completions finish reason to a finish reason enum.
     *
     * @since 1.0.0
     *
     * @param string $finishReason The finish reason from the API response.
     * @param bool $hasFunctionCalls Whether the choice contains function calls.
     * @return FinishReasonEnum The finish reason.
     */
    protected function parseFinishReason(string $finishReason, bool $hasFunctionCalls): FinishReasonEnum
    {
        switch ($finishReason) {
            case 'stop':
                return $hasFunctionCalls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
            case 'length':
                return FinishReasonEnum::length();
            case 'tool_calls':
            case 'function_call':
                return FinishReasonEnum::toolCalls();
            case 'content_filter':
                return FinishReasonEnum::contentFilter();
            default:
                return $hasFunctionCalls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
        }
    }
}
