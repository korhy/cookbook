<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

class McpEndpointTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private const MCP_URL = '/api/v1/mcp';

    public function testMcpEndpointIsPubliclyReachableWithoutAuthentication(): void
    {
        $response = static::createClient()->request('POST', self::MCP_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'test-client', 'version' => '0.0.1'],
                ],
            ],
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testToolsListIncludesRecipeTools(): void
    {
        $client = static::createClient();

        $initResponse = $client->request('POST', self::MCP_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'test-client', 'version' => '0.0.1'],
                ],
            ],
        ]);

        $sessionId = $initResponse->getHeaders()['mcp-session-id'][0] ?? null;
        $this->assertNotNull($sessionId, 'Expected an Mcp-Session-Id response header after initialize.');

        $listResponse = $client->request('POST', self::MCP_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'Mcp-Session-Id' => $sessionId,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ],
        ]);

        $names = array_column($listResponse->toArray(false)['result']['tools'] ?? [], 'name');

        $this->assertContains('recipe_search', $names);
        $this->assertContains('recipe_get', $names);
        $this->assertContains('category_list', $names);
    }

    public function testRecipesEndpointStillRequiresAuthentication(): void
    {
        $response = static::createClient()->request('GET', '/api/v1/recipes', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }
}
