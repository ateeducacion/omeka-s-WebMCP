<?php
declare(strict_types=1);

namespace WebMCP\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name' => 'webmcp_enable_items',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Item tools', // @translate
                'info' => 'Expose create, update, delete, search, and get-item tools to AI agents.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $this->add([
            'name' => 'webmcp_enable_media',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Media tools', // @translate
                'info' => 'Expose upload-media and list-media tools to AI agents.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $this->add([
            'name' => 'webmcp_enable_item_sets',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Item Set tools', // @translate
                'info' => 'Expose create, update, delete, and list item-set tools to AI agents.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $this->add([
            'name' => 'webmcp_enable_sites',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Site tools', // @translate
                'info' => 'Expose create-site, update-site, and list-sites tools to AI agents.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $this->add([
            'name' => 'webmcp_enable_users',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable User tools', // @translate
                'info' => 'Expose create, update, delete, and list-users tools to AI agents.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $this->add([
            'name' => 'webmcp_enable_vocabularies',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Vocabulary tools', // @translate
                'info' => 'Expose list-vocabularies, list-properties, and resource-template tools.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);

        $this->add([
            'name' => 'webmcp_enable_bulk',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Bulk Operation tools', // @translate
                'info' => 'Expose batch-create-items and batch-delete-items tools to AI agents.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'value' => '1',
            ],
        ]);
    }
}
