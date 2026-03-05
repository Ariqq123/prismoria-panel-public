<?php

namespace Pterodactyl\Tests\Unit\Transformers\Api\Application;

use Mockery as m;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Transformers\Api\Application\BaseTransformer;

class BaseTransformerAuthorizationTest extends TestCase
{
    public function tearDown(): void
    {
        m::close();

        parent::tearDown();
    }

    public function testAuthorizeFailsClosedWhenRequestIsNotSet(): void
    {
        $transformer = new DummyTransformer();

        $this->assertFalse($transformer->canResource(AdminAcl::RESOURCE_SERVERS));
    }

    public function testAuthorizeFailsClosedWhenRequestUserIsMissing(): void
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturnNull();

        $transformer = (new DummyTransformer())->setRequest($request);

        $this->assertFalse($transformer->canResource(AdminAcl::RESOURCE_SERVERS));
    }

    public function testAccountTokenAuthorizationUsesRootAdminFlag(): void
    {
        $token = ApiKey::factory()->make(['key_type' => ApiKey::TYPE_ACCOUNT]);
        $user = m::mock(User::class)->makePartial();
        $user->root_admin = true;
        $user->shouldReceive('currentAccessToken')->andReturn($token);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);

        $transformer = (new DummyTransformer())->setRequest($request);

        $this->assertTrue($transformer->canResource(AdminAcl::RESOURCE_SERVERS));
    }

    public function testApplicationTokenAuthorizationRespectsLegacyCompatibilityToggle(): void
    {
        config()->set('pterodactyl.legacy.allow_application_api_keys', false);

        $token = ApiKey::factory()->make([
            'key_type' => ApiKey::TYPE_APPLICATION,
            'r_servers' => AdminAcl::READ,
        ]);
        $user = m::mock(User::class)->makePartial();
        $user->shouldReceive('currentAccessToken')->andReturn($token);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);

        $transformer = (new DummyTransformer())->setRequest($request);

        $this->assertFalse($transformer->canResource(AdminAcl::RESOURCE_SERVERS));
    }
}

class DummyTransformer extends BaseTransformer
{
    public function getResourceName(): string
    {
        return 'dummy';
    }

    public function transform(Model $model): array
    {
        return [];
    }

    public function canResource(string $resource): bool
    {
        return $this->authorize($resource);
    }
}
