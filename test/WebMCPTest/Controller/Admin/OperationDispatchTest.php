<?php

declare(strict_types=1);

namespace WebMCPTest\Controller\Admin;

use PHPUnit\Framework\TestCase;
use WebMCP\Controller\Admin\WebMCPProxyController;

class OperationDispatchTest extends TestCase
{
    private function controller($api)
    {
        return new class ($api) extends WebMCPProxyController {
            private $testApi;

            public function __construct($api)
            {
                $this->testApi = $api;
            }

            public function api($data = null, $throwValidationException = false)
            {
                TestCase::assertNull($data);
                TestCase::assertTrue($throwValidationException);
                return $this->testApi;
            }

            public function execute($op, $data = [], $ids = [])
            {
                return $this->runOperation($op, 'items', 7, ['limit' => 5], $data, $ids);
            }
        };
    }

    private function api()
    {
        return new class {
            public $written;
            public $deleted = [];

            public function response($content)
            {
                return new class ($content) {
                    private $content;

                    public function __construct($content)
                    {
                        $this->content = $content;
                    }

                    public function getContent()
                    {
                        return $this->content;
                    }

                    public function getTotalResults()
                    {
                        return 12;
                    }
                };
            }

            public function search($resource, $query)
            {
                TestCase::assertSame('items', $resource);
                TestCase::assertSame(['limit' => 5], $query);
                return $this->response([['o:id' => 7]]);
            }

            public function read($resource, $id)
            {
                TestCase::assertSame('items', $resource);
                TestCase::assertSame(7, $id);
                return $this->response(['o:id' => 7, 'o:is_public' => true]);
            }

            public function create($resource, $data)
            {
                TestCase::assertSame('items', $resource);
                if (isset($data['fail'])) {
                    throw new \RuntimeException('Invalid item');
                }
                $this->written = $data;
                return $this->response($data + ['o:id' => 8]);
            }

            public function update($resource, $id, $data)
            {
                TestCase::assertSame('items', $resource);
                TestCase::assertSame(7, $id);
                $this->written = $data;
                return $this->response($data);
            }

            public function delete($resource, $id)
            {
                TestCase::assertSame('items', $resource);
                if ($id === 9) {
                    throw new \RuntimeException('Cannot delete item');
                }
                $this->deleted[] = $id;
            }
        };
    }

    public function testSearchReadCreateUpdateAndDelete(): void
    {
        $api = $this->api();
        $controller = $this->controller($api);
        $this->assertSame(['items' => [['o:id' => 7]], 'total_results' => 12], $controller->execute('search'));
        $this->assertSame(['o:id' => 7, 'o:is_public' => true], $controller->execute('get'));
        $data = ['dcterms:title' => [['type' => 'literal', '@value' => 'Title']]];
        $created = $controller->execute('create', $data);
        $this->assertSame('auto', $created['dcterms:title'][0]['property_id']);
        $updated = $controller->execute('update', $data);
        $this->assertTrue($updated['o:is_public']);
        $this->assertSame('auto', $api->written['dcterms:title'][0]['property_id']);
        $this->assertSame(['deleted' => true, 'id' => 7], $controller->execute('delete'));
        $this->assertSame([7], $api->deleted);
    }

    public function testBatchOperationsRetainSuccessesAndReportIndividualFailures(): void
    {
        $api = $this->api();
        $controller = $this->controller($api);
        $result = $controller->execute('batch_create', [['o:is_public' => true], ['fail' => true]]);
        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('Invalid item', $result['errors'][0]['message']);
        $result = $controller->execute('batch_delete', [], [7, 9]);
        $this->assertSame([7], $result['ids']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(9, $result['errors'][0]['id']);
    }

    public function testUnknownOperationsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown operation: missing');
        $this->controller($this->api())->execute('missing');
    }
}
