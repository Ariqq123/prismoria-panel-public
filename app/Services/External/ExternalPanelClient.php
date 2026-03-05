<?php

namespace Pterodactyl\Services\External;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Models\ExternalPanelConnection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExternalPanelClient
{
    public const DEFAULT_TIMEOUT_SECONDS = 45;

    /**
     * Returns a normalized panel URL without trailing slash.
     */
    public function normalizePanelUrl(string $panelUrl): string
    {
        $normalized = rtrim(trim($panelUrl), '/');
        $normalized = preg_replace('#/api/client$#i', '', $normalized) ?? $normalized;

        return rtrim($normalized, '/');
    }

    /**
     * Send a request to an external panel client API endpoint.
     */
    public function request(
        ExternalPanelConnection $connection,
        string $method,
        string $endpoint,
        array $options = []
    ): Response {
        $timeout = (int) Arr::pull($options, '_timeout', self::DEFAULT_TIMEOUT_SECONDS);
        $connectTimeout = (int) Arr::pull($options, '_connect_timeout', 8);
        $retryDelays = Arr::pull($options, '_retry_delays', [200, 500, 1000]);

        $url = sprintf('%s/api/client/%s', $this->normalizePanelUrl($connection->panel_url), ltrim($endpoint, '/'));

        $request = Http::acceptJson()
            ->withHeaders([
                'Accept' => 'Application/vnd.pterodactyl.v1+json',
            ])
            ->withToken($connection->api_key)
            ->timeout(max(1, $timeout))
            ->connectTimeout(max(1, $connectTimeout));

        if (is_array($retryDelays) && count($retryDelays) > 0) {
            $request = $request->retry(
                $retryDelays,
                throw: false,
                when: function ($exception) {
                    return $exception instanceof ConnectionException;
                }
            );
        }

        $response = $request->send(strtoupper($method), $url, $options);

        return $response;
    }

    /**
     * Tries multiple endpoints and returns the first successful response.
     */
    public function requestWithFallback(
        ExternalPanelConnection $connection,
        string $method,
        array $endpoints,
        array $options = []
    ): Response {
        $lastResponse = null;
        foreach ($endpoints as $endpoint) {
            $response = $this->request($connection, $method, $endpoint, $options);
            $lastResponse = $response;

            if ($response->successful()) {
                return $response;
            }

            // Continue trying alternative endpoint mappings for these status codes.
            if (!in_array($response->status(), [404, 405, 501], true)) {
                return $response;
            }
        }

        return $lastResponse ?? throw new HttpException(502, 'External panel did not return a response.');
    }

    /**
     * Extracts the most user-friendly error from a panel API response.
     */
    public function extractErrorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $detail = data_get($json, 'errors.0.detail');
            if (is_string($detail) && strlen($detail) > 0) {
                return $detail;
            }

            $message = data_get($json, 'message');
            if (is_string($message) && strlen($message) > 0) {
                return $message;
            }

            $error = data_get($json, 'error');
            if (is_string($error) && strlen($error) > 0) {
                return $error;
            }
        }

        return sprintf('External panel request failed with status code %d.', $response->status());
    }
}
