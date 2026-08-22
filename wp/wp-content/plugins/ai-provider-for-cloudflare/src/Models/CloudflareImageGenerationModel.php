<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\CloudflareAiProvider\Provider\CloudflareProvider;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception text, not browser output.

/**
 * Class for a Cloudflare Workers AI image generation model (the FLUX family).
 *
 * Image generation is not available through Cloudflare's OpenAI-compatible endpoint; it uses the
 * native run endpoint (`…/ai/run/{model}`). The two FLUX generations use different request encodings,
 * which is why each model carries a profile:
 *
 * - **FLUX.1 [schnell]** takes a JSON body of `prompt`, `steps` (default 4, max 8) and an optional
 *   `seed`. It outputs a fixed square image.
 * - **FLUX.2** (`dev`, `klein-4b`, `klein-9b`) requires a **multipart/form-data** body of `prompt`,
 *   `steps`, `width` and `height` (JSON is rejected with a "required properties are 'multipart'"
 *   error). It supports configurable dimensions.
 *
 * In both cases the response is `{"result": {"image": "<base64 jpeg>"}, "success": true}` with the
 * image bytes inline. Each request returns a single image, so multiple candidates are obtained by
 * issuing additional requests with a varied seed.
 *
 * @since 1.0.0
 *
 * @phpstan-type ImageResultData array{image?: string}
 * @phpstan-type ImageResponseData array{result?: ImageResultData}
 * @phpstan-type ModelProfile array{format: string, steps: int, steps_max: int, dimensions: bool}
 */
class CloudflareImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    /**
     * Minimum HTTP request timeout, in seconds, for image generation.
     *
     * Image generation is significantly slower than text generation (including model cold starts), so
     * a longer timeout than the typical default is required to avoid premature timeouts.
     *
     * @since 1.0.0
     *
     * @var float
     */
    private const MIN_REQUEST_TIMEOUT = 60.0;

    /**
     * Default square dimension (pixels) sent to FLUX.2 models.
     *
     * @since 1.0.0
     *
     * @var int
     */
    private const IMAGE_DIMENSION = 1024;

    /**
     * Per-model generation profiles.
     *
     * `format` is `json` (FLUX.1 schnell) or `multipart` (FLUX.2). `steps`/`steps_max` are the default
     * and maximum diffusion steps. `dimensions` indicates whether width/height are sent.
     *
     * @since 1.0.0
     *
     * @var array<string, ModelProfile>
     */
    private const MODEL_PROFILES = [
        '@cf/black-forest-labs/flux-1-schnell' => [
            'format' => 'json', 'steps' => 4, 'steps_max' => 8, 'dimensions' => false,
        ],
        '@cf/black-forest-labs/flux-2-dev' => [
            'format' => 'multipart', 'steps' => 10, 'steps_max' => 50, 'dimensions' => true,
        ],
        '@cf/black-forest-labs/flux-2-klein-4b' => [
            'format' => 'multipart', 'steps' => 6, 'steps_max' => 25, 'dimensions' => true,
        ],
        '@cf/black-forest-labs/flux-2-klein-9b' => [
            'format' => 'multipart', 'steps' => 6, 'steps_max' => 25, 'dimensions' => true,
        ],
    ];

    /**
     * Profile used when a model is not explicitly listed above.
     *
     * @since 1.0.0
     *
     * @var ModelProfile
     */
    private const DEFAULT_PROFILE = ['format' => 'json', 'steps' => 4, 'steps_max' => 8, 'dimensions' => false];

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();
        $profile = self::MODEL_PROFILES[$this->metadata()->getId()] ?? self::DEFAULT_PROFILE;
        $params = $this->prepareGenerateImageParams($prompt, $profile);

        $config = $this->getConfig();
        $candidateCount = $config->getCandidateCount();
        $iterations = (is_int($candidateCount) && $candidateCount > 0) ? $candidateCount : 1;

        // Resolve the account ID and the token (the API key may carry a combined "{account_id}:{token}").
        [$accountId, $authentication] = CloudflareProvider::resolveCredentials($this->getRequestAuthentication());

        $url = CloudflareProvider::aiUrl($accountId, 'run/' . $this->metadata()->getId());
        $requestOptions = $this->resolveRequestOptions();

        $candidates = [];
        for ($i = 0; $i < $iterations; $i++) {
            // Vary the seed across iterations so multiple candidates are not identical.
            $iterationParams = $params;
            if (isset($iterationParams['seed']) && is_numeric($iterationParams['seed'])) {
                $iterationParams['seed'] = (int) $iterationParams['seed'] + $i;
            }

            $request = $this->buildRequest($url, $iterationParams, $profile, $requestOptions);

            // Add authentication credentials to the request.
            $request = $authentication->authenticateRequest($request);

            $response = $httpTransporter->send($request);
            ResponseUtil::throwIfNotSuccessful($response);

            foreach ($this->parseResponseToCandidates($response) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return new GenerativeAiResult(
            'img-' . substr(md5(uniqid('', true)), 0, 12),
            $candidates,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Builds the HTTP request for the given parameters, encoding the body per the model profile.
     *
     * FLUX.1 schnell uses a JSON body (an array, which the SDK JSON-encodes). FLUX.2 requires
     * multipart/form-data, which is built here as a raw string body with an explicit boundary.
     *
     * @since 1.0.0
     *
     * @param string                $url            The request URL.
     * @param array<string, mixed>  $params         The generation parameters.
     * @param ModelProfile          $profile        The model profile.
     * @param RequestOptions        $requestOptions The resolved request options.
     * @return Request The prepared request.
     */
    protected function buildRequest(string $url, array $params, array $profile, RequestOptions $requestOptions): Request
    {
        if ($profile['format'] === 'multipart') {
            $boundary = '----WPCFBoundary' . bin2hex(random_bytes(8));
            $body = $this->encodeMultipart($params, $boundary);

            return new Request(
                HttpMethodEnum::POST(),
                $url,
                [
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    'Accept' => 'application/json',
                ],
                $body,
                $requestOptions
            );
        }

        return new Request(
            HttpMethodEnum::POST(),
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $params,
            $requestOptions
        );
    }

    /**
     * Encodes parameters as a multipart/form-data body string.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $fields   The form fields.
     * @param string               $boundary The multipart boundary.
     * @return string The encoded multipart body.
     */
    protected function encodeMultipart(array $fields, string $boundary): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $this->stringifyFieldValue($value) . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        return $body;
    }

    /**
     * Converts a form-field value to its string representation for multipart encoding.
     *
     * @since 1.0.0
     *
     * @param mixed $value The field value.
     * @return string The string representation.
     */
    protected function stringifyFieldValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        return (string) $value;
    }

    /**
     * Resolves the request options, ensuring a timeout long enough for image generation.
     *
     * @since 1.0.0
     *
     * @return RequestOptions The request options to use for the image generation request.
     */
    protected function resolveRequestOptions(): RequestOptions
    {
        $existing = $this->getRequestOptions();
        // Clone so the configured options object is not mutated.
        $options = $existing !== null ? RequestOptions::fromArray($existing->toArray()) : new RequestOptions();

        $timeout = $options->getTimeout();
        if ($timeout === null || $timeout < self::MIN_REQUEST_TIMEOUT) {
            $options->setTimeout(self::MIN_REQUEST_TIMEOUT);
        }

        return $options;
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt  The prompt to generate an image for.
     * @param ModelProfile  $profile The model profile.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateImageParams(array $prompt, array $profile): array
    {
        $config = $this->getConfig();

        $params = [
            'prompt' => $this->preparePromptParam($prompt),
            'steps' => $profile['steps'],
        ];

        if ($profile['dimensions']) {
            $params['width'] = self::IMAGE_DIMENSION;
            $params['height'] = self::IMAGE_DIMENSION;
        }

        /*
         * Any custom options are added to the parameters as well. This lets developers tune
         * provider-specific options (e.g. seed, negative_prompt, width/height for FLUX.2) or override
         * the defaults. The steps value is clamped to what the selected model accepts.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            $params[$key] = $value;
        }

        if (isset($params['steps']) && is_numeric($params['steps'])) {
            $params['steps'] = max(1, min((int) $params['steps'], $profile['steps_max']));
        }

        return $params;
    }

    /**
     * Extracts the prompt text from the messages.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare. The Cloudflare image API accepts a
     *                                single text prompt.
     * @return string The prepared prompt parameter.
     * @throws InvalidArgumentException If the messages do not contain a single user text prompt.
     */
    protected function preparePromptParam(array $messages): string
    {
        if (count($messages) !== 1) {
            throw new InvalidArgumentException(
                'The Cloudflare image generation API requires a single user message as prompt.'
            );
        }
        $message = $messages[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'The Cloudflare image generation API requires a user message as prompt.'
            );
        }

        $text = null;
        foreach ($message->getParts() as $part) {
            $partText = $part->getText();
            if ($partText !== null) {
                $text = $partText;
                break;
            }
        }

        if ($text === null) {
            throw new InvalidArgumentException(
                'The Cloudflare image generation API requires a single text message part as prompt.'
            );
        }

        return $text;
    }

    /**
     * Parses the API response into a list of candidates.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return list<Candidate> The parsed candidates.
     */
    protected function parseResponseToCandidates(Response $response): array
    {
        /** @var ImageResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['result']) || !is_array($responseData['result'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'result');
        }

        $result = $responseData['result'];
        if (!isset($result['image']) || !is_string($result['image']) || $result['image'] === '') {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'result.image');
        }

        $base64 = $result['image'];
        $imageFile = new File($base64, $this->detectMimeType($base64));

        return [
            new Candidate(
                new Message(MessageRoleEnum::model(), [new MessagePart($imageFile)]),
                FinishReasonEnum::stop()
            ),
        ];
    }

    /**
     * Detects the image MIME type from the leading characters of its base64 representation.
     *
     * @since 1.0.0
     *
     * @param string $base64 The base64-encoded image data.
     * @return string The detected MIME type, defaulting to image/jpeg.
     */
    protected function detectMimeType(string $base64): string
    {
        if (strncmp($base64, 'iVBORw0KGg', 10) === 0) {
            return 'image/png';
        }
        if (strncmp($base64, 'R0lGOD', 6) === 0) {
            return 'image/gif';
        }
        if (strncmp($base64, 'UklGR', 5) === 0) {
            return 'image/webp';
        }
        return 'image/jpeg';
    }
}
