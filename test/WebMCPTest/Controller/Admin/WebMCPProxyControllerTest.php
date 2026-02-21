<?php

declare(strict_types=1);

namespace WebMCPTest\Controller\Admin;

use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use PHPUnit\Framework\TestCase;

class WebMCPProxyControllerTest extends TestCase
{
    private TestableWebMCPProxyController $controller;
    private Response $response;

    protected function setUp(): void
    {
        $this->controller = new TestableWebMCPProxyController();
        $this->response   = new Response();
        $this->controller->setTestResponse($this->response);
    }

    // ------------------------------------------------------------------ helpers

    private function makePostRequest(array $body, bool $withCsrf = true): Request
    {
        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setContent((string) json_encode($body));
        $request->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        if ($withCsrf) {
            $request->getHeaders()->addHeaderLine('X-CSRF-Token', 'valid-token');
        }
        return $request;
    }

    private function dispatch(Request $request): JsonModel
    {
        $this->controller->setTestRequest($request);
        return $this->controller->proxyAction();
    }

    // ------------------------------------------------------------------ tests

    public function testRejectsGetRequest(): void
    {
        $request = new Request();
        $request->setMethod(Request::METHOD_GET);
        $result = $this->dispatch($request);

        $this->assertInstanceOf(JsonModel::class, $result);
        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(405, $this->response->getStatusCode());
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->controller->csrfValid = false;

        $result = $this->dispatch($this->makePostRequest(['op' => 'search', 'resource' => 'items']));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(403, $this->response->getStatusCode());
        $this->assertStringContainsString('CSRF', $result->getVariable('message'));
    }

    public function testRejectsNonJsonBody(): void
    {
        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setContent('not-json');
        $request->getHeaders()->addHeaderLine('X-CSRF-Token', 'valid-token');

        $result = $this->dispatch($request);

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(400, $this->response->getStatusCode());
        $this->assertStringContainsString('JSON', $result->getVariable('message'));
    }

    public function testRejectsMissingOpField(): void
    {
        $result = $this->dispatch($this->makePostRequest(['resource' => 'items']));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(400, $this->response->getStatusCode());
        $this->assertStringContainsString('op', $result->getVariable('message'));
    }

    public function testRejectsMissingResourceField(): void
    {
        $result = $this->dispatch($this->makePostRequest(['op' => 'search']));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(400, $this->response->getStatusCode());
        $this->assertStringContainsString('resource', $result->getVariable('message'));
    }

    public function testSuccessfulOperationReturnsData(): void
    {
        $this->controller->nextOperationResult = ['items' => [], 'total_results' => 0];

        $result = $this->dispatch($this->makePostRequest(['op' => 'search', 'resource' => 'items']));

        $this->assertTrue($result->getVariable('success'));
        $this->assertSame(200, $this->response->getStatusCode());
        $this->assertSame(['items' => [], 'total_results' => 0], $result->getVariable('data'));
    }

    public function testOperationArgsPassedCorrectly(): void
    {
        $payload = [
            'op'       => 'get',
            'resource' => 'items',
            'id'       => 42,
            'query'    => ['page' => 1],
            'data'     => ['dcterms:title' => [['@value' => 'Test']]],
        ];

        $this->dispatch($this->makePostRequest($payload));

        $args = $this->controller->lastOperationArgs;
        $this->assertSame('get', $args['op']);
        $this->assertSame('items', $args['resource']);
        $this->assertSame(42, $args['id']);
        $this->assertSame(['page' => 1], $args['query']);
    }

    public function testPermissionDeniedExceptionReturns403(): void
    {
        $this->controller->nextOperationException =
            new \Omeka\Api\Exception\PermissionDeniedException('No access');

        $result = $this->dispatch($this->makePostRequest(['op' => 'delete', 'resource' => 'items', 'id' => 1]));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(403, $this->response->getStatusCode());
        $this->assertStringContainsString('Permission', $result->getVariable('message'));
    }

    public function testNotFoundExceptionReturns404(): void
    {
        $this->controller->nextOperationException =
            new \Omeka\Api\Exception\NotFoundException('Item 999 not found');

        $result = $this->dispatch($this->makePostRequest(['op' => 'get', 'resource' => 'items', 'id' => 999]));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(404, $this->response->getStatusCode());
        $this->assertStringContainsString('Not found', $result->getVariable('message'));
    }

    public function testValidationExceptionReturns422(): void
    {
        $this->controller->nextOperationException =
            new \Omeka\Api\Exception\ValidationException('Title is required');

        $result = $this->dispatch($this->makePostRequest(['op' => 'create', 'resource' => 'items']));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(422, $this->response->getStatusCode());
        $this->assertStringContainsString('Title is required', $result->getVariable('message'));
    }

    public function testInvalidArgumentExceptionReturns400(): void
    {
        $this->controller->nextOperationException =
            new \InvalidArgumentException('Unknown operation: foobar');

        $result = $this->dispatch($this->makePostRequest(['op' => 'foobar', 'resource' => 'items']));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(400, $this->response->getStatusCode());
        $this->assertStringContainsString('Unknown operation', $result->getVariable('message'));
    }

    public function testGenericExceptionReturns500(): void
    {
        $this->controller->nextOperationException =
            new \RuntimeException('Unexpected server error');

        $result = $this->dispatch($this->makePostRequest(['op' => 'search', 'resource' => 'items']));

        $this->assertTrue($result->getVariable('error'));
        $this->assertSame(500, $this->response->getStatusCode());
        $this->assertStringContainsString('Unexpected server error', $result->getVariable('message'));
    }

    public function testIdsArrayPassedForBatchDelete(): void
    {
        $payload = [
            'op'       => 'batch_delete',
            'resource' => 'items',
            'ids'      => [1, 2, 3],
        ];

        $this->dispatch($this->makePostRequest($payload));

        $this->assertSame([1, 2, 3], $this->controller->lastOperationArgs['ids']);
    }

    public function testQueryDefaultsToEmptyArray(): void
    {
        $this->dispatch($this->makePostRequest(['op' => 'search', 'resource' => 'items']));

        $this->assertSame([], $this->controller->lastOperationArgs['query']);
    }

    public function testIdsDefaultsToEmptyArray(): void
    {
        $this->dispatch($this->makePostRequest(['op' => 'delete', 'resource' => 'items', 'id' => 5]));

        $this->assertSame([], $this->controller->lastOperationArgs['ids']);
    }
}
