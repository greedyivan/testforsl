<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\IntegrationTestCase;

class HealthTest extends IntegrationTestCase
{
    public function test_liveness_always_returns_200(): void
    {
        $resp = $this->http->get('/health');

        $this->assertSame(200, $resp->getStatusCode());

        $body = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ok', $body['status']);
    }

    public function test_readiness_returns_200_when_all_deps_healthy(): void
    {
        $resp = $this->http->get('/ready');
        $body = json_decode((string) $resp->getBody(), true);

        // В интеграционном окружении все зависимости запущены
        $this->assertSame(200, $resp->getStatusCode(), json_encode($body));
        $this->assertSame('ok', $body['status']);
    }
}
