<?php

declare(strict_types=1);

namespace WebMCPTest\Controller\Admin;

use Laminas\Http\Request;
use Laminas\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests that create/update payloads containing dcterms:title reach
 * runOperation() intact so items are never stored as [untitled].
 *
 * These tests reflect the data shape produced by the buildItemData()
 * helper in webmcp.js after it maps the convenience `title` field to
 * the JSON-LD {"dcterms:title": [{"type":"literal","@value":"..."}]} format.
 */
class ProxyCreateDataTest extends TestCase
{
    private TestableWebMCPProxyController $controller;
    private Response $response;

    protected function setUp(): void
    {
        $this->controller = new TestableWebMCPProxyController();
        $this->response   = new Response();
        $this->controller->setTestResponse($this->response);
    }

    private function post(array $body): array
    {
        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setContent((string) json_encode($body));
        $request->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request->getHeaders()->addHeaderLine('X-CSRF-Token', 'valid-token');
        $this->controller->setTestRequest($request);
        $this->controller->proxyAction();
        return $this->controller->lastOperationArgs;
    }

    // ------------------------------------------------------------------ items

    public function testCreateItemWithTitlePassesDcTermsTitle(): void
    {
        $args = $this->post([
            'op'       => 'create',
            'resource' => 'items',
            'data'     => [
                'dcterms:title' => [['type' => 'literal', '@value' => 'My Item']],
            ],
        ]);

        $this->assertSame('create', $args['op']);
        $this->assertSame('items', $args['resource']);
        $this->assertArrayHasKey('dcterms:title', $args['data']);
        $this->assertSame('My Item', $args['data']['dcterms:title'][0]['@value']);
    }

    public function testCreateItemWithoutTitlePassesEmptyData(): void
    {
        $args = $this->post([
            'op'       => 'create',
            'resource' => 'items',
            'data'     => [],
        ]);

        // Data reaches runOperation without a dcterms:title key — Omeka will
        // show [untitled], but that is the caller's responsibility at this layer.
        $this->assertSame('create', $args['op']);
        $this->assertArrayNotHasKey('dcterms:title', $args['data']);
    }

    public function testCreateItemWithTitleAndDescription(): void
    {
        $args = $this->post([
            'op'       => 'create',
            'resource' => 'items',
            'data'     => [
                'dcterms:title'       => [['type' => 'literal', '@value' => 'Archive Record']],
                'dcterms:description' => [['type' => 'literal', '@value' => 'A description.']],
            ],
        ]);

        $this->assertSame('Archive Record', $args['data']['dcterms:title'][0]['@value']);
        $this->assertSame('A description.', $args['data']['dcterms:description'][0]['@value']);
    }

    public function testCreateItemPreservesResourceTemplateAndItemSets(): void
    {
        $args = $this->post([
            'op'       => 'create',
            'resource' => 'items',
            'data'     => [
                'dcterms:title'      => [['type' => 'literal', '@value' => 'Titled Item']],
                'o:resource_template' => ['o:id' => 5],
                'o:item_set'          => [['o:id' => 10], ['o:id' => 11]],
            ],
        ]);

        $this->assertSame(5, $args['data']['o:resource_template']['o:id']);
        $this->assertCount(2, $args['data']['o:item_set']);
    }

    // ------------------------------------------------------------------ item sets

    public function testCreateItemSetWithTitlePassesDcTermsTitle(): void
    {
        $args = $this->post([
            'op'       => 'create',
            'resource' => 'item_sets',
            'data'     => [
                'dcterms:title' => [['type' => 'literal', '@value' => 'Photography Collection']],
            ],
        ]);

        $this->assertSame('create', $args['op']);
        $this->assertSame('item_sets', $args['resource']);
        $this->assertSame('Photography Collection', $args['data']['dcterms:title'][0]['@value']);
    }

    // ------------------------------------------------------------------ update

    public function testUpdateItemWithTitlePassesDcTermsTitle(): void
    {
        $args = $this->post([
            'op'       => 'update',
            'resource' => 'items',
            'id'       => 42,
            'data'     => [
                'dcterms:title' => [['type' => 'literal', '@value' => 'Updated Title']],
            ],
        ]);

        $this->assertSame('update', $args['op']);
        $this->assertSame(42, $args['id']);
        $this->assertSame('Updated Title', $args['data']['dcterms:title'][0]['@value']);
    }

    // ------------------------------------------------------------------ batch create

    public function testBatchCreateItemsPassesDataArray(): void
    {
        $items = [
            ['dcterms:title' => [['type' => 'literal', '@value' => 'Item A']]],
            ['dcterms:title' => [['type' => 'literal', '@value' => 'Item B']]],
        ];

        $args = $this->post([
            'op'       => 'batch_create',
            'resource' => 'items',
            'data'     => $items,
        ]);

        $this->assertSame('batch_create', $args['op']);
        $this->assertCount(2, $args['data']);
        $this->assertSame('Item A', $args['data'][0]['dcterms:title'][0]['@value']);
        $this->assertSame('Item B', $args['data'][1]['dcterms:title'][0]['@value']);
    }
}
