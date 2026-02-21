<?php
declare(strict_types=1);

namespace WebMCP;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Validator\Csrf;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;
use WebMCP\Form\ConfigForm;

/**
 * Main class for the WebMCP module.
 *
 * Implements the WebMCP standard (https://webmachinelearning.github.io/webmcp/)
 * to expose Omeka-S backend management capabilities to AI agents through the browser.
 *
 * All API operations are routed through the server-side proxy at
 * /admin/webmcp/proxy, which uses Omeka\ApiManager internally, so they run
 * inside Omeka-S's own request lifecycle and respect the authenticated PHP
 * session — bypassing any JWT middleware that would block direct /api/* calls.
 */
class Module extends AbstractModule
{
    /**
     * Retrieve the configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Execute logic when the module is installed.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $messenger = new Messenger();
        $message = new Message("WebMCP module installed.");
        $messenger->addSuccess($message);
    }

    /**
     * Execute logic when the module is uninstalled.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator): void
    {
        $messenger = new Messenger();
        $message = new Message("WebMCP module uninstalled.");
        $messenger->addWarning($message);
    }

    /**
     * Register event listeners to inject WebMCP JS into the admin layout.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'handleAdminLayout']
        );
    }

    /**
     * Inject WebMCP JavaScript files into the admin layout.
     *
     * Passes tool-group flags, the proxy URL, and a CSRF token to JS via an
     * inline config object. Only loads the scripts when at least one tool
     * group is enabled.
     *
     * @param Event $event
     */
    public function handleAdminLayout(Event $event): void
    {
        $services = $this->getServiceLocator();

        // Only inject scripts on admin pages.
        $status = $services->get('Omeka\Status');
        if (!$status->isAdminRequest()) {
            return;
        }

        $settings = $services->get('Omeka\Settings');

        $groups = [
            'items'        => (bool) $settings->get('webmcp_enable_items', true),
            'media'        => (bool) $settings->get('webmcp_enable_media', true),
            'item_sets'    => (bool) $settings->get('webmcp_enable_item_sets', true),
            'sites'        => (bool) $settings->get('webmcp_enable_sites', true),
            'users'        => (bool) $settings->get('webmcp_enable_users', true),
            'vocabularies' => (bool) $settings->get('webmcp_enable_vocabularies', true),
            'bulk'         => (bool) $settings->get('webmcp_enable_bulk', true),
        ];

        // Skip script injection when all groups are disabled.
        if (!in_array(true, $groups, true)) {
            return;
        }

        $view = $event->getTarget();

        // Generate a CSRF token (stored in session) for the proxy endpoint.
        // The same name is used in WebMCPProxyController to validate the token.
        $csrf       = new Csrf(['name' => 'webmcp_proxy', 'timeout' => null]);
        $csrfToken  = $csrf->getHash();
        $proxyUrl   = $view->url('admin/webmcp-proxy');

        $jsConfig     = $groups + [
            'csrf_token' => $csrfToken,
            'proxy_url'  => $proxyUrl,
        ];
        $configJson   = json_encode($jsConfig);

        // Append mtime-based cache-bust so browsers always load the latest
        // version of the JS after module updates.
        $assetDir   = __DIR__ . '/asset/js/';
        $vResources = @filemtime($assetDir . 'webmcp-resources.js') ?: 0;
        $vMain      = @filemtime($assetDir . 'webmcp.js')           ?: 0;

        $view->headScript()->appendScript("window.WebMCPConfig = {$configJson};");
        $view->headScript()->appendFile(
            $view->assetUrl('js/webmcp-resources.js', 'WebMCP') . '?v=' . $vResources
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/webmcp.js', 'WebMCP') . '?v=' . $vMain
        );
    }

    /**
     * Get the configuration form for this module.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer): string
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $form = new ConfigForm();
        $form->init();

        $form->setData([
            'webmcp_enable_items'        => $settings->get('webmcp_enable_items', true)        ? '1' : '0',
            'webmcp_enable_media'        => $settings->get('webmcp_enable_media', true)        ? '1' : '0',
            'webmcp_enable_item_sets'    => $settings->get('webmcp_enable_item_sets', true)    ? '1' : '0',
            'webmcp_enable_sites'        => $settings->get('webmcp_enable_sites', true)        ? '1' : '0',
            'webmcp_enable_users'        => $settings->get('webmcp_enable_users', true)        ? '1' : '0',
            'webmcp_enable_vocabularies' => $settings->get('webmcp_enable_vocabularies', true) ? '1' : '0',
            'webmcp_enable_bulk'         => $settings->get('webmcp_enable_bulk', true)         ? '1' : '0',
        ]);

        return $renderer->formCollection($form, false);
    }

    /**
     * Handle the configuration form submission.
     *
     * @param AbstractController $controller
     */
    public function handleConfigForm(AbstractController $controller): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $config = $controller->params()->fromPost();

        $settings->set('webmcp_enable_items', ($config['webmcp_enable_items']        ?? '0') === '1');
        $settings->set('webmcp_enable_media', ($config['webmcp_enable_media']        ?? '0') === '1');
        $settings->set('webmcp_enable_item_sets', ($config['webmcp_enable_item_sets']    ?? '0') === '1');
        $settings->set('webmcp_enable_sites', ($config['webmcp_enable_sites']        ?? '0') === '1');
        $settings->set('webmcp_enable_users', ($config['webmcp_enable_users']        ?? '0') === '1');
        $settings->set('webmcp_enable_vocabularies', ($config['webmcp_enable_vocabularies'] ?? '0') === '1');
        $settings->set('webmcp_enable_bulk', ($config['webmcp_enable_bulk']         ?? '0') === '1');
    }
}
