<?php declare(strict_types=1);

namespace WebMCPTest\Config;

use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 3) . '/config/module.config.php';
    }

    public function testConfigReturnsArray(): void
    {
        $this->assertIsArray($this->config);
    }

    public function testDefaultSettingsContainAllToolGroups(): void
    {
        $this->assertArrayHasKey('WebMCP', $this->config);
        $this->assertArrayHasKey('settings', $this->config['WebMCP']);

        $settings = $this->config['WebMCP']['settings'];

        $expectedKeys = [
            'webmcp_enable_items',
            'webmcp_enable_media',
            'webmcp_enable_item_sets',
            'webmcp_enable_sites',
            'webmcp_enable_users',
            'webmcp_enable_vocabularies',
            'webmcp_enable_bulk',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $settings, "Missing default setting: $key");
        }
    }

    public function testDefaultSettingsContainNoApiKeyFields(): void
    {
        $settings = $this->config['WebMCP']['settings'];

        $this->assertArrayNotHasKey('webmcp_key_identity', $settings, 'API key identity should be removed');
        $this->assertArrayNotHasKey('webmcp_key_credential', $settings, 'API key credential should be removed');
    }

    public function testProxyRouteIsDefined(): void
    {
        $childRoutes = $this->config['router']['routes']['admin']['child_routes'] ?? [];
        $this->assertArrayHasKey('webmcp-proxy', $childRoutes, 'webmcp-proxy child route must be defined');
    }

    public function testProxyRoutePointsToCorrectPath(): void
    {
        $route = $this->config['router']['routes']['admin']['child_routes']['webmcp-proxy'];
        $this->assertSame('Literal', $route['type']);
        $this->assertSame('/webmcp/proxy', $route['options']['route']);
    }

    public function testProxyRouteDefaultsAreCorrect(): void
    {
        $defaults = $this->config['router']['routes']['admin']['child_routes']['webmcp-proxy']['options']['defaults'];
        $this->assertSame('WebMCP\Controller\Admin', $defaults['__NAMESPACE__']);
        $this->assertTrue($defaults['__ADMIN__']);
        $this->assertSame('WebMCPProxy', $defaults['controller']);
        $this->assertSame('proxy', $defaults['action']);
    }

    public function testProxyControllerIsRegistered(): void
    {
        $invokables = $this->config['controllers']['invokables'] ?? [];
        $this->assertArrayHasKey(
            'WebMCP\Controller\Admin\WebMCPProxy',
            $invokables,
            'WebMCPProxy controller must be registered as invokable'
        );
        $this->assertSame(
            \WebMCP\Controller\Admin\WebMCPProxyController::class,
            $invokables['WebMCP\Controller\Admin\WebMCPProxy']
        );
    }

    public function testConfigFormIsRegistered(): void
    {
        $formElements = $this->config['form_elements']['invokables'] ?? [];
        $this->assertContains(
            \WebMCP\Form\ConfigForm::class,
            $formElements,
            'ConfigForm must be registered in form_elements'
        );
    }
}
