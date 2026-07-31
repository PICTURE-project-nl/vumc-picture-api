<?php

namespace Tests\Unit;

use App\BrainMap;
use App\Upload;
use App\User;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Passport;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ModelCompatibilityTest extends TestCase
{
    public function test_models_keep_generating_version_four_uuids(): void
    {
        $this->assertSame(4, Uuid::fromString((new BrainMap())->newUniqueId())->getVersion());
        $this->assertSame(4, Uuid::fromString((new Upload())->newUniqueId())->getVersion());
    }

    public function test_passport_keeps_existing_client_identifiers_and_routes(): void
    {
        $this->assertInstanceOf(OAuthenticatable::class, new User());
        $this->assertFalse(Passport::$clientUuids);
        $this->assertTrue(Route::has('passport.token'));
    }
}
