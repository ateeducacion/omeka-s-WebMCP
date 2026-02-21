<?php declare(strict_types=1);

namespace ModuleTemplateTest\Config;

use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    public function testModuleConfigReturnsArrayWithDefaults(): void
    {
        // Require the config file which returns an array
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';

        $this->assertIsArray($config);
        $this->assertArrayHasKey('WebMCP', $config);
        $this->assertArrayHasKey('settings', $config['WebMCP']);

        $settings = $config['WebMCP']['settings'];
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
}
