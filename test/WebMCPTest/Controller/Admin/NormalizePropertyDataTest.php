<?php

declare(strict_types=1);

namespace WebMCPTest\Controller\Admin;

use PHPUnit\Framework\TestCase;
use WebMCP\Controller\Admin\WebMCPProxyController;

/**
 * Unit tests for WebMCPProxyController::normalizePropertyData().
 *
 * Omeka's ValueHydrator silently ignores property values that lack
 * property_id. The normalizer adds 'property_id' => 'auto' so the hydrator
 * can resolve the ID from the vocabulary term key.
 */
class NormalizePropertyDataTest extends TestCase
{
    /** Expose the protected normalizePropertyData method for testing. */
    private WebMCPProxyController $controller;

    protected function setUp(): void
    {
        $this->controller = new class extends WebMCPProxyController {
            public function normalize(array $data): array
            {
                return $this->normalizePropertyData($data);
            }
        };
    }

    private function normalize(array $data): array
    {
        return $this->controller->normalize($data);
    }

    // ------------------------------------------------------------------ adding property_id

    public function testAddsAutoPropertyIdToLiteralValue(): void
    {
        $result = $this->normalize([
            'dcterms:title' => [['type' => 'literal', '@value' => 'My Title']],
        ]);

        $this->assertSame('auto', $result['dcterms:title'][0]['property_id']);
        $this->assertSame('My Title', $result['dcterms:title'][0]['@value']);
    }

    public function testAddsAutoPropertyIdToMultipleValues(): void
    {
        $result = $this->normalize([
            'dcterms:title'       => [['type' => 'literal', '@value' => 'Title']],
            'dcterms:description' => [['type' => 'literal', '@value' => 'Desc']],
        ]);

        $this->assertSame('auto', $result['dcterms:title'][0]['property_id']);
        $this->assertSame('auto', $result['dcterms:description'][0]['property_id']);
    }

    public function testAddsAutoToEachValueInArray(): void
    {
        $result = $this->normalize([
            'dcterms:subject' => [
                ['type' => 'literal', '@value' => 'History'],
                ['type' => 'literal', '@value' => 'Science'],
            ],
        ]);

        $this->assertSame('auto', $result['dcterms:subject'][0]['property_id']);
        $this->assertSame('auto', $result['dcterms:subject'][1]['property_id']);
    }

    // ------------------------------------------------------------------ preserving existing property_id

    public function testPreservesExistingIntegerPropertyId(): void
    {
        $result = $this->normalize([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Title', 'property_id' => 1]],
        ]);

        $this->assertSame(1, $result['dcterms:title'][0]['property_id']);
    }

    public function testPreservesExistingAutoPropertyId(): void
    {
        $result = $this->normalize([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Title', 'property_id' => 'auto']],
        ]);

        $this->assertSame('auto', $result['dcterms:title'][0]['property_id']);
    }

    // ------------------------------------------------------------------ skipping non-property keys

    public function testSkipsOmekaPrefixedFields(): void
    {
        $original = [
            'o:resource_template' => [['o:id' => 5]],
            'o:item_set'          => [['o:id' => 10]],
        ];
        $result = $this->normalize($original);

        // o:* fields must not get property_id injected
        $this->assertArrayNotHasKey('property_id', $result['o:resource_template'][0]);
        $this->assertArrayNotHasKey('property_id', $result['o:item_set'][0]);
    }

    public function testSkipsJsonLdKeywords(): void
    {
        $original = [
            '@type'    => ['o:Item'],
            '@context' => 'https://example.com/context',
        ];
        $result = $this->normalize($original);

        // @* keys should be returned unchanged
        $this->assertSame($original, $result);
    }

    public function testSkipsKeysWithoutColon(): void
    {
        $original = ['title' => [['@value' => 'foo']]];
        $result   = $this->normalize($original);

        $this->assertArrayNotHasKey('property_id', $result['title'][0]);
    }

    public function testSkipsNonArrayValues(): void
    {
        $original = ['dcterms:title' => 'plain string'];
        $result   = $this->normalize($original);

        $this->assertSame('plain string', $result['dcterms:title']);
    }

    // ------------------------------------------------------------------ mixed data

    public function testMixedDataProcessesOnlyPropertyTerms(): void
    {
        $result = $this->normalize([
            'dcterms:title'       => [['type' => 'literal', '@value' => 'My Item']],
            'o:resource_template' => ['o:id' => 5],
            'o:item_set'          => [['o:id' => 10]],
        ]);

        $this->assertSame('auto', $result['dcterms:title'][0]['property_id']);
        // Non-property fields are untouched
        $this->assertSame(['o:id' => 5], $result['o:resource_template']);
    }

    public function testEmptyDataReturnsEmpty(): void
    {
        $this->assertSame([], $this->normalize([]));
    }
}
