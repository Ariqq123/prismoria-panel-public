<?php

namespace Pterodactyl\Transformers\Api\Application;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Webmozart\Assert\Assert;
use Pterodactyl\Models\ApiKey;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use League\Fractal\TransformerAbstract;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/**
 * @method array transform(Model $model)
 */
abstract class BaseTransformer extends TransformerAbstract
{
    public const RESPONSE_TIMEZONE = 'UTC';

    protected Request $request;

    /**
     * BaseTransformer constructor.
     */
    public function __construct()
    {
        // Transformers allow for dependency injection on the handle method.
        if (method_exists($this, 'handle')) {
            Container::getInstance()->call([$this, 'handle']);
        }
    }

    /**
     * Return the resource name for the JSONAPI output.
     */
    abstract public function getResourceName(): string;

    /**
     * Sets the request on the instance.
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Returns a new transformer instance with the request set on the instance.
     */
    public static function fromRequest(Request $request): BaseTransformer
    {
        return app(static::class)->setRequest($request);
    }

    /**
     * Determine if the API key loaded onto the transformer has permission
     * to access a different resource. This is used when including other
     * models on a transformation request.
     *
     * @deprecated — prefer $user->can/cannot methods
     */
    protected function authorize(string $resource): bool
    {
        if (!isset($this->request)) {
            Log::debug('API transformer authorization denied: request is not set.', ['resource' => $resource]);

            return false;
        }

        $user = $this->request->user();
        if (is_null($user)) {
            Log::debug('API transformer authorization denied: request user is missing.', ['resource' => $resource]);

            return false;
        }

        $token = $user->currentAccessToken();
        if (!$token instanceof ApiKey) {
            Log::debug('API transformer authorization denied: current access token is not an ApiKey.', ['resource' => $resource]);

            return false;
        }

        if ($token->key_type === ApiKey::TYPE_ACCOUNT) {
            return (bool) $user->root_admin;
        }

        if ($token->key_type !== ApiKey::TYPE_APPLICATION || !ApiKey::allowsLegacyApplicationAuthorization()) {
            Log::debug('API transformer authorization denied: legacy application API key authorization is disabled or unsupported token type.', [
                'resource' => $resource,
                'token_id' => $token->id,
                'key_type' => $token->key_type,
            ]);

            return false;
        }

        Log::debug('Authorizing request with legacy application API key type.', [
            'resource' => $resource,
            'token_id' => $token->id,
        ]);

        return AdminAcl::check($token, $resource);
    }

    /**
     * Create a new instance of the transformer and pass along the currently
     * set API key.
     *
     * @template T of \Pterodactyl\Transformers\Api\Application\BaseTransformer
     *
     * @param class-string<T> $abstract
     *
     * @return T
     *
     * @throws \Pterodactyl\Exceptions\Transformer\InvalidTransformerLevelException
     *
     * @noinspection PhpDocSignatureInspection
     */
    protected function makeTransformer(string $abstract)
    {
        Assert::subclassOf($abstract, self::class);

        return $abstract::fromRequest($this->request);
    }

    /**
     * Return an ISO-8601 formatted timestamp to use in the API response.
     */
    protected function formatTimestamp(string $timestamp): string
    {
        return CarbonImmutable::createFromFormat(CarbonInterface::DEFAULT_TO_STRING_FORMAT, $timestamp)
            ->setTimezone(self::RESPONSE_TIMEZONE)
            ->toAtomString();
    }
}
