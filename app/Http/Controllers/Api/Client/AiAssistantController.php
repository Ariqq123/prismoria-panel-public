<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use GuzzleHttp\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\MCPluginsConfig;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Permission;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\External\ExternalServerRepository;
use Pterodactyl\Services\External\ExternalServerReference;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class AiAssistantController extends ClientApiController
{
    private const MAX_AGENT_TOOL_ITERATIONS = 6;
    private const MAX_AGENT_FILE_READ_BYTES = 32768;
    private const MAX_AGENT_FILE_WRITE_BYTES = 131072;
    private const MAX_AGENT_EDIT_FILE_BYTES = 262144;
    private const MAX_AGENT_COMMAND_LOG_READ_BYTES = 131072;
    private const MAX_AGENT_COMMAND_LOG_RETURN_BYTES = 16384;
    private const MAX_AGENT_COMMAND_LOG_MAX_LINES = 300;
    private const MAX_AGENT_COMMAND_WAIT_SECONDS = 15;
    private const PROVIDER_MAX_RETRIES = 3;
    private const PROVIDER_MAX_RETRY_DELAY_SECONDS = 20;

    public function __construct(
        private ExternalServerRepository $externalRepository,
        private DaemonCommandRepository $daemonCommandRepository,
        private DaemonPowerRepository $daemonPowerRepository,
        private DaemonFileRepository $daemonFileRepository,
        private DaemonServerRepository $daemonServerRepository
    ) {
        parent::__construct();
    }

    public function chat(ClientApiRequest $request): array
    {
        $this->validate($request, [
            'provider' => 'sometimes|string|in:openai,groq,grok,gemini',
            'message' => 'required|string|min:1|max:4000',
            'history' => 'sometimes|array|max:20',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string|min:1|max:4000',
            'context' => 'sometimes|array',
            'context.routePath' => 'sometimes|string|max:2048',
            'context.route_path' => 'sometimes|string|max:2048',
            'context.server' => 'sometimes|array',
            'context.server.identifier' => 'required_with:context.server|string|max:191',
            'context.server.name' => 'sometimes|string|max:191',
            'context.server.uuid' => 'sometimes|string|max:191',
            'context.server.source' => 'sometimes|string|in:local,external',
            'context.server.externalPanelName' => 'sometimes|string|max:191',
            'context.server.external_panel_name' => 'sometimes|string|max:191',
            'context.server.externalPanelUrl' => 'sometimes|string|max:2048',
            'context.server.external_panel_url' => 'sometimes|string|max:2048',
            'context.server.externalServerIdentifier' => 'sometimes|string|max:191',
            'context.server.external_server_identifier' => 'sometimes|string|max:191',
        ]);

        $provider = $this->normalizeProvider((string) $request->input('provider', ''));
        if ($provider === '') {
            $provider = $this->normalizeProvider((string) config('services.ai_assistant.default_provider', 'openai'));
        }
        if ($provider === '') {
            $provider = 'openai';
        }

        [$apiKey, $model, $baseUrl, $systemPrompt, $temperature] = $this->providerConfig($provider);
        $history = $this->sanitizeHistory(is_array($request->input('history')) ? $request->input('history') : []);
        $message = trim((string) $request->input('message'));
        $context = $this->sanitizeContext($request->input('context'));
        $contextPrompt = $this->buildContextPrompt($context);
        if ($contextPrompt !== '') {
            $systemPrompt = trim(
                implode(
                    "\n\n",
                    array_values(array_filter([$systemPrompt, $contextPrompt], static fn (string $value): bool => $value !== ''))
                )
            );
        }

        if ($provider === 'gemini') {
            [$reply, $resolvedModel] = $this->requestGemini(
                $apiKey,
                $baseUrl,
                $model,
                $systemPrompt,
                $temperature,
                $history,
                $message
            );

            return [
                'object' => 'assistant_reply',
                'attributes' => [
                    'provider' => $provider,
                    'message' => $reply,
                    'model' => $resolvedModel,
                ],
            ];
        }

        [$reply, $resolvedModel] = $this->requestOpenAiCompatible(
            $provider,
            $apiKey,
            $baseUrl,
            $model,
            $systemPrompt,
            $temperature,
            $history,
            $message,
            $context,
            $request->user()
        );

        return [
            'object' => 'assistant_reply',
            'attributes' => [
                'provider' => $provider,
                'message' => $reply,
                'model' => $resolvedModel,
            ],
        ];
    }

    /**
     * @param array<int, mixed> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $sanitized = [];

        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = strtolower(trim((string) Arr::get($item, 'role', '')));
            $content = trim((string) Arr::get($item, 'content', ''));

            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $sanitized[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 4000),
            ];
        }

        return array_slice($sanitized, -12);
    }

    /**
     * @return array{
     *   route_path?: string,
     *   server?: array{
     *     identifier: string,
     *     name?: string,
     *     uuid?: string,
     *     source?: string,
     *     external_panel_name?: string,
     *     external_panel_url?: string,
     *     external_server_identifier?: string
     *   }
     * }
     */
    private function sanitizeContext(mixed $context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $routePath = $this->sanitizeContextString((string) Arr::get($context, 'route_path', Arr::get($context, 'routePath', '')), 2048);
        $server = Arr::get($context, 'server');
        $serverContext = [];

        if (is_array($server)) {
            $identifier = $this->sanitizeContextString((string) Arr::get($server, 'identifier', ''), 191);
            if ($identifier !== '') {
                $serverContext['identifier'] = $identifier;
            }

            $name = $this->sanitizeContextString((string) Arr::get($server, 'name', ''), 191);
            if ($name !== '') {
                $serverContext['name'] = $name;
            }

            $uuid = $this->sanitizeContextString((string) Arr::get($server, 'uuid', ''), 191);
            if ($uuid !== '') {
                $serverContext['uuid'] = $uuid;
            }

            $source = strtolower(
                $this->sanitizeContextString((string) Arr::get($server, 'source', ''), 16)
            );
            if (in_array($source, ['local', 'external'], true)) {
                $serverContext['source'] = $source;
            }

            $externalPanelName = $this->sanitizeContextString(
                (string) Arr::get($server, 'external_panel_name', Arr::get($server, 'externalPanelName', '')),
                191
            );
            if ($externalPanelName !== '') {
                $serverContext['external_panel_name'] = $externalPanelName;
            }

            $externalPanelUrl = $this->sanitizeContextString(
                (string) Arr::get($server, 'external_panel_url', Arr::get($server, 'externalPanelUrl', '')),
                2048
            );
            if ($externalPanelUrl !== '') {
                $serverContext['external_panel_url'] = $externalPanelUrl;
            }

            $externalServerIdentifier = $this->sanitizeContextString(
                (string) Arr::get(
                    $server,
                    'external_server_identifier',
                    Arr::get($server, 'externalServerIdentifier', '')
                ),
                191
            );
            if ($externalServerIdentifier !== '') {
                $serverContext['external_server_identifier'] = $externalServerIdentifier;
            }
        }

        $output = [];
        if ($routePath !== '') {
            $output['route_path'] = $routePath;
        }

        if (isset($serverContext['identifier'])) {
            $output['server'] = $serverContext;
        }

        return $output;
    }

    private function sanitizeContextString(string $value, int $limit): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return mb_substr($trimmed, 0, $limit);
    }

    /**
     * @param array{
     *   route_path?: string,
     *   server?: array{
     *     identifier: string,
     *     name?: string,
     *     uuid?: string,
     *     source?: string,
     *     external_panel_name?: string,
     *     external_panel_url?: string,
     *     external_server_identifier?: string
     *   }
     * } $context
     */
    private function buildContextPrompt(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $lines = ['Panel context (authoritative):'];

        $routePath = (string) Arr::get($context, 'route_path', '');
        if ($routePath !== '') {
            $lines[] = sprintf('- Route: %s', $routePath);
        }

        $server = Arr::get($context, 'server');
        if (is_array($server)) {
            $identifier = (string) Arr::get($server, 'identifier', '');
            if ($identifier !== '') {
                $lines[] = sprintf('- Server identifier: %s', $identifier);
            }

            $name = (string) Arr::get($server, 'name', '');
            if ($name !== '') {
                $lines[] = sprintf('- Server name: %s', $name);
            }

            $uuid = (string) Arr::get($server, 'uuid', '');
            if ($uuid !== '') {
                $lines[] = sprintf('- Server UUID: %s', $uuid);
            }

            $source = (string) Arr::get($server, 'source', '');
            if ($source !== '') {
                $lines[] = sprintf('- Server source: %s', $source);
            }

            $externalPanelName = (string) Arr::get($server, 'external_panel_name', '');
            if ($externalPanelName !== '') {
                $lines[] = sprintf('- External panel name: %s', $externalPanelName);
            }

            $externalPanelUrl = (string) Arr::get($server, 'external_panel_url', '');
            if ($externalPanelUrl !== '') {
                $lines[] = sprintf('- External panel URL: %s', $externalPanelUrl);
            }

            $externalServerIdentifier = (string) Arr::get($server, 'external_server_identifier', '');
            if ($externalServerIdentifier !== '') {
                $lines[] = sprintf('- External server identifier: %s', $externalServerIdentifier);
            }
        }

        $lines[] = 'Use this panel context when it is relevant. If any value is missing, say it is unavailable instead of guessing.';
        if (is_array($server) && ((string) Arr::get($server, 'identifier', '')) !== '') {
            $lines[] = 'You can use server tools for status, power, console commands with log tail output, plugin manager search/install, direct plugin download, and safe file read/edit actions on this context server.';
            $lines[] = 'For potentially disruptive actions (stop, kill, full file overwrite), ask for user confirmation unless the user explicitly requested it.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    private function detectForcedAssistantIntent(string $message, string $defaultServerIdentifier): ?array
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return null;
        }

        $lower = strtolower($normalized);

        if (preg_match('/\b(install|download)\b/', $lower) === 1 && str_contains($lower, 'plugin')) {
            $arguments = $this->extractInstallPluginIntentArguments($normalized);
            if (!is_null($arguments)) {
                if (!isset($arguments['server_identifier']) && $defaultServerIdentifier !== '') {
                    $arguments['server_identifier'] = $defaultServerIdentifier;
                }

                return [
                    'tool' => 'install_plugin_manager',
                    'arguments' => $arguments,
                ];
            }
        }

        if (preg_match('/\b(search|find|lookup)\b/', $lower) === 1 && str_contains($lower, 'plugin')) {
            $arguments = $this->extractSearchPluginIntentArguments($normalized);
            if (!is_null($arguments)) {
                return [
                    'tool' => 'search_plugin_manager',
                    'arguments' => $arguments,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractInstallPluginIntentArguments(string $message): ?array
    {
        $provider = $this->extractPluginProviderFromText($message);
        $pluginId = '';
        if (preg_match('/\bplugin[_\s-]?id\s*[:#]?\s*([A-Za-z0-9._:-]+)\b/i', $message, $matches) === 1) {
            $pluginId = trim((string) ($matches[1] ?? ''));
        }

        $pluginQuery = '';
        $patterns = [
            '/\bplugin\s+(.+?)(?:\s+\b(?:using|with|via|on|for)\b|$)/i',
            '/\b(?:install|download)\s+(.+?)\s+\bplugin\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $pluginQuery = trim((string) ($matches[1] ?? ''));
                if ($pluginQuery !== '') {
                    break;
                }
            }
        }

        if ($pluginQuery !== '') {
            $pluginQuery = preg_replace('/\bprovider\s+(modrinth|curseforge|spigotmc|hangar|polymart)\b/i', '', $pluginQuery) ?? $pluginQuery;
            $pluginQuery = trim($pluginQuery, " \t\n\r\0\x0B'\"`.,;:()[]{}");
        }

        if ($pluginId === '' && $pluginQuery === '') {
            return null;
        }

        $arguments = [];
        if ($provider !== '') {
            $arguments['provider'] = $provider;
        }
        if ($pluginId !== '') {
            $arguments['plugin_id'] = $pluginId;
        } else {
            $arguments['plugin_query'] = mb_substr($pluginQuery, 0, 120);
        }

        return $arguments;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSearchPluginIntentArguments(string $message): ?array
    {
        $provider = $this->extractPluginProviderFromText($message);
        $pluginQuery = '';
        $patterns = [
            '/\b(?:search|find|lookup)\s+(?:for\s+)?plugin\s+(.+?)(?:\s+\b(?:using|with|via|on|for)\b|$)/i',
            '/\bplugin\s+(.+?)\s+\b(?:search|find|lookup)\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $pluginQuery = trim((string) ($matches[1] ?? ''));
                if ($pluginQuery !== '') {
                    break;
                }
            }
        }

        if ($pluginQuery === '') {
            return null;
        }

        $pluginQuery = preg_replace('/\bprovider\s+(modrinth|curseforge|spigotmc|hangar|polymart)\b/i', '', $pluginQuery) ?? $pluginQuery;
        $pluginQuery = trim($pluginQuery, " \t\n\r\0\x0B'\"`.,;:()[]{}");
        if ($pluginQuery === '') {
            return null;
        }

        $arguments = [
            'query' => mb_substr($pluginQuery, 0, 120),
            'limit' => 6,
        ];
        if ($provider !== '') {
            $arguments['provider'] = $provider;
        }

        return $arguments;
    }

    private function extractPluginProviderFromText(string $message): string
    {
        $providers = implode('|', $this->pluginManagerProviders());
        $patterns = [
            '/\bprovider\s+(' . $providers . ')\b/i',
            '/\busing\s+(' . $providers . ')\b/i',
            '/\bvia\s+(' . $providers . ')\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $provider = strtolower(trim((string) ($matches[1] ?? '')));
                if (in_array($provider, $this->pluginManagerProviders(), true)) {
                    return $provider;
                }
            }
        }

        return '';
    }

    private function formatForcedToolSuccessReply(string $toolName, array $result): string
    {
        if ($toolName === 'install_plugin_manager') {
            $provider = (string) Arr::get($result, 'provider', 'unknown');
            $pluginLabel = trim((string) Arr::get($result, 'plugin_name', ''));
            if ($pluginLabel === '') {
                $pluginLabel = (string) Arr::get($result, 'plugin_id', 'plugin');
            }
            $serverIdentifier = trim((string) Arr::get($result, 'server_identifier', 'current server'));
            $directory = (string) Arr::get($result, 'directory', '/plugins');

            return sprintf(
                'Plugin install queued via Plugin Manager: %s (provider: %s) on %s to %s.',
                $pluginLabel,
                $provider,
                $serverIdentifier !== '' ? $serverIdentifier : 'current server',
                $directory
            );
        }

        if ($toolName === 'search_plugin_manager') {
            $provider = (string) Arr::get($result, 'provider', 'unknown');
            $items = Arr::get($result, 'results', []);
            if (!is_array($items) || $items === []) {
                return sprintf('No plugin results found on provider %s.', $provider);
            }

            $rows = [];
            foreach (array_slice($items, 0, 3) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) Arr::get($item, 'name', ''));
                $id = trim((string) Arr::get($item, 'id', ''));
                if ($name === '' && $id === '') {
                    continue;
                }
                $rows[] = $name !== '' ? sprintf('%s (%s)', $name, $id) : $id;
            }

            if ($rows === []) {
                return sprintf('Plugin search completed on provider %s.', $provider);
            }

            return sprintf('Top plugin results on %s: %s.', $provider, implode('; ', $rows));
        }

        return 'Action completed successfully.';
    }

    private function extractAssistantMessage(array $payload): string
    {
        $content = Arr::get($payload, 'choices.0.message.content');

        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $segment) {
                if (is_string($segment)) {
                    $parts[] = $segment;
                    continue;
                }

                if (!is_array($segment)) {
                    continue;
                }

                $text = Arr::get($segment, 'text', Arr::get($segment, 'content', ''));
                if (is_string($text) && trim($text) !== '') {
                    $parts[] = $text;
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));
        if ($normalized === 'grok') {
            $normalized = 'groq';
        }

        return in_array($normalized, ['openai', 'groq', 'gemini'], true) ? $normalized : '';
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: float}
     */
    private function providerConfig(string $provider): array
    {
        $prefix = sprintf('services.%s', $provider);
        $apiKey = trim((string) config($prefix . '.api_key', ''));
        if ($apiKey === '') {
            throw new DisplayException(sprintf('%s is not configured. Set %s_API_KEY in panel environment.', strtoupper($provider), strtoupper($provider)));
        }

        $defaultModel = match ($provider) {
            'groq' => 'llama-3.3-70b-versatile',
            'gemini' => 'gemini-2.5-flash',
            default => 'gpt-4o-mini',
        };
        $defaultBaseUrl = match ($provider) {
            'groq' => 'https://api.groq.com/openai/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            default => 'https://api.openai.com/v1',
        };

        $model = trim((string) config($prefix . '.model', $defaultModel));
        if ($model === '') {
            $model = $defaultModel;
        }

        $baseUrl = rtrim((string) config($prefix . '.base_url', $defaultBaseUrl), '/');
        if ($baseUrl === '') {
            $baseUrl = $defaultBaseUrl;
        }

        $systemPrompt = trim((string) config($prefix . '.system_prompt', ''));
        if ($systemPrompt === '') {
            $systemPrompt = trim((string) config('services.openai.system_prompt', ''));
        }

        $temperature = (float) config($prefix . '.temperature', 0.4);
        if ($temperature < 0 || $temperature > 2) {
            $temperature = 0.4;
        }

        return [$apiKey, $model, $baseUrl, $systemPrompt, $temperature];
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{0: string, 1: string}
     */
    private function requestOpenAiCompatible(
        string $provider,
        string $apiKey,
        string $baseUrl,
        string $model,
        string $systemPrompt,
        float $temperature,
        array $history,
        string $message,
        array $context,
        User $user
    ): array {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }
        foreach ($history as $entry) {
            $messages[] = $entry;
        }
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $defaultServerIdentifier = trim((string) Arr::get($context, 'server.identifier', ''));
        $tools = $this->assistantTools();
        $toolIterations = 0;
        $resolvedModel = $model;
        $forcedIntent = $this->detectForcedAssistantIntent($message, $defaultServerIdentifier);

        while (true) {
            $payload = [
                'model' => $model,
                'temperature' => $temperature,
                'messages' => $messages,
            ];
            if ($tools !== []) {
                $payload['tools'] = $tools;
                if ($toolIterations === 0 && is_array($forcedIntent)) {
                    $payload['tool_choice'] = [
                        'type' => 'function',
                        'function' => [
                            'name' => (string) Arr::get($forcedIntent, 'tool', ''),
                        ],
                    ];
                } else {
                    $payload['tool_choice'] = 'auto';
                }
            }

            $response = $this->requestOpenAiCompatibleWithRetry($provider, $apiKey, $baseUrl, $payload);

            if (!$response->successful()) {
                $this->throwProviderApiError($provider, $response);
            }

            $json = is_array($response->json()) ? $response->json() : [];
            $resolvedModel = trim((string) Arr::get($json, 'model', $resolvedModel));
            if ($resolvedModel === '') {
                $resolvedModel = $model;
            }

            $assistantMessage = Arr::get($json, 'choices.0.message', []);
            $assistantContent = $this->extractAssistantContent(Arr::get($assistantMessage, 'content'));
            $toolCalls = Arr::get($assistantMessage, 'tool_calls', []);

            if (!is_array($toolCalls) || $toolCalls === [] || $tools === []) {
                if ($toolIterations === 0 && is_array($forcedIntent)) {
                    $forcedToolName = trim((string) Arr::get($forcedIntent, 'tool', ''));
                    $forcedArguments = Arr::get($forcedIntent, 'arguments', []);
                    if ($forcedToolName !== '' && is_array($forcedArguments)) {
                        $forcedResult = $this->executeAssistantTool($forcedToolName, $forcedArguments, $defaultServerIdentifier, $user);
                        if ((bool) Arr::get($forcedResult, 'ok', false)) {
                            return [$this->formatForcedToolSuccessReply($forcedToolName, $forcedResult), $resolvedModel];
                        }

                        $forcedError = trim((string) Arr::get($forcedResult, 'error', ''));
                        if ($forcedError !== '') {
                            throw new DisplayException($forcedError);
                        }
                    }
                }

                $reply = trim($assistantContent);
                if ($reply === '') {
                    throw new DisplayException(sprintf('%s assistant returned an empty response.', strtoupper($provider)));
                }

                return [$reply, $resolvedModel];
            }

            if ($toolIterations >= self::MAX_AGENT_TOOL_ITERATIONS) {
                throw new DisplayException('AI agent reached the maximum action depth for one request.');
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $assistantContent !== '' ? $assistantContent : null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }

                $toolName = trim((string) Arr::get($toolCall, 'function.name', ''));
                if ($toolName === '') {
                    continue;
                }

                $toolArgumentsRaw = Arr::get($toolCall, 'function.arguments', '{}');
                $toolArguments = [];
                if (is_string($toolArgumentsRaw) && trim($toolArgumentsRaw) !== '') {
                    $decodedArguments = json_decode($toolArgumentsRaw, true);
                    if (is_array($decodedArguments)) {
                        $toolArguments = $decodedArguments;
                    }
                }

                $toolResult = $this->executeAssistantTool($toolName, $toolArguments, $defaultServerIdentifier, $user);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) Arr::get($toolCall, 'id', ''),
                    'name' => $toolName,
                    'content' => json_encode($toolResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }

            $toolIterations += 1;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestOpenAiCompatibleWithRetry(
        string $provider,
        string $apiKey,
        string $baseUrl,
        array $payload
    ): Response {
        $response = null;

        for ($attempt = 1; $attempt <= self::PROVIDER_MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(45)
                    ->connectTimeout(10)
                    ->acceptJson()
                    ->withToken($apiKey)
                    ->post($baseUrl . '/chat/completions', $payload);
            } catch (ConnectionException) {
                if ($attempt >= self::PROVIDER_MAX_RETRIES) {
                    break;
                }

                usleep((int) ($this->providerRetryDelaySecondsFromAttempt($attempt) * 1_000_000));
                continue;
            }

            if ($response->status() !== 429 || $attempt >= self::PROVIDER_MAX_RETRIES) {
                break;
            }

            usleep((int) ($this->providerRetryDelaySecondsFromResponse($response, $attempt) * 1_000_000));
        }

        if (!$response instanceof Response) {
            throw new DisplayException(sprintf('Failed to connect to %s API. Please try again shortly.', strtoupper($provider)));
        }

        return $response;
    }

    private function providerRetryDelaySecondsFromAttempt(int $attempt): float
    {
        $base = min(self::PROVIDER_MAX_RETRY_DELAY_SECONDS, max(1, 2 ** ($attempt - 1)));
        $jitter = mt_rand(0, 300) / 1000;

        return min(self::PROVIDER_MAX_RETRY_DELAY_SECONDS, $base + $jitter);
    }

    private function providerRetryDelaySecondsFromResponse(Response $response, int $attempt): float
    {
        $retryAfter = trim((string) $response->header('retry-after', ''));
        if ($retryAfter !== '') {
            $seconds = is_numeric($retryAfter) ? (float) $retryAfter : null;
            if (is_null($seconds)) {
                $timestamp = strtotime($retryAfter);
                if ($timestamp !== false) {
                    $seconds = max(0, $timestamp - time());
                }
            }

            if (!is_null($seconds)) {
                return min(self::PROVIDER_MAX_RETRY_DELAY_SECONDS, max(0.25, $seconds));
            }
        }

        $resetTokensHeader = trim((string) $response->header('x-ratelimit-reset-tokens', ''));
        $resetTokensSeconds = $this->parseRateLimitResetDuration($resetTokensHeader);
        if (!is_null($resetTokensSeconds)) {
            return min(self::PROVIDER_MAX_RETRY_DELAY_SECONDS, max(0.25, $resetTokensSeconds));
        }

        $resetRequestsHeader = trim((string) $response->header('x-ratelimit-reset-requests', ''));
        $resetRequestsSeconds = $this->parseRateLimitResetDuration($resetRequestsHeader);
        if (!is_null($resetRequestsSeconds)) {
            return min(self::PROVIDER_MAX_RETRY_DELAY_SECONDS, max(0.25, $resetRequestsSeconds));
        }

        return $this->providerRetryDelaySecondsFromAttempt($attempt);
    }

    private function parseRateLimitResetDuration(string $value): ?float
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(?:(\d+)m)?([0-9]+(?:\.[0-9]+)?)s$/i', $value, $matches) === 1) {
            $minutes = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $seconds = (float) $matches[2];

            return ($minutes * 60) + $seconds;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assistantTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_server_status',
                    'description' => 'Get server state and resource usage for the current server.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'send_server_command',
                    'description' => 'Send a console command to a server and optionally return recent log output.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['command'],
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                            'command' => [
                                'type' => 'string',
                                'description' => 'Command text to send to the server console.',
                            ],
                            'include_log' => [
                                'type' => 'boolean',
                                'description' => 'Return recent log output after the command. Defaults to true.',
                            ],
                            'log_path' => [
                                'type' => 'string',
                                'description' => 'Optional log file path. Defaults to /logs/latest.log.',
                            ],
                            'tail_lines' => [
                                'type' => 'integer',
                                'description' => 'Number of recent log lines to return. Defaults to 60, max 300.',
                            ],
                            'wait_seconds' => [
                                'type' => 'number',
                                'description' => 'Delay before reading logs, to let command output flush. Defaults to 2 seconds, max 15.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_plugin_manager',
                    'description' => 'Search plugins from the panel plugin manager providers and return plugin IDs for installation.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['query'],
                        'properties' => [
                            'provider' => [
                                'type' => 'string',
                                'description' => 'Plugin provider (modrinth, curseforge, spigotmc, hangar, polymart). Defaults to manager default provider.',
                            ],
                            'query' => [
                                'type' => 'string',
                                'description' => 'Plugin name or search text.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum results to return. Defaults to 5, max 10.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'install_plugin_manager',
                    'description' => 'Install a plugin using the same provider logic as the panel plugin manager.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                            'provider' => [
                                'type' => 'string',
                                'description' => 'Plugin provider (modrinth, curseforge, spigotmc, hangar, polymart). Defaults to manager default provider.',
                            ],
                            'plugin_id' => [
                                'type' => 'string',
                                'description' => 'Plugin ID from provider. Optional if plugin_query is provided.',
                            ],
                            'plugin_query' => [
                                'type' => 'string',
                                'description' => 'Search query to auto-pick first installable plugin when plugin_id is omitted.',
                            ],
                            'version_id' => [
                                'type' => 'string',
                                'description' => 'Optional specific version identifier.',
                            ],
                            'directory' => [
                                'type' => 'string',
                                'description' => 'Destination directory. Defaults to /plugins.',
                            ],
                            'foreground' => [
                                'type' => 'boolean',
                                'description' => 'Wait for completion before returning. Defaults to true.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'download_server_plugin',
                    'description' => 'Download a plugin/archive file from a remote URL into the server file system (default /plugins).',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['url'],
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                            'url' => [
                                'type' => 'string',
                                'description' => 'Public HTTP(S) URL to the plugin file.',
                            ],
                            'directory' => [
                                'type' => 'string',
                                'description' => 'Destination directory. Defaults to /plugins.',
                            ],
                            'filename' => [
                                'type' => 'string',
                                'description' => 'Optional output filename. If omitted, use upstream header/name.',
                            ],
                            'use_header' => [
                                'type' => 'boolean',
                                'description' => 'Use upstream Content-Disposition filename if available. Defaults to true.',
                            ],
                            'foreground' => [
                                'type' => 'boolean',
                                'description' => 'Wait for completion before returning. Defaults to true.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'set_server_power',
                    'description' => 'Send a power action to a server (start, stop, restart, kill).',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['signal'],
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                            'signal' => [
                                'type' => 'string',
                                'enum' => ['start', 'stop', 'restart', 'kill'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_server_file',
                    'description' => 'Read text file content from a server.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['path'],
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                            'path' => [
                                'type' => 'string',
                                'description' => 'Absolute file path, for example: /plugins/example/config.yml',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'edit_server_file',
                    'description' => 'Safely edit a text file with targeted operations without replacing unrelated content.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['path', 'edits'],
                        'properties' => [
                            'server_identifier' => [
                                'type' => 'string',
                                'description' => 'Optional server identifier. Use current context server if omitted.',
                            ],
                            'path' => [
                                'type' => 'string',
                                'description' => 'Absolute file path, for example: /server.properties',
                            ],
                            'create_if_missing' => [
                                'type' => 'boolean',
                                'description' => 'Create a new file when missing. Defaults to true.',
                            ],
                            'edits' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => 64,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['action'],
                                    'properties' => [
                                        'action' => [
                                            'type' => 'string',
                                            'enum' => ['set_key_value', 'replace_text', 'append_text', 'prepend_text', 'ensure_line', 'remove_line', 'remove_text'],
                                        ],
                                        'key' => [
                                            'type' => 'string',
                                            'description' => 'Required for set_key_value.',
                                        ],
                                        'value' => [
                                            'type' => 'string',
                                            'description' => 'Required for set_key_value.',
                                        ],
                                        'delimiter' => [
                                            'type' => 'string',
                                            'description' => 'Optional delimiter for set_key_value, default "=".',
                                        ],
                                        'search' => [
                                            'type' => 'string',
                                            'description' => 'Required for replace_text and remove_text.',
                                        ],
                                        'replace' => [
                                            'type' => 'string',
                                            'description' => 'Replacement text for replace_text.',
                                        ],
                                        'text' => [
                                            'type' => 'string',
                                            'description' => 'Text payload for append_text or prepend_text.',
                                        ],
                                        'line' => [
                                            'type' => 'string',
                                            'description' => 'Line payload for ensure_line or remove_line.',
                                        ],
                                        'all' => [
                                            'type' => 'boolean',
                                            'description' => 'Apply action to all matches when supported.',
                                        ],
                                        'contains' => [
                                            'type' => 'boolean',
                                            'description' => 'For remove_line: match lines containing "line" text.',
                                        ],
                                        'if_missing' => [
                                            'type' => 'boolean',
                                            'description' => 'Do not error when target text/key/line is missing.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function extractAssistantContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $segment) {
            if (is_string($segment)) {
                $parts[] = $segment;
                continue;
            }

            if (!is_array($segment)) {
                continue;
            }

            $text = Arr::get($segment, 'text', Arr::get($segment, 'content', ''));
            if (is_string($text) && trim($text) !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function executeAssistantTool(string $toolName, array $arguments, string $defaultServerIdentifier, User $user): array
    {
        try {
            return match ($toolName) {
                'get_server_status' => $this->toolGetServerStatus($arguments, $defaultServerIdentifier, $user),
                'send_server_command' => $this->toolSendServerCommand($arguments, $defaultServerIdentifier, $user),
                'search_plugin_manager' => $this->toolSearchPluginManager($arguments),
                'install_plugin_manager' => $this->toolInstallPluginManager($arguments, $defaultServerIdentifier, $user),
                'download_server_plugin' => $this->toolDownloadServerPlugin($arguments, $defaultServerIdentifier, $user),
                'set_server_power' => $this->toolSetServerPower($arguments, $defaultServerIdentifier, $user),
                'read_server_file' => $this->toolReadServerFile($arguments, $defaultServerIdentifier, $user),
                'edit_server_file' => $this->toolEditServerFile($arguments, $defaultServerIdentifier, $user),
                'write_server_file' => $this->toolWriteServerFile($arguments, $defaultServerIdentifier, $user),
                default => ['ok' => false, 'error' => sprintf('Unsupported tool: %s', $toolName)],
            };
        } catch (DisplayException $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        } catch (DaemonConnectionException $exception) {
            return [
                'ok' => false,
                'error' => 'Unable to connect to the server daemon for this action.',
            ];
        } catch (\Throwable $exception) {
            report($exception);
            $message = trim($exception->getMessage());

            return [
                'ok' => false,
                'error' => $message !== ''
                    ? 'Agent action failed: ' . $message
                    : 'Agent action failed due to an internal error.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolGetServerStatus(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);

        if ($target['source'] === 'external') {
            $payload = $this->externalRepository->getResources($target['identifier'], $user);
            $attributes = Arr::get($payload, 'attributes', []);

            return [
                'ok' => true,
                'source' => 'external',
                'server_identifier' => $target['identifier'],
                'state' => (string) Arr::get($attributes, 'current_state', Arr::get($attributes, 'state', 'unknown')),
                'resources' => [
                    'memory_bytes' => (int) Arr::get($attributes, 'resources.memory_bytes', 0),
                    'cpu_absolute' => (float) Arr::get($attributes, 'resources.cpu_absolute', 0),
                    'disk_bytes' => (int) Arr::get($attributes, 'resources.disk_bytes', 0),
                    'network_rx_bytes' => (int) Arr::get($attributes, 'resources.network_rx_bytes', 0),
                    'network_tx_bytes' => (int) Arr::get($attributes, 'resources.network_tx_bytes', 0),
                ],
            ];
        }

        $details = $this->daemonServerRepository->setServer($target['server'])->getDetails();

        return [
            'ok' => true,
            'source' => 'local',
            'server_identifier' => $target['identifier'],
            'state' => (string) Arr::get($details, 'state', Arr::get($details, 'current_state', 'unknown')),
            'resources' => [
                'memory_bytes' => (int) Arr::get($details, 'resources.memory_bytes', 0),
                'cpu_absolute' => (float) Arr::get($details, 'resources.cpu_absolute', 0),
                'disk_bytes' => (int) Arr::get($details, 'resources.disk_bytes', 0),
                'network_rx_bytes' => (int) Arr::get($details, 'resources.network_rx_bytes', 0),
                'network_tx_bytes' => (int) Arr::get($details, 'resources.network_tx_bytes', 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolSendServerCommand(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);
        $this->assertAgentPermission($target, $user, Permission::ACTION_CONTROL_CONSOLE);

        $command = trim((string) Arr::get($arguments, 'command', ''));
        if ($command === '') {
            throw new DisplayException('Command is required.');
        }

        if (mb_strlen($command) > 1024) {
            throw new DisplayException('Command is too long (max 1024 characters).');
        }

        if ($target['source'] === 'external') {
            $this->externalRepository->sendCommand($target['identifier'], $user, ['command' => $command]);
        } else {
            $this->daemonCommandRepository->setServer($target['server'])->send($command);
        }

        $includeLog = $this->parseBoolean(Arr::get($arguments, 'include_log', true), true);
        $logPath = trim((string) Arr::get($arguments, 'log_path', '/logs/latest.log'));
        if ($logPath === '') {
            $logPath = '/logs/latest.log';
        }

        $waitSeconds = (float) Arr::get($arguments, 'wait_seconds', 2);
        if (!is_finite($waitSeconds)) {
            $waitSeconds = 2;
        }
        $waitSeconds = max(0, min(self::MAX_AGENT_COMMAND_WAIT_SECONDS, $waitSeconds));

        $tailLines = (int) Arr::get($arguments, 'tail_lines', 60);
        $tailLines = max(1, min(self::MAX_AGENT_COMMAND_LOG_MAX_LINES, $tailLines));

        $log = null;
        $logWarning = null;
        if ($includeLog) {
            if ($waitSeconds > 0) {
                usleep((int) round($waitSeconds * 1_000_000));
            }

            try {
                $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_READ_CONTENT);
                $logContent = $this->readServerFileContentForTarget(
                    $target,
                    $user,
                    $logPath,
                    self::MAX_AGENT_COMMAND_LOG_READ_BYTES
                );
                $log = $this->extractTailLogLines($logContent, $tailLines, self::MAX_AGENT_COMMAND_LOG_RETURN_BYTES);
            } catch (\Throwable $exception) {
                $logWarning = sprintf('Unable to read command log from "%s".', $logPath);
            }
        }

        $response = [
            'ok' => true,
            'source' => $target['source'],
            'server_identifier' => $target['identifier'],
            'command' => $command,
            'include_log' => $includeLog,
        ];

        if ($includeLog) {
            $response['log_path'] = $logPath;
            $response['tail_lines'] = $tailLines;
            $response['log'] = $log;
            if (!is_null($logWarning)) {
                $response['warning'] = $logWarning;
            }
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolSearchPluginManager(array $arguments): array
    {
        $provider = $this->normalizePluginProvider(
            (string) Arr::get($arguments, 'provider', $this->defaultPluginProvider())
        );

        $query = trim((string) Arr::get($arguments, 'query', ''));
        if ($query === '') {
            throw new DisplayException('Plugin search query is required.');
        }

        $limit = (int) Arr::get($arguments, 'limit', 5);
        $limit = max(1, min(10, $limit));

        $results = $this->searchPluginManagerCandidates($provider, $query, $limit);

        return [
            'ok' => true,
            'provider' => $provider,
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolInstallPluginManager(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);
        $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_CREATE);

        $providerInput = strtolower(trim((string) Arr::get($arguments, 'provider', '')));
        $provider = $providerInput !== '' ? $this->normalizePluginProvider($providerInput) : '';

        $pluginId = trim((string) Arr::get($arguments, 'plugin_id', Arr::get($arguments, 'pluginId', '')));
        $pluginQuery = trim((string) Arr::get(
            $arguments,
            'plugin_query',
            Arr::get(
                $arguments,
                'pluginQuery',
                Arr::get(
                    $arguments,
                    'plugin_name',
                    Arr::get($arguments, 'pluginName', Arr::get($arguments, 'name', Arr::get($arguments, 'query', '')))
                )
            )
        ));
        $versionId = trim((string) Arr::get($arguments, 'version_id', Arr::get($arguments, 'versionId', '')));
        if ($versionId === '') {
            $versionId = null;
        }

        if ($pluginId === '' && $pluginQuery === '') {
            throw new DisplayException('Provide either plugin_id or plugin_query for manager install.');
        }

        if ($pluginId !== '' && $provider === '') {
            throw new DisplayException('Provider is required when using plugin_id.');
        }

        $providers = $this->pluginInstallProviderOrder($provider);
        $lastError = null;

        foreach ($providers as $candidateProvider) {
            try {
                $selectedPlugin = null;
                $resolvedPluginId = $pluginId;
                if ($resolvedPluginId === '') {
                    $matches = $this->searchPluginManagerCandidates($candidateProvider, $pluginQuery, 6);
                    $selectedPlugin = $this->pickInstallablePluginCandidate($matches);
                    if (is_null($selectedPlugin)) {
                        throw new DisplayException(sprintf('No installable plugin found for "%s" on provider %s.', $pluginQuery, $candidateProvider));
                    }

                    $resolvedPluginId = (string) Arr::get($selectedPlugin, 'id', '');
                    if ($resolvedPluginId === '') {
                        throw new DisplayException('Plugin manager search did not return a valid plugin id.');
                    }
                }

                $plugin = $this->fetchPluginManagerDownloadDetails($candidateProvider, $resolvedPluginId, $versionId);
                $this->assertSafeDownloadUrl($plugin['url']);

                $directory = trim((string) Arr::get($arguments, 'directory', '/plugins'));
                if ($directory === '') {
                    $directory = '/plugins';
                }
                if (!str_starts_with($directory, '/')) {
                    throw new DisplayException('Directory must be an absolute path (start with "/").');
                }

                $filename = trim((string) ($plugin['name'] ?? ''));
                if ($filename !== '' && (str_contains($filename, '/') || str_contains($filename, '\\'))) {
                    throw new DisplayException('Resolved plugin filename is invalid.');
                }

                $foreground = $this->parseBoolean(Arr::get($arguments, 'foreground', true), true);
                $useHeader = $candidateProvider !== 'spigotmc';

                $payload = array_filter([
                    'url' => $plugin['url'],
                    'directory' => $directory,
                    'filename' => $filename !== '' ? $filename : null,
                    'use_header' => $useHeader,
                    'foreground' => $foreground,
                ], static fn (mixed $value): bool => !is_null($value));

                $this->pullServerFileForTarget($target, $user, $payload);

                return [
                    'ok' => true,
                    'source' => $target['source'],
                    'server_identifier' => $target['identifier'],
                    'provider' => $candidateProvider,
                    'plugin_id' => $resolvedPluginId,
                    'plugin_name' => $plugin['name'] ?? null,
                    'version_id' => $versionId,
                    'directory' => $directory,
                    'via' => 'plugin_manager',
                    'selected' => $selectedPlugin,
                    'queued' => true,
                ];
            } catch (DisplayException $exception) {
                $lastError = $exception->getMessage();
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new DisplayException(
            'Plugin manager install failed: ' . ($lastError !== null && trim($lastError) !== '' ? $lastError : 'unknown reason')
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolDownloadServerPlugin(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);
        $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_CREATE);

        $url = trim((string) Arr::get($arguments, 'url', ''));
        if ($url === '') {
            throw new DisplayException('Download URL is required.');
        }
        $this->assertSafeDownloadUrl($url);

        $directory = trim((string) Arr::get($arguments, 'directory', '/plugins'));
        if ($directory === '') {
            $directory = '/plugins';
        }
        if (!str_starts_with($directory, '/')) {
            throw new DisplayException('Directory must be an absolute path (start with "/").');
        }

        $filename = trim((string) Arr::get($arguments, 'filename', ''));
        if ($filename !== '') {
            if (str_contains($filename, '/') || str_contains($filename, '\\')) {
                throw new DisplayException('Filename must not include path separators.');
            }
            if (mb_strlen($filename) > 191) {
                throw new DisplayException('Filename is too long (max 191 characters).');
            }
        }

        $useHeader = $this->parseBoolean(Arr::get($arguments, 'use_header', true), true);
        $foreground = $this->parseBoolean(Arr::get($arguments, 'foreground', true), true);

        $payload = array_filter([
            'url' => $url,
            'directory' => $directory,
            'filename' => $filename !== '' ? $filename : null,
            'use_header' => $useHeader,
            'foreground' => $foreground,
        ], static fn (mixed $value): bool => !is_null($value));

        $this->pullServerFileForTarget($target, $user, $payload);

        return [
            'ok' => true,
            'source' => $target['source'],
            'server_identifier' => $target['identifier'],
            'url' => $url,
            'directory' => $directory,
            'filename' => $filename !== '' ? $filename : null,
            'queued' => true,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolSetServerPower(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);

        $signal = strtolower(trim((string) Arr::get($arguments, 'signal', '')));
        if (!in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
            throw new DisplayException('Signal must be one of: start, stop, restart, kill.');
        }

        $permission = match ($signal) {
            'start' => Permission::ACTION_CONTROL_START,
            'restart' => Permission::ACTION_CONTROL_RESTART,
            default => Permission::ACTION_CONTROL_STOP,
        };
        $this->assertAgentPermission($target, $user, $permission);

        if ($target['source'] === 'external') {
            $this->externalRepository->sendPowerAction($target['identifier'], $user, ['signal' => $signal]);
        } else {
            $this->daemonPowerRepository->setServer($target['server'])->send($signal);
        }

        return [
            'ok' => true,
            'source' => $target['source'],
            'server_identifier' => $target['identifier'],
            'signal' => $signal,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolReadServerFile(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);
        $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_READ_CONTENT);

        $path = trim((string) Arr::get($arguments, 'path', ''));
        if ($path === '') {
            throw new DisplayException('File path is required.');
        }

        $content = $this->readServerFileContentForTarget($target, $user, $path, self::MAX_AGENT_FILE_READ_BYTES);

        $truncated = false;
        if (mb_strlen($content) > self::MAX_AGENT_FILE_READ_BYTES) {
            $content = mb_substr($content, 0, self::MAX_AGENT_FILE_READ_BYTES);
            $truncated = true;
        }

        return [
            'ok' => true,
            'source' => $target['source'],
            'server_identifier' => $target['identifier'],
            'path' => $path,
            'truncated' => $truncated,
            'content' => $content,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolEditServerFile(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);
        $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_UPDATE);
        $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_READ_CONTENT);

        $path = trim((string) Arr::get($arguments, 'path', ''));
        if ($path === '') {
            throw new DisplayException('File path is required.');
        }

        $edits = Arr::get($arguments, 'edits', []);
        if (!is_array($edits) || $edits === []) {
            throw new DisplayException('At least one edit operation is required.');
        }

        if (count($edits) > 64) {
            throw new DisplayException('Too many edit operations in one request (max 64).');
        }

        $createIfMissing = $this->parseBoolean(Arr::get($arguments, 'create_if_missing', true), true);
        $content = $this->readServerFileContentForTarget(
            $target,
            $user,
            $path,
            self::MAX_AGENT_EDIT_FILE_BYTES + 1,
            $createIfMissing
        );

        if (strlen($content) > self::MAX_AGENT_EDIT_FILE_BYTES) {
            throw new DisplayException(sprintf('File is too large to edit safely (max %d bytes).', self::MAX_AGENT_EDIT_FILE_BYTES));
        }

        [$nextContent, $applied] = $this->applySafeFileEdits($content, $edits);

        if ($nextContent === $content) {
            return [
                'ok' => true,
                'source' => $target['source'],
                'server_identifier' => $target['identifier'],
                'path' => $path,
                'changed' => false,
                'applied' => $applied,
                'bytes_written' => 0,
            ];
        }

        if (strlen($nextContent) > self::MAX_AGENT_EDIT_FILE_BYTES) {
            throw new DisplayException(sprintf('Edited file is too large (max %d bytes).', self::MAX_AGENT_EDIT_FILE_BYTES));
        }

        $this->writeServerFileContentForTarget($target, $user, $path, $nextContent);

        return [
            'ok' => true,
            'source' => $target['source'],
            'server_identifier' => $target['identifier'],
            'path' => $path,
            'changed' => true,
            'applied' => $applied,
            'bytes_written' => strlen($nextContent),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolWriteServerFile(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $target = $this->resolveAgentServerTarget($arguments, $defaultServerIdentifier, $user);
        $this->assertAgentPermission($target, $user, Permission::ACTION_FILE_UPDATE);

        $path = trim((string) Arr::get($arguments, 'path', ''));
        if ($path === '') {
            throw new DisplayException('File path is required.');
        }

        $allowOverwrite = $this->parseBoolean(Arr::get($arguments, 'allow_overwrite', false), false);
        if (!$allowOverwrite) {
            throw new DisplayException('Direct overwrite is disabled for safety. Use edit_server_file for targeted edits.');
        }

        $content = (string) Arr::get($arguments, 'content', '');
        if (strlen($content) > self::MAX_AGENT_FILE_WRITE_BYTES) {
            throw new DisplayException(sprintf('File content too large (max %d bytes).', self::MAX_AGENT_FILE_WRITE_BYTES));
        }

        $this->writeServerFileContentForTarget($target, $user, $path, $content);

        return [
            'ok' => true,
            'source' => $target['source'],
            'server_identifier' => $target['identifier'],
            'path' => $path,
            'bytes_written' => strlen($content),
        ];
    }

    /**
     * @param array{source: 'local'|'external', identifier: string, server?: Server} $target
     */
    private function readServerFileContentForTarget(
        array $target,
        User $user,
        string $path,
        int $maxBytes,
        bool $allowMissing = false
    ): string {
        try {
            if ($target['source'] === 'external') {
                return $this->externalRepository->proxyText(
                    $target['identifier'],
                    $user,
                    'GET',
                    [
                        $this->externalFileEndpoint($target['identifier'], 'files/contents'),
                        $this->externalFileEndpoint($target['identifier'], 'files/content'),
                    ],
                    new Request(),
                    ['query' => ['file' => $path]]
                );
            }

            return $this->daemonFileRepository->setServer($target['server'])->getContent($path, $maxBytes);
        } catch (\Throwable $exception) {
            if ($allowMissing && $this->isMissingFileReadException($exception)) {
                return '';
            }

            throw $exception;
        }
    }

    /**
     * @param array{source: 'local'|'external', identifier: string, server?: Server} $target
     */
    private function writeServerFileContentForTarget(array $target, User $user, string $path, string $content): void
    {
        if ($target['source'] === 'external') {
            $this->externalRepository->proxyNoContent(
                $target['identifier'],
                $user,
                'POST',
                $this->externalFileEndpoint($target['identifier'], 'files/write'),
                [
                    'query' => ['file' => $path],
                    'body' => $content,
                    'headers' => ['Content-Type' => 'text/plain'],
                ]
            );

            return;
        }

        $this->daemonFileRepository->setServer($target['server'])->putContent($path, $content);
    }

    /**
     * @param array{source: 'local'|'external', identifier: string, server?: Server} $target
     * @param array{url: string, directory: string, filename?: ?string, use_header?: bool, foreground?: bool} $payload
     */
    private function pullServerFileForTarget(array $target, User $user, array $payload): void
    {
        if ($target['source'] === 'external') {
            try {
                $this->externalRepository->proxyNoContent(
                    $target['identifier'],
                    $user,
                    'POST',
                    $this->externalFileEndpoint($target['identifier'], 'files/pull'),
                    ['json' => $payload]
                );
            } catch (\Throwable $exception) {
                if (!$this->shouldUseExternalUploadFallback($exception)) {
                    throw $exception;
                }

                $this->externalUploadPulledFileForTarget($target, $user, $payload);
            }

            return;
        }

        $params = [
            'use_header' => $this->parseBoolean(Arr::get($payload, 'use_header', true), true),
            'foreground' => $this->parseBoolean(Arr::get($payload, 'foreground', true), true),
        ];

        $filename = trim((string) Arr::get($payload, 'filename', ''));
        if ($filename !== '') {
            $params['filename'] = $filename;
        }

        $this->daemonFileRepository->setServer($target['server'])->pull(
            (string) Arr::get($payload, 'url', ''),
            (string) Arr::get($payload, 'directory', '/'),
            $params
        );
    }

    private function shouldUseExternalUploadFallback(\Throwable $exception): bool
    {
        $statusCode = method_exists($exception, 'getStatusCode')
            ? (int) $exception->getStatusCode()
            : null;

        if (in_array($statusCode, [404, 405, 501], true)) {
            return true;
        }

        $message = strtolower(trim($exception->getMessage()));
        if ($message === '') {
            return false;
        }

        foreach ([
            'this feature is not supported by the connected external panel',
            'method not allowed',
            'not implemented',
            'endpoint not found',
            'not found',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{source: 'local'|'external', identifier: string, server?: Server} $target
     * @param array{url: string, directory: string, filename?: ?string, use_header?: bool, foreground?: bool} $payload
     */
    private function externalUploadPulledFileForTarget(array $target, User $user, array $payload): void
    {
        $sourceUrl = trim((string) Arr::get($payload, 'url', ''));
        if ($sourceUrl === '') {
            throw new DisplayException('Plugin source URL is missing for external upload fallback.');
        }
        $this->assertSafeDownloadUrl($sourceUrl);

        $directory = trim((string) Arr::get($payload, 'directory', '/plugins'));
        if ($directory === '') {
            $directory = '/plugins';
        }

        $uploadPayload = $this->externalRepository->proxyJson(
            $target['identifier'],
            $user,
            'GET',
            $this->externalFileEndpoint($target['identifier'], 'files/upload')
        );
        $uploadUrl = $this->extractExternalUploadUrl($uploadPayload);
        if ($uploadUrl === null) {
            throw new DisplayException('The external panel does not provide a usable file upload endpoint.');
        }

        $targetUrl = $this->appendDirectoryQueryToUrl($uploadUrl, $directory);
        $filename = $this->resolveUploadFilenameFromPayload($payload);

        $downloadResponse = Http::timeout(180)
            ->connectTimeout(12)
            ->withHeaders(['Accept' => '*/*'])
            ->get($sourceUrl);

        if (!$downloadResponse->successful()) {
            throw new DisplayException('Failed to download plugin file: ' . $this->extractHttpResponseErrorMessage($downloadResponse));
        }

        $binary = $downloadResponse->body();
        if ($binary === '') {
            throw new DisplayException('Downloaded plugin file is empty.');
        }

        $uploadResponse = Http::timeout(300)
            ->connectTimeout(12)
            ->attach('files', $binary, $filename)
            ->post($targetUrl);

        if (!$uploadResponse->successful()) {
            throw new DisplayException('External upload fallback failed: ' . $this->extractHttpResponseErrorMessage($uploadResponse));
        }
    }

    private function extractExternalUploadUrl(array $payload): ?string
    {
        foreach (['attributes.url', 'data.attributes.url', 'data.url', 'url'] as $path) {
            $value = trim((string) Arr::get($payload, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function appendDirectoryQueryToUrl(string $url, string $directory): string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $query['directory'] = $directory;
        $parsed['query'] = http_build_query($query);

        return $this->buildUrlFromParsedParts($parsed);
    }

    private function buildUrlFromParsedParts(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return sprintf('%s%s%s%s%s%s%s', $scheme, $auth, $host, $port, $path, $query, $fragment);
    }

    /**
     * @param array{url: string, directory: string, filename?: ?string, use_header?: bool, foreground?: bool} $payload
     */
    private function resolveUploadFilenameFromPayload(array $payload): string
    {
        $filename = trim((string) Arr::get($payload, 'filename', ''));

        if ($filename === '') {
            $path = (string) parse_url((string) Arr::get($payload, 'url', ''), PHP_URL_PATH);
            $candidate = basename($path);
            if (is_string($candidate) && $candidate !== '' && $candidate !== '/' && $candidate !== '\\') {
                $filename = $candidate;
            }
        }

        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'plugin.jar';
        }

        $filename = str_replace(['/', '\\', "\0", "\r", "\n"], '', $filename);
        if ($filename === '') {
            $filename = 'plugin.jar';
        }

        if (mb_strlen($filename) > 191) {
            $filename = mb_substr($filename, 0, 191);
        }

        return $filename;
    }

    private function extractHttpResponseErrorMessage(Response $response): string
    {
        $payload = $response->json();
        if (is_array($payload)) {
            foreach (['errors.0.detail', 'error', 'message'] as $path) {
                $value = trim((string) Arr::get($payload, $path, ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $body = trim($response->body());
        if ($body !== '') {
            return mb_substr($body, 0, 300);
        }

        return sprintf('Request failed with status code %d.', $response->status());
    }

    private function defaultPluginProvider(): string
    {
        $configured = strtolower(trim((string) MCPluginsConfig::query()->value('default_provider')));

        return in_array($configured, $this->pluginManagerProviders(), true) ? $configured : 'modrinth';
    }

    /**
     * @return array<int, string>
     */
    private function pluginManagerProviders(): array
    {
        return ['modrinth', 'curseforge', 'spigotmc', 'hangar', 'polymart'];
    }

    private function normalizePluginProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));

        if (in_array($normalized, $this->pluginManagerProviders(), true)) {
            return $normalized;
        }

        return $this->defaultPluginProvider();
    }

    /**
     * @return array<int, string>
     */
    private function pluginInstallProviderOrder(string $requestedProvider = ''): array
    {
        $requested = strtolower(trim($requestedProvider));
        if ($requested !== '' && in_array($requested, $this->pluginManagerProviders(), true)) {
            return [$requested];
        }

        $order = [];
        $default = $this->defaultPluginProvider();
        if (in_array($default, $this->pluginManagerProviders(), true)) {
            $order[] = $default;
        }

        foreach (['modrinth', 'curseforge', 'hangar', 'spigotmc', 'polymart'] as $provider) {
            if (!in_array($provider, $order, true)) {
                $order[] = $provider;
            }
        }

        return $order;
    }

    private function pluginManagerHttpClient(string $provider): Client
    {
        return match ($provider) {
            'modrinth' => new Client(['base_uri' => 'https://api.modrinth.com/v2/']),
            'curseforge' => new Client([
                'base_uri' => 'https://api.curseforge.com/v1/',
                'headers' => [
                    'X-API-Key' => (string) (MCPluginsConfig::first()?->curseforge_api_key ?? ''),
                ],
            ]),
            'spigotmc' => new Client(['base_uri' => 'https://api.spiget.org/v2/']),
            'hangar' => new Client(['base_uri' => 'https://hangar.papermc.io/api/v1/']),
            'polymart' => new Client(['base_uri' => 'https://api.polymart.org/v1/']),
            default => throw new DisplayException(sprintf('Unsupported plugin provider: %s', $provider)),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchPluginManagerCandidates(string $provider, string $query, int $limit): array
    {
        $provider = $this->normalizePluginProvider($provider);
        $client = $this->pluginManagerHttpClient($provider);
        $limit = max(1, min(10, $limit));

        try {
            $payload = match ($provider) {
                'modrinth' => $this->decodeJsonResponse(
                    (string) $client->get('search', [
                        'query' => [
                            'limit' => $limit,
                            'query' => $query,
                            'index' => 'relevance',
                            'offset' => 0,
                            'facets' => json_encode([
                                ['server_side!=unsupported'],
                            ]),
                        ],
                    ])->getBody(),
                    'modrinth search'
                ),
                'curseforge' => $this->decodeJsonResponse(
                    (string) $client->get('mods/search', [
                        'query' => [
                            'gameId' => 432,
                            'classId' => 5,
                            'pageSize' => $limit,
                            'index' => 0,
                            'searchFilter' => $query,
                            'sortOrder' => 'desc',
                        ],
                    ])->getBody(),
                    'curseforge search'
                ),
                'spigotmc' => $this->decodeJsonResponse(
                    (string) $client->get(
                        sprintf('search/resources/%s', rawurlencode($query)),
                        ['query' => ['size' => $limit, 'page' => 1, 'sort' => '-downloads']]
                    )->getBody(),
                    'spigotmc search'
                ),
                'hangar' => $this->decodeJsonResponse(
                    (string) $client->get('projects', [
                        'query' => [
                            'limit' => $limit,
                            'offset' => 0,
                            'query' => $query,
                            'sort' => 'stars',
                        ],
                    ])->getBody(),
                    'hangar search'
                ),
                'polymart' => $this->decodeJsonResponse(
                    (string) $client->get('search', [
                        'query' => [
                            'limit' => $limit,
                            'start' => 1,
                            'query' => $query,
                            'sort' => 'relevance',
                        ],
                    ])->getBody(),
                    'polymart search'
                ),
            };
        } catch (\Throwable $exception) {
            throw new DisplayException('Plugin manager search failed: ' . $exception->getMessage());
        }

        return match ($provider) {
            'modrinth' => array_values(array_map(static fn (array $plugin): array => [
                'provider' => 'modrinth',
                'id' => (string) ($plugin['project_id'] ?? ''),
                'name' => (string) ($plugin['title'] ?? ''),
                'description' => (string) ($plugin['description'] ?? ''),
                'url' => isset($plugin['project_id']) ? 'https://modrinth.com/plugin/' . $plugin['project_id'] : null,
                'downloads' => (int) ($plugin['downloads'] ?? 0),
                'installable' => true,
            ], array_filter($payload['hits'] ?? [], static fn (mixed $item): bool => is_array($item)))),
            'curseforge' => array_values(array_map(static fn (array $plugin): array => [
                'provider' => 'curseforge',
                'id' => (string) ($plugin['id'] ?? ''),
                'name' => (string) ($plugin['name'] ?? ''),
                'description' => (string) ($plugin['summary'] ?? ''),
                'url' => isset($plugin['slug']) ? 'https://www.curseforge.com/minecraft/bukkit-plugins/' . $plugin['slug'] : null,
                'downloads' => (int) ($plugin['downloadCount'] ?? 0),
                'installable' => true,
            ], array_filter($payload['data'] ?? [], static fn (mixed $item): bool => is_array($item)))),
            'spigotmc' => array_values(array_map(static function (array $plugin): array {
                $externalUrl = Arr::get($plugin, 'file.externalUrl');
                $installable = !(is_string($externalUrl) && $externalUrl !== '' && !str_ends_with($externalUrl, '.jar'));
                if (!empty($plugin['premium'])) {
                    $installable = false;
                }

                return [
                    'provider' => 'spigotmc',
                    'id' => (string) ($plugin['id'] ?? ''),
                    'name' => (string) ($plugin['name'] ?? ''),
                    'description' => (string) ($plugin['tag'] ?? ''),
                    'url' => isset($plugin['id']) ? 'https://www.spigotmc.org/resources/' . $plugin['id'] : null,
                    'downloads' => (int) ($plugin['downloads'] ?? 0),
                    'installable' => $installable,
                ];
            }, array_filter($payload, static fn (mixed $item): bool => is_array($item)))),
            'hangar' => array_values(array_map(static fn (array $plugin): array => [
                'provider' => 'hangar',
                'id' => (string) ($plugin['name'] ?? ''),
                'name' => (string) ($plugin['name'] ?? ''),
                'description' => (string) ($plugin['description'] ?? ''),
                'url' => (isset($plugin['namespace']['owner'], $plugin['name'])
                    ? sprintf('https://hangar.papermc.io/%s/%s', $plugin['namespace']['owner'], $plugin['name'])
                    : null),
                'downloads' => (int) Arr::get($plugin, 'stats.downloads', 0),
                'installable' => true,
            ], array_filter($payload['result'] ?? [], static fn (mixed $item): bool => is_array($item)))),
            'polymart' => array_values(array_map(static fn (array $plugin): array => [
                'provider' => 'polymart',
                'id' => (string) ($plugin['id'] ?? ''),
                'name' => (string) ($plugin['title'] ?? ''),
                'description' => (string) ($plugin['subtitle'] ?? ''),
                'url' => (string) ($plugin['url'] ?? ''),
                'downloads' => (int) ($plugin['totalDownloads'] ?? 0),
                'installable' => (bool) ($plugin['canDownload'] ?? false),
            ], array_filter($payload['response']['result'] ?? [], static fn (mixed $item): bool => is_array($item)))),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function pickInstallablePluginCandidate(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $id = trim((string) Arr::get($candidate, 'id', ''));
            if ($id === '') {
                continue;
            }

            $installable = Arr::get($candidate, 'installable', true);
            if ($this->parseBoolean($installable, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{url: string, name: string}
     */
    private function fetchPluginManagerDownloadDetails(string $provider, string $pluginId, ?string $versionId): array
    {
        $provider = $this->normalizePluginProvider($provider);
        $pluginId = trim($pluginId);
        if ($pluginId === '') {
            throw new DisplayException('Plugin id is required.');
        }

        try {
            return match ($provider) {
                'modrinth' => $this->fetchPluginManagerModrinthData($pluginId, $versionId),
                'curseforge' => $this->fetchPluginManagerCurseForgeData($pluginId, $versionId),
                'hangar' => $this->fetchPluginManagerHangarData($pluginId, $versionId),
                'spigotmc' => $this->fetchPluginManagerSpigotData($pluginId),
                'polymart' => $this->fetchPluginManagerPolymartData($pluginId),
                default => throw new DisplayException(sprintf('Unsupported plugin provider: %s', $provider)),
            };
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new DisplayException('Plugin manager install failed: ' . $exception->getMessage());
        }
    }

    /**
     * @return array{url: string, name: string}
     */
    private function fetchPluginManagerModrinthData(string $pluginId, ?string $versionId): array
    {
        $client = $this->pluginManagerHttpClient('modrinth');
        $payload = $this->decodeJsonResponse(
            (string) $client->get($versionId ? "version/{$versionId}" : "project/{$pluginId}/version")->getBody(),
            'modrinth plugin'
        );

        $pluginFile = $versionId ? ($payload['files'][0] ?? null) : ($payload[0]['files'][0] ?? null);
        $url = is_array($pluginFile) ? (string) ($pluginFile['url'] ?? '') : '';
        if ($url === '') {
            throw new DisplayException('Modrinth download URL not found.');
        }

        return [
            'url' => $url,
            'name' => (string) ($pluginFile['filename'] ?? 'plugin.jar'),
        ];
    }

    /**
     * @return array{url: string, name: string}
     */
    private function fetchPluginManagerCurseForgeData(string $pluginId, ?string $versionId): array
    {
        $client = $this->pluginManagerHttpClient('curseforge');
        $payload = $this->decodeJsonResponse(
            (string) $client->get($versionId ? "mods/{$pluginId}/files/{$versionId}" : "mods/{$pluginId}/files")->getBody(),
            'curseforge plugin'
        );

        $pluginFile = $versionId ? ($payload['data'] ?? null) : ($payload['data'][0] ?? null);
        $downloadUrl = is_array($pluginFile) ? (string) ($pluginFile['downloadUrl'] ?? '') : '';
        if ($downloadUrl === '') {
            throw new DisplayException('CurseForge download URL not found.');
        }

        return [
            'url' => str_replace('edge', 'mediafiles', $downloadUrl),
            'name' => (string) ($pluginFile['fileName'] ?? 'plugin.jar'),
        ];
    }

    /**
     * @return array{url: string, name: string}
     */
    private function fetchPluginManagerHangarData(string $pluginId, ?string $versionId): array
    {
        $client = $this->pluginManagerHttpClient('hangar');
        if (!is_null($versionId) && trim($versionId) !== '') {
            $versionNumber = $versionId;
            $serverType = 'PAPER';
            if (str_contains($versionId, ' - ')) {
                [$versionNumber, $serverType] = explode(' - ', $versionId, 2);
            }
            $payload = $this->decodeJsonResponse(
                (string) $client->get(sprintf('projects/%s/versions/%s', $pluginId, $versionNumber))->getBody(),
                'hangar plugin'
            );
            $download = Arr::get($payload, 'downloads.' . strtoupper(trim($serverType)));
        } else {
            $payload = $this->decodeJsonResponse(
                (string) $client->get("projects/{$pluginId}/versions")->getBody(),
                'hangar plugin versions'
            );
            $download = Arr::get($payload, 'result.0.downloads.PAPER');
        }

        if (!is_array($download)) {
            throw new DisplayException('Hangar download details not found.');
        }

        $url = (string) ($download['downloadUrl'] ?? ($download['externalUrl'] ?? ''));
        if ($url === '') {
            throw new DisplayException('Hangar download URL not found.');
        }

        return [
            'url' => $url,
            'name' => (string) Arr::get($download, 'fileInfo.name', 'plugin.jar'),
        ];
    }

    /**
     * @return array{url: string, name: string}
     */
    private function fetchPluginManagerSpigotData(string $pluginId): array
    {
        $client = $this->pluginManagerHttpClient('spigotmc');
        $plugin = $this->decodeJsonResponse(
            (string) $client->get("resources/{$pluginId}")->getBody(),
            'spigot plugin'
        );

        $externalUrl = Arr::get($plugin, 'file.externalUrl');
        $downloadUrl = (is_string($externalUrl) && str_ends_with($externalUrl, '.jar'))
            ? $externalUrl
            : "https://cdn.spiget.org/file/spiget-resources/{$pluginId}.jar";

        return [
            'url' => $downloadUrl,
            'name' => ((string) ($plugin['name'] ?? 'plugin')) . '.jar',
        ];
    }

    /**
     * @return array{url: string, name: string}
     */
    private function fetchPluginManagerPolymartData(string $pluginId): array
    {
        $client = $this->pluginManagerHttpClient('polymart');
        $downloadPayload = $this->decodeJsonResponse(
            (string) $client->post('getDownloadURL', [
                'form_params' => [
                    'allow_redirects' => '0',
                    'resource_id' => $pluginId,
                ],
            ])->getBody(),
            'polymart download url'
        );

        $downloadUrl = (string) Arr::get($downloadPayload, 'response.result.url', '');
        if ($downloadUrl === '') {
            throw new DisplayException('Polymart download URL not found.');
        }

        $resourcePayload = $this->decodeJsonResponse(
            (string) $client->get('getResourceInfo', ['query' => ['resource_id' => $pluginId]])->getBody(),
            'polymart resource'
        );

        return [
            'url' => $downloadUrl,
            'name' => ((string) Arr::get($resourcePayload, 'response.resource.title', 'plugin')) . '.jar',
        ];
    }

    private function decodeJsonResponse(string $body, string $context): array
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new DisplayException(sprintf('Invalid JSON response from %s.', $context));
        }

        return $payload;
    }

    private function isMissingFileReadException(\Throwable $exception): bool
    {
        $message = strtolower(trim($exception->getMessage()));
        if ($message === '') {
            return false;
        }

        foreach (['not found', 'no such file', 'does not exist', 'cannot find', '404'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $edits
     * @return array{0: string, 1: array<int, string>}
     */
    private function applySafeFileEdits(string $content, array $edits): array
    {
        $usesCrlf = str_contains($content, "\r\n");
        $working = str_replace(["\r\n", "\r"], "\n", $content);
        $applied = [];

        foreach ($edits as $index => $edit) {
            if (!is_array($edit)) {
                throw new DisplayException(sprintf('Edit operation at index %d must be an object.', $index + 1));
            }

            [$working, $summary] = $this->applySingleSafeEdit($working, $edit, $index);
            $applied[] = $summary;
        }

        $final = $usesCrlf ? str_replace("\n", "\r\n", $working) : $working;

        return [$final, $applied];
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{0: string, 1: string}
     */
    private function applySingleSafeEdit(string $content, array $edit, int $index): array
    {
        $action = strtolower(trim((string) Arr::get($edit, 'action', '')));
        $position = $index + 1;

        return match ($action) {
            'set_key_value' => (function () use ($content, $edit, $position): array {
                $key = trim((string) Arr::get($edit, 'key', ''));
                if ($key === '') {
                    throw new DisplayException(sprintf('Edit %d: "key" is required for set_key_value.', $position));
                }

                $value = (string) Arr::get($edit, 'value', '');
                if (str_contains($value, "\n") || str_contains($value, "\r")) {
                    throw new DisplayException(sprintf('Edit %d: "value" for set_key_value must be a single line.', $position));
                }

                $delimiter = (string) Arr::get($edit, 'delimiter', '=');
                if ($delimiter === '') {
                    $delimiter = '=';
                }

                $replaceAll = $this->parseBoolean(Arr::get($edit, 'all', false), false);
                $ifMissing = $this->parseBoolean(Arr::get($edit, 'if_missing', true), true);
                $pattern = '/^(\s*' . preg_quote($key, '/') . '\s*' . preg_quote($delimiter, '/') . '\s*).*$/' . 'm';

                $replaced = 0;
                $next = preg_replace_callback(
                    $pattern,
                    static fn (array $matches): string => $matches[1] . $value,
                    $content,
                    $replaceAll ? -1 : 1,
                    $replaced
                );

                if (!is_string($next)) {
                    throw new DisplayException(sprintf('Edit %d failed while applying set_key_value.', $position));
                }

                if ($replaced === 0) {
                    if (!$ifMissing) {
                        throw new DisplayException(sprintf('Edit %d: key "%s" not found in file.', $position, $key));
                    }

                    return [$this->appendLine($content, $key . $delimiter . $value), sprintf('set_key_value:%s (appended)', $key)];
                }

                return [$next, sprintf('set_key_value:%s', $key)];
            })(),
            'replace_text' => (function () use ($content, $edit, $position): array {
                $search = (string) Arr::get($edit, 'search', '');
                if ($search === '') {
                    throw new DisplayException(sprintf('Edit %d: "search" is required for replace_text.', $position));
                }

                $replace = (string) Arr::get($edit, 'replace', '');
                $replaceAll = $this->parseBoolean(Arr::get($edit, 'all', false), false);
                $ifMissing = $this->parseBoolean(Arr::get($edit, 'if_missing', false), false);

                if ($replaceAll) {
                    $next = str_replace($search, $replace, $content, $count);
                    if ($count === 0 && !$ifMissing) {
                        throw new DisplayException(sprintf('Edit %d: target text not found for replace_text.', $position));
                    }

                    return [$next, sprintf('replace_text:%d', $count)];
                }

                $offset = strpos($content, $search);
                if ($offset === false) {
                    if ($ifMissing) {
                        return [$content, 'replace_text:0'];
                    }

                    throw new DisplayException(sprintf('Edit %d: target text not found for replace_text.', $position));
                }

                $next = substr_replace($content, $replace, $offset, strlen($search));

                return [$next, 'replace_text:1'];
            })(),
            'remove_text' => (function () use ($content, $edit, $position): array {
                $search = (string) Arr::get($edit, 'search', '');
                if ($search === '') {
                    throw new DisplayException(sprintf('Edit %d: "search" is required for remove_text.', $position));
                }

                $removeAll = $this->parseBoolean(Arr::get($edit, 'all', true), true);
                $ifMissing = $this->parseBoolean(Arr::get($edit, 'if_missing', false), false);

                if ($removeAll) {
                    $next = str_replace($search, '', $content, $count);
                    if ($count === 0 && !$ifMissing) {
                        throw new DisplayException(sprintf('Edit %d: target text not found for remove_text.', $position));
                    }

                    return [$next, sprintf('remove_text:%d', $count)];
                }

                $offset = strpos($content, $search);
                if ($offset === false) {
                    if ($ifMissing) {
                        return [$content, 'remove_text:0'];
                    }

                    throw new DisplayException(sprintf('Edit %d: target text not found for remove_text.', $position));
                }

                $next = substr_replace($content, '', $offset, strlen($search));

                return [$next, 'remove_text:1'];
            })(),
            'append_text' => (function () use ($content, $edit, $position): array {
                $text = (string) Arr::get($edit, 'text', '');
                if ($text === '') {
                    throw new DisplayException(sprintf('Edit %d: "text" is required for append_text.', $position));
                }

                $ifMissing = $this->parseBoolean(Arr::get($edit, 'if_missing', false), false);
                if ($ifMissing && str_contains($content, $text)) {
                    return [$content, 'append_text:0'];
                }

                return [$this->appendTextWithLineGap($content, $text), 'append_text:1'];
            })(),
            'prepend_text' => (function () use ($content, $edit, $position): array {
                $text = (string) Arr::get($edit, 'text', '');
                if ($text === '') {
                    throw new DisplayException(sprintf('Edit %d: "text" is required for prepend_text.', $position));
                }

                $ifMissing = $this->parseBoolean(Arr::get($edit, 'if_missing', false), false);
                if ($ifMissing && str_contains($content, $text)) {
                    return [$content, 'prepend_text:0'];
                }

                return [$this->prependTextWithLineGap($content, $text), 'prepend_text:1'];
            })(),
            'ensure_line' => (function () use ($content, $edit, $position): array {
                $line = str_replace(["\r\n", "\r"], "\n", (string) Arr::get($edit, 'line', ''));
                $line = trim($line, "\n");
                if ($line === '') {
                    throw new DisplayException(sprintf('Edit %d: "line" is required for ensure_line.', $position));
                }

                $lines = explode("\n", $content);
                if (in_array($line, $lines, true)) {
                    return [$content, 'ensure_line:0'];
                }

                return [$this->appendLine($content, $line), 'ensure_line:1'];
            })(),
            'remove_line' => (function () use ($content, $edit, $position): array {
                $line = str_replace(["\r\n", "\r"], "\n", (string) Arr::get($edit, 'line', ''));
                $line = trim($line, "\n");
                if ($line === '') {
                    throw new DisplayException(sprintf('Edit %d: "line" is required for remove_line.', $position));
                }

                $contains = $this->parseBoolean(Arr::get($edit, 'contains', false), false);
                $removeAll = $this->parseBoolean(Arr::get($edit, 'all', true), true);
                $ifMissing = $this->parseBoolean(Arr::get($edit, 'if_missing', false), false);

                $removed = 0;
                $nextLines = [];
                foreach (explode("\n", $content) as $currentLine) {
                    $matched = $contains ? str_contains($currentLine, $line) : $currentLine === $line;
                    if ($matched && ($removeAll || $removed === 0)) {
                        $removed++;
                        continue;
                    }

                    $nextLines[] = $currentLine;
                }

                if ($removed === 0 && !$ifMissing) {
                    throw new DisplayException(sprintf('Edit %d: target line not found for remove_line.', $position));
                }

                return [implode("\n", $nextLines), sprintf('remove_line:%d', $removed)];
            })(),
            default => throw new DisplayException(sprintf('Edit %d: unsupported action "%s".', $position, $action)),
        };
    }

    private function parseBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function appendTextWithLineGap(string $content, string $text): string
    {
        if ($content === '') {
            return $text;
        }

        if (str_ends_with($content, "\n") || str_starts_with($text, "\n")) {
            return $content . $text;
        }

        return $content . "\n" . $text;
    }

    private function prependTextWithLineGap(string $content, string $text): string
    {
        if ($content === '') {
            return $text;
        }

        if (str_ends_with($text, "\n") || str_starts_with($content, "\n")) {
            return $text . $content;
        }

        return $text . "\n" . $content;
    }

    private function appendLine(string $content, string $line): string
    {
        if ($content === '') {
            return $line;
        }

        if (!str_ends_with($content, "\n")) {
            return $content . "\n" . $line;
        }

        return $content . $line;
    }

    private function extractTailLogLines(string $content, int $tailLines, int $maxBytes): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $normalized);
        $tail = implode("\n", array_slice($lines, -$tailLines));

        if (strlen($tail) > $maxBytes) {
            $tail = substr($tail, -$maxBytes);
        }

        return trim($tail);
    }

    private function assertSafeDownloadUrl(string $url): void
    {
        if (strlen($url) > 2048) {
            throw new DisplayException('Download URL is too long.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new DisplayException('Download URL is invalid.');
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new DisplayException('Download URL must use http or https.');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            throw new DisplayException('Download URL must include a valid host.');
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local')) {
            throw new DisplayException('Local/private hosts are not allowed for plugin downloads.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($isPublic === false) {
                throw new DisplayException('Private or reserved IP addresses are not allowed for plugin downloads.');
            }
        }
    }

    private function externalFileEndpoint(string $identifier, string $suffix): string
    {
        $trimmed = trim($identifier);
        if (!str_starts_with($trimmed, 'external:')) {
            throw new DisplayException('Invalid external server identifier.');
        }

        try {
            $parts = ExternalServerReference::parseCompositeIdentifier($trimmed);
        } catch (\Throwable) {
            throw new DisplayException('Invalid external server identifier.');
        }

        return sprintf('servers/%s/%s', $parts['server_identifier'], ltrim($suffix, '/'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{source: 'local'|'external', identifier: string, server?: Server, permissions?: array<int, string>}
     */
    private function resolveAgentServerTarget(array $arguments, string $defaultServerIdentifier, User $user): array
    {
        $serverIdentifier = trim((string) Arr::get($arguments, 'server_identifier', ''));
        if ($serverIdentifier === '') {
            $serverIdentifier = trim($defaultServerIdentifier);
        }

        if ($serverIdentifier === '') {
            throw new DisplayException('No server context is available for this action. Open a server page first.');
        }

        if (str_starts_with($serverIdentifier, 'external:')) {
            try {
                // Access check and data hydration.
                $this->externalRepository->getServer($serverIdentifier, $user);
                $permissions = $this->externalRepository->getPermissions($serverIdentifier, $user);
            } catch (\Throwable) {
                throw new DisplayException('External server not found or not accessible for this account.');
            }

            return [
                'source' => 'external',
                'identifier' => $serverIdentifier,
                'permissions' => array_values(array_filter(array_map(static fn ($item) => is_string($item) ? trim($item) : '', $permissions))),
            ];
        }

        $localServerQuery = $user->root_admin ? Server::query() : $user->accessibleServers();
        $server = $localServerQuery
            ->where(function ($builder) use ($serverIdentifier) {
                $builder->where('servers.uuidShort', $serverIdentifier)
                    ->orWhere('servers.uuid', $serverIdentifier);

                if (ctype_digit($serverIdentifier)) {
                    $builder->orWhere('servers.id', (int) $serverIdentifier);
                }
            })
            ->first();

        if (!$server instanceof Server) {
            throw new DisplayException('Server not found or not accessible for this account.');
        }

        return [
            'source' => 'local',
            'identifier' => $serverIdentifier,
            'server' => $server,
        ];
    }

    /**
     * @param array{source: 'local'|'external', identifier: string, server?: Server, permissions?: array<int, string>} $target
     */
    private function assertAgentPermission(array $target, User $user, string $permission): void
    {
        if ((bool) config('services.ai_assistant.full_server_access', false)) {
            return;
        }

        if ($target['source'] === 'local') {
            $server = Arr::get($target, 'server');
            if (!$server instanceof Server || !$user->can($permission, $server)) {
                throw new DisplayException(sprintf('Missing permission for this action: %s', $permission));
            }

            return;
        }

        $permissions = Arr::get($target, 'permissions', []);
        if (!is_array($permissions)) {
            $permissions = [];
        }

        if (!in_array($permission, $permissions, true) && !in_array('*', $permissions, true)) {
            throw new DisplayException(sprintf('Missing external permission for this action: %s', $permission));
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{0: string, 1: string}
     */
    private function requestGemini(
        string $apiKey,
        string $baseUrl,
        string $model,
        string $systemPrompt,
        float $temperature,
        array $history,
        string $message
    ): array {
        $contents = [];
        foreach ($history as $entry) {
            $contents[] = [
                'role' => $entry['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $entry['content']]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $message]],
        ];

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
            ],
        ];

        if ($systemPrompt !== '') {
            $payload['system_instruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        $lastModelUnavailableResponse = null;
        foreach ($this->geminiModelCandidates($model) as $candidateModel) {
            $endpointModel = str_starts_with($candidateModel, 'models/') ? $candidateModel : 'models/' . $candidateModel;

            try {
                $response = Http::timeout(45)
                    ->connectTimeout(10)
                    ->acceptJson()
                    ->withOptions(['query' => ['key' => $apiKey]])
                    ->post($baseUrl . '/' . $endpointModel . ':generateContent', $payload);
            } catch (ConnectionException) {
                throw new DisplayException('Failed to connect to GEMINI API. Please try again shortly.');
            }

            if (!$response->successful()) {
                if ($this->isGeminiModelUnavailable($response)) {
                    $lastModelUnavailableResponse = $response;
                    continue;
                }

                $this->throwProviderApiError('gemini', $response);
            }

            $json = is_array($response->json()) ? $response->json() : [];
            $parts = Arr::get($json, 'candidates.0.content.parts', []);
            $replyParts = [];
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (!is_array($part)) {
                        continue;
                    }

                    $text = trim((string) Arr::get($part, 'text', ''));
                    if ($text !== '') {
                        $replyParts[] = $text;
                    }
                }
            }

            $reply = trim(implode("\n", $replyParts));
            if ($reply === '') {
                $finishReason = strtoupper(trim((string) Arr::get($json, 'candidates.0.finishReason', '')));
                if ($finishReason === 'SAFETY' || $finishReason === 'BLOCKLIST') {
                    throw new DisplayException('Gemini blocked this response due to safety restrictions.');
                }

                throw new DisplayException('Gemini returned an empty response.');
            }

            return [$reply, str_starts_with($candidateModel, 'models/') ? substr($candidateModel, 7) : $candidateModel];
        }

        if ($lastModelUnavailableResponse instanceof Response) {
            $this->throwProviderApiError('gemini', $lastModelUnavailableResponse);
        }

        throw new DisplayException('No supported Gemini model is configured. Update GEMINI_MODEL and retry.');
    }

    /**
     * @return array<int, string>
     */
    private function geminiModelCandidates(string $preferredModel): array
    {
        $configuredFallbacks = array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) config('services.gemini.fallback_models', ''))
        ));

        $defaultFallbacks = [
            'gemini-2.5-flash',
            'gemini-flash-latest',
            'gemini-2.0-flash',
        ];

        $candidates = [];
        foreach (array_merge([$preferredModel], $configuredFallbacks, $defaultFallbacks) as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, $candidates, true)) {
                continue;
            }

            $candidates[] = $normalized;
        }

        return $candidates;
    }

    private function isGeminiModelUnavailable(Response $response): bool
    {
        if (!in_array($response->status(), [400, 404], true)) {
            return false;
        }

        $json = is_array($response->json()) ? $response->json() : [];
        $errorMessage = strtolower(trim((string) Arr::get($json, 'error.message', '')));
        if ($errorMessage === '') {
            return false;
        }

        return str_contains($errorMessage, 'is not found')
            || str_contains($errorMessage, 'model')
                && str_contains($errorMessage, 'not found')
            || str_contains($errorMessage, 'not supported for generatecontent');
    }

    private function throwProviderApiError(string $provider, Response $response): never
    {
        $json = is_array($response->json()) ? $response->json() : [];
        $status = $response->status();
        $errorMessage = trim((string) Arr::get($json, 'error.message', ''));
        $errorCode = strtolower(trim((string) Arr::get($json, 'error.code', Arr::get($json, 'error.status', ''))));
        $normalizedMessage = strtolower($errorMessage);
        $providerName = strtoupper($provider);

        $isQuotaExceeded = in_array($errorCode, ['insufficient_quota', 'resource_exhausted'], true)
            || str_contains($normalizedMessage, 'exceeded your current quota')
            || str_contains($normalizedMessage, 'quota');
        if ($isQuotaExceeded) {
            throw new DisplayException(
                sprintf(
                    '%s quota exceeded for the configured API key. Please top up billing, increase limits, or use another key.',
                    $providerName
                )
            );
        }

        if ($status === 429) {
            $resetHint = trim((string) $response->header('x-ratelimit-reset-tokens', ''));
            if ($resetHint === '') {
                $resetHint = trim((string) $response->header('x-ratelimit-reset-requests', ''));
            }
            if ($resetHint === '') {
                $resetHint = trim((string) $response->header('retry-after', ''));
            }

            if ($resetHint !== '') {
                throw new DisplayException(
                    sprintf('%s rate limit reached. Retry after approximately %s.', $providerName, $resetHint)
                );
            }

            throw new DisplayException(sprintf('%s rate limit reached. Please retry in a moment.', $providerName));
        }

        if ($errorMessage === '') {
            $errorMessage = sprintf('%s request failed with status %d.', $providerName, $status);
        }

        throw new DisplayException(sprintf('%s assistant request failed: %s', $providerName, $errorMessage));
    }
}
