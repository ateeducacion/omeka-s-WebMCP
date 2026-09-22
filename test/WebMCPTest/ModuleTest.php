<?php

declare(strict_types=1);

namespace WebMCPTest;

use WebMCP\Module;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\Controller\AbstractController;
use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    private $settings;
    private $services;
    private $module;

    protected function setUp(): void
    {
        $this->settings = new class {
            public $values = [];

            public function get($key, $default = null)
            {
                return $this->values[$key] ?? $default;
            }

            public function set($key, $value): void
            {
                $this->values[$key] = $value;
            }
        };
        $this->services = new ServiceManager(['services' => [
            'Omeka\Settings' => $this->settings,
            'Config' => [],
        ]]);
        $this->module = new Module();
        $this->module->setServiceLocator($this->services);
    }

    public function testConfigurationAndLifecycle(): void
    {
        $this->assertSame(include dirname(__DIR__, 2) . '/config/module.config.php', $this->module->getConfig());
        $this->module->install($this->services);
        $this->module->uninstall($this->services);
        $events = $this->createMock(\Laminas\EventManager\SharedEventManagerInterface::class);
        $events->expects($this->once())->method('attach')
            ->with('*', 'view.layout', [$this->module, 'handleAdminLayout']);
        $this->module->attachListeners($events);
    }

    public function testFormUsesSavedValuesAndDefaults(): void
    {
        $renderer = new class extends PhpRenderer {
            public $form;

            public function formCollection($form, $wrap)
            {
                $this->form = $form;
                return 'rendered form';
            }
        };
        $this->assertSame('rendered form', $this->module->getConfigForm($renderer));
        $this->assertSame('1', $renderer->form->get('webmcp_enable_items')->getValue());
        $this->settings->values['webmcp_enable_items'] = false;
        $this->module->getConfigForm($renderer);
        $this->assertSame('0', $renderer->form->get('webmcp_enable_items')->getValue());
    }

    public function testSubmissionPersistsSettings(): void
    {
        $post = ['webmcp_enable_items' => '1', 'webmcp_enable_bulk' => '0'];
        $controller = $this->getMockBuilder(\Laminas\Mvc\Controller\AbstractActionController::class)
            ->disableOriginalConstructor()->addMethods(['params'])->getMock();
        $params = new class ($post) {
            private $post;

            public function __construct(array $post)
            {
                $this->post = $post;
            }

            public function fromPost()
            {
                return $this->post;
            }
        };
        $controller->method('params')->willReturn($params);
        $this->module->handleConfigForm($controller);
        $this->assertTrue($this->settings->values['webmcp_enable_items']);
        $this->assertFalse($this->settings->values['webmcp_enable_bulk']);
        $this->assertFalse($this->settings->values['webmcp_enable_users']);
    }
    public function testLayoutSkipsPublicRequestsAndDisabledGroups(): void
    {
        $status = new class {
            public $admin = false;

            public function isAdminRequest()
            {
                return $this->admin;
            }
        };
        $this->services->setService('Omeka\Status', $status);
        $event = new \Laminas\EventManager\Event();
        $this->module->handleAdminLayout($event);
        $status->admin = true;
        foreach (['items', 'media', 'item_sets', 'sites', 'users', 'vocabularies', 'bulk'] as $group) {
            $this->settings->values['webmcp_enable_' . $group] = false;
        }
        // A missing view is safe because no scripts should be requested.
        $this->module->handleAdminLayout($event);
        $this->assertNull($event->getTarget());
    }
}
