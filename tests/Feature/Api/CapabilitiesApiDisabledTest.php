<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Padosoft\PatentBoxTracker\Tests\TestCase;

final class CapabilitiesApiDisabledTest extends TestCase
{
    public function test_capabilities_endpoint_is_not_exposed_when_api_is_disabled(): void
    {
        $this->getJson('/api/patent-box/v1/capabilities')
            ->assertNotFound();
    }
}

