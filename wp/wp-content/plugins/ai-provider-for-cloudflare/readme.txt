=== AI Provider for Cloudflare ===
Contributors: deepakbhojwani
Tags: ai, cloudflare, workers-ai, llama, connector
Requires at least: 6.9
Tested up to: 7.0.2
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cloudflare Workers AI for the WordPress AI Client — text generation, FLUX images, and AI alt text on Cloudflare's free edge.

== Description ==

This plugin adds Cloudflare Workers AI as a provider for the WordPress AI Client. Unlike text-only connectors, it brings **three** capabilities to any AI-Client-powered feature, plugin, or theme — **text generation, image generation, and image understanding (vision / alt text)** — all running on Cloudflare's global edge network.

Cloudflare Workers AI offers a free daily allocation (Neurons) on the free plan, and a single API token works across many hosted models — chat (Llama, Mistral, Gemma, Qwen, DeepSeek, and more), FLUX image generation, and multimodal vision.

**What you get:**

* **Text generation** with Cloudflare-hosted chat models (Llama, Mistral, Gemma, Qwen, DeepSeek, GPT-OSS, and more)
* **Image generation** with Cloudflare-hosted FLUX models (FLUX.1 [schnell] and FLUX.2 [dev] / [klein])
* **AI alt text & image understanding** — works out of the box with a non-gated multimodal model, so the WordPress alt-text feature just works (no per-account license step)
* JSON / structured output support (on models that support JSON mode)
* Function calling (tool) support (on models that support function calling)
* Reasoning ("thinking") model support
* Auto-preferred: once your credentials are saved, AI features use Cloudflare automatically — no per-feature provider picking
* Automatic provider registration with the WordPress AI Client
* No setup screen to wrestle with — credentials go on the standard Connectors screen, and the model list is read live from your account

The model list is read live from Cloudflare's model catalog, which is account-accurate, so only models your account can actually call are surfaced.

**Requirements:**

* PHP 7.4 or higher
* For WordPress 6.9, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed
* For WordPress 7.0 and above, no additional changes are required
* A free Cloudflare account with Workers AI (an API token and your account ID)

This plugin is a provider add-on: on its own it does nothing visible. It only takes effect once the PHP AI Client is available and another plugin (or your code) makes an AI request using the `cloudflare` provider.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-cloudflare/`, or install through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure the PHP AI Client is available (bundled with WordPress 7.0, or via the WordPress AI plugin / the wordpress/php-ai-client package on WordPress 6.9).
4. Configure your Cloudflare credentials. Cloudflare needs two values — your account ID and an API token — because the account ID is part of every Cloudflare API URL. Provide them in either of these ways:

* **On the AI provider screen (recommended):** in the Cloudflare API key field, enter both values joined by a colon, as `account-id:api-token` (for example `cc3a43c9...:cfut_...`). No file editing is needed.
* **In `wp-config.php`:** put the token in the key and the account ID in a constant (or set environment variables of the same names):

    define( 'CLOUDFLARE_API_KEY', 'your-workers-ai-api-token' );
    define( 'CLOUDFLARE_ACCOUNT_ID', 'your-account-id' );

== Frequently Asked Questions ==

= How do I get a Cloudflare API token and account ID? =

Sign in at [dash.cloudflare.com](https://dash.cloudflare.com), open Workers AI, and choose "Use REST API". From there you can create a Workers AI API token (it needs both the "Workers AI - Read" and "Workers AI - Edit" permissions) and copy your account ID, which is shown on the same page.

= Why do I need an account ID as well as a token? =

Cloudflare's API URL includes your account ID (for example `…/accounts/{account_id}/ai/…`), so the plugin cannot make any request without it, and a Workers AI token cannot reveal the account ID on its own. The easiest way is to enter `account-id:api-token` in the API key field; alternatively set the `CLOUDFLARE_ACCOUNT_ID` constant and put just the token in the key. The account ID is not a secret, but it is required.

= Does this plugin work without the PHP AI Client? =

No. This plugin requires the PHP AI Client. It provides the Cloudflare-specific implementation that the PHP AI Client uses, and registers itself automatically when the client is available.

= Which models are supported? =

The chat (text generation) models from Cloudflare's live catalog (Llama, Mistral, Gemma, Qwen, DeepSeek, GPT-OSS, and more), including vision-capable models that also accept image input, plus the FLUX.1 [schnell], FLUX.2 [dev], and FLUX.2 [klein] image generation models. JSON mode and function calling are available on the subset of models that support them.

= Does this plugin generate images? =

Yes. It supports the Cloudflare-hosted FLUX.1 [schnell] and FLUX.2 ([dev], [klein-4b], [klein-9b]) image generation models. Vision-capable chat models can additionally accept images as input.

= Does image understanding / alt text generation work? =

Yes. Image input (used by features such as alt text generation) is handled by a multimodal chat model. By default Mistral Small 3.1 is used: it is not license-gated (so it works on every account with no extra setup) and reliably follows accessibility instructions — writing real alt text for informative images and detecting decorative ones. Cloudflare's dedicated vision model, Llama 3.2 Vision, is also available for general image description, but it requires you to accept Meta's Llama license once before first use (open that model in the Cloudflare dashboard and click Agree, or send it one request with the prompt "agree"); until then it returns a 403, and it is not the default.

= Is there a free tier? =

Cloudflare Workers AI includes a free daily allocation on the free plan. Usage beyond that requires the Workers Paid plan. See Cloudflare's pricing for current limits.

= Is this plugin affiliated with Cloudflare? =

No. Cloudflare and Workers AI are trademarks of Cloudflare, Inc. This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by Cloudflare, Inc.

== External services ==

This plugin connects to the Cloudflare Workers AI API to provide AI text and image generation. It is required for the plugin's core functionality and only runs when you (or a plugin using the PHP AI Client) trigger an AI request.

- What it does: sends your prompt text, model selection, and generation parameters to Cloudflare's Workers AI inference API and receives the generated text or image.
- When: only when an AI request is made via the PHP AI Client using the `cloudflare` provider. No data is sent on page load or in the background.
- Data sent: the prompt/messages you provide, the chosen model ID, generation parameters, and your Cloudflare API token (as an Authorization header) for authentication. The request URL includes your Cloudflare account ID.
- Service: Cloudflare Workers AI — endpoint `api.cloudflare.com`.
  - Terms of Service: https://www.cloudflare.com/website-terms/
  - Privacy Policy: https://www.cloudflare.com/privacypolicy/

Cloudflare and Workers AI are trademarks of Cloudflare, Inc. This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by Cloudflare, Inc.

== Changelog ==

= 1.0.0 =

* Initial release.
* Text generation with Cloudflare Workers AI chat models via the OpenAI-compatible Chat Completions API.
* Image generation with the Cloudflare-hosted FLUX.1 [schnell] and FLUX.2 [dev] / [klein] models via the Workers AI run endpoint.
* Image understanding / AI alt text via a non-gated multimodal model — works out of the box, no per-account license step.
* Auto-preferred: AI features use Cloudflare automatically once credentials are configured (via the wpai_preferred_text_models filter).
* Live model catalog (account-accurate) — only models your account can call are surfaced.
* JSON output, function calling, and reasoning support.
