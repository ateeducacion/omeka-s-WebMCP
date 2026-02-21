<?php

declare(strict_types=1);

namespace WebMCPTest\Controller\Admin;

use Laminas\Http\Request;
use Laminas\Http\Response;
use WebMCP\Controller\Admin\WebMCPProxyController;

/**
 * Testable subclass that:
 *  - allows injecting Request/Response directly (bypassing the Laminas
 *    EventManager dispatch cycle)
 *  - stubs out CSRF validation
 *  - stubs out runOperation() so tests can inspect arguments and simulate
 *    results/exceptions without a real Omeka-S API stack
 */
class TestableWebMCPProxyController extends WebMCPProxyController
{
    public bool $csrfValid = true;
    public array $nextOperationResult = ['mocked' => true];
    public ?array $lastOperationArgs = null;
    public ?\Exception $nextOperationException = null;

    /**
     * Inject an HTTP request into the protected property that
     * AbstractController::getRequest() reads directly.
     */
    public function setTestRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * Inject an HTTP response into the protected property that
     * AbstractController::getResponse() reads directly.
     */
    public function setTestResponse(Response $response): void
    {
        $this->response = $response;
    }

    protected function isCsrfTokenValid(string $token): bool
    {
        return $this->csrfValid;
    }

    protected function runOperation(string $op, string $resource, $id, array $query, $data, array $ids): array
    {
        $this->lastOperationArgs = compact('op', 'resource', 'id', 'query', 'data', 'ids');
        if ($this->nextOperationException !== null) {
            throw $this->nextOperationException;
        }
        return $this->nextOperationResult;
    }
}
