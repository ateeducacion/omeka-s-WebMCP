<?php declare(strict_types=1);

namespace WebMCPTest\Form;

use PHPUnit\Framework\TestCase;
use WebMCP\Form\ConfigForm;
use Laminas\Form\Element;

class ConfigFormFieldsTest extends TestCase
{
    public function testFormContainsAllToolGroupFields(): void
    {
        $form = new ConfigForm();
        $form->init();

        $expected = [
            'webmcp_enable_items' => Element\Checkbox::class,
            'webmcp_enable_media' => Element\Checkbox::class,
            'webmcp_enable_item_sets' => Element\Checkbox::class,
            'webmcp_enable_sites' => Element\Checkbox::class,
            'webmcp_enable_users' => Element\Checkbox::class,
            'webmcp_enable_vocabularies' => Element\Checkbox::class,
            'webmcp_enable_bulk' => Element\Checkbox::class,
        ];

        foreach ($expected as $name => $type) {
            $this->assertTrue($form->has($name), "Form should contain element '$name'");
            $element = $form->get($name);
            $this->assertInstanceOf($type, $element, "Element '$name' should be of type $type");
        }
    }

    public function testCheckboxesUseHiddenValues(): void
    {
        $form = new ConfigForm();
        $form->init();

        $fields = [
            'webmcp_enable_items',
            'webmcp_enable_media',
            'webmcp_enable_item_sets',
            'webmcp_enable_sites',
            'webmcp_enable_users',
            'webmcp_enable_vocabularies',
            'webmcp_enable_bulk',
        ];

        foreach ($fields as $name) {
            /** @var Element\Checkbox $el */
            $el = $form->get($name);
            $opts = $el->getOptions();
            $this->assertSame('1', $opts['checked_value'] ?? null, "checked_value for $name");
            $this->assertSame('0', $opts['unchecked_value'] ?? null, "unchecked_value for $name");
        }
    }
}
