<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_static_api_documentation_remains_available(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertJsonPath('swagger', '2.0')
            ->assertJsonPath('info.title', 'VUMC PICTURE API');

        $this->get('/api/documentation')
            ->assertOk()
            ->assertSee('swagger-ui');
    }
}
