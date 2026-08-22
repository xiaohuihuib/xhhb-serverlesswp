<?php

declare(strict_types=1);

namespace WordPress\CloudflareAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\CloudflareAiProvider\Metadata\CloudflareModelMetadataDirectory;
use WordPress\CloudflareAiProvider\Models\CloudflareImageGenerationModel;
use WordPress\CloudflareAiProvider\Models\CloudflareTextGenerationModel;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception text, not browser output.

/**
 * Class for the Cloudflare Workers AI provider.
 *
 * Unlike most providers, Cloudflare's API base URL embeds the account ID, so this provider needs two
 * pieces of configuration: the API token and the account ID. Because a Workers AI token cannot reveal
 * its own account ID, the account ID is supplied by the user. It can be provided in either of two
 * ways, checked in this order:
 *
 * 1. **Combined into the API key** as `{account_id}:{token}` (recommended; works with the single
 *    API-key field on the AI connector screen, so no wp-config editing is needed). The account ID is
 *    a 32-character hex string, which makes the split unambiguous.
 * 2. **As the `CLOUDFLARE_ACCOUNT_ID` constant/environment variable**, with the API key holding only
 *    the token.
 *
 * The base URL is therefore built per request from the resolved account ID.
 *
 * @since 1.0.0
 */
class CloudflareProvider extends AbstractApiProvider
{
    /**
     * Name of the constant/environment variable holding the Cloudflare account ID.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACCOUNT_ID_VAR = 'CLOUDFLARE_ACCOUNT_ID';

    /**
     * Root of the Cloudflare API.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * {@inheritDoc}
     *
     * The Workers AI routes all hang off `…/accounts/{id}/ai`. When the account ID is supplied via the
     * combined API key rather than the constant, this static base URL (built from the constant only)
     * is not used to issue requests; {@see CloudflareProvider::aiUrl()} is used instead with the
     * per-request resolved account ID.
     *
     * @since 1.0.0
     */
    protected static function baseUrl(): string
    {
        return self::aiUrl(self::configuredAccountId());
    }

    /**
     * Builds a Workers AI URL for the given account ID and path.
     *
     * @since 1.0.0
     *
     * @param string $accountId The Cloudflare account ID.
     * @param string $path      The path relative to `…/ai`. Default empty string.
     * @return string The full URL.
     */
    public static function aiUrl(string $accountId, string $path = ''): string
    {
        $base = self::API_BASE . '/accounts/' . rawurlencode($accountId) . '/ai';
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Resolves the account ID configured via the constant/environment variable.
     *
     * @since 1.0.0
     *
     * @return string The account ID, or an empty string when it has not been configured this way.
     */
    public static function configuredAccountId(): string
    {
        if (defined(self::ACCOUNT_ID_VAR)) {
            $value = constant(self::ACCOUNT_ID_VAR);
            return is_string($value) ? trim($value) : '';
        }

        $env = getenv(self::ACCOUNT_ID_VAR);
        return is_string($env) ? trim($env) : '';
    }

    /**
     * Splits a raw credential value into an account ID and a token.
     *
     * Accepts the combined `{account_id}:{token}` form (a 32-character hex account ID followed by a
     * colon and the token). When the value is not in that form, it is treated as a bare token and the
     * account ID falls back to the constant/environment variable.
     *
     * @since 1.0.0
     *
     * @param string $raw The raw credential value.
     * @return array{0: string, 1: string} A tuple of [account ID, token].
     */
    public static function parseCredential(string $raw): array
    {
        $raw = trim($raw);

        $pos = strpos($raw, ':');
        if ($pos !== false) {
            $left = substr($raw, 0, $pos);
            $right = substr($raw, $pos + 1);
            if ($right !== '' && preg_match('/^[0-9a-f]{32}$/i', $left) === 1) {
                return [$left, $right];
            }
        }

        return [self::configuredAccountId(), $raw];
    }

    /**
     * Resolves the account ID and the authentication to use for a request.
     *
     * When the API key carries a combined `{account_id}:{token}` value, a fresh authentication using
     * only the token is returned (so the Authorization header is correct), along with the parsed
     * account ID. Otherwise the original authentication is returned unchanged.
     *
     * @since 1.0.0
     *
     * @param RequestAuthenticationInterface $auth The authentication configured by the SDK.
     * @return array{0: string, 1: RequestAuthenticationInterface} A tuple of [account ID, authentication].
     */
    public static function resolveCredentials(RequestAuthenticationInterface $auth): array
    {
        if (!$auth instanceof ApiKeyRequestAuthentication) {
            return [self::configuredAccountId(), $auth];
        }

        $rawKey = $auth->getApiKey();
        [$accountId, $token] = self::parseCredential($rawKey);

        // Nothing was split off the key; reuse the original authentication object.
        if ($token === trim($rawKey)) {
            return [$accountId, $auth];
        }

        return [$accountId, new ApiKeyRequestAuthentication($token)];
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new CloudflareTextGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isImageGeneration()) {
                return new CloudflareImageGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'cloudflare',
            'Cloudflare Workers AI',
            ProviderTypeEnum::cloud(),
            'https://dash.cloudflare.com/?to=/:account/ai/workers-ai',
            RequestAuthenticationMethod::apiKey()
        ];
        // Provider description support was added in 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            // For WordPress, we should translate the description.
            if (function_exists('__')) {
                // phpcs:ignore Generic.Files.LineLength.TooLong
                $providerMetadataArgs[] = __('Text and image generation with Cloudflare Workers AI (Llama, Mistral, Qwen, FLUX). Enter your API key as account_id:token (your Cloudflare account ID and API token joined by a colon).', 'ai-provider-for-cloudflare');
            } else {
                // phpcs:ignore Generic.Files.LineLength.TooLong -- Description literal, kept in sync with the translated string above.
                $providerMetadataArgs[] = 'Text and image generation with Cloudflare Workers AI (Llama, Mistral, Qwen, FLUX). Enter your API key as account_id:token (your Cloudflare account ID and API token joined by a colon).';
            }
        }
        // Provider logoPath support was added in 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $providerMetadataArgs[] = dirname(__DIR__, 2) . '/assets/images/cloudflare.svg';
        }
        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Check valid API access by attempting to list models.
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new CloudflareModelMetadataDirectory();
    }
}
