/**
 * WebMCP tool registration for Omeka-S admin interface.
 *
 * Registers tools via navigator.modelContext.registerTool() so that AI agents
 * (browser extensions, built-in browser agents) can discover and invoke them.
 *
 * @see https://webmachinelearning.github.io/webmcp/
 */

'use strict';

(function () {
    // Feature detection: only proceed if WebMCP API is available.
    if (
        typeof navigator === 'undefined' ||
        !navigator.modelContext ||
        typeof navigator.modelContext.registerTool !== 'function'
    ) {
        return;
    }

    /**
     * Retrieve the CSRF token from the Omeka-S admin page.
     *
     * @returns {string} CSRF token value, or empty string if not found.
     */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content') || '';
        }
        // Fallback: check for inline JS variable
        if (typeof Omeka !== 'undefined' && Omeka.csrfToken) {
            return Omeka.csrfToken;
        }
        return '';
    }

    /**
     * Build common fetch headers for Omeka-S REST API requests.
     *
     * @returns {Object} Headers object.
     */
    function apiHeaders() {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        const csrf = getCsrfToken();
        if (csrf) {
            headers['X-CSRF-Token'] = csrf;
        }
        return headers;
    }

    /**
     * Perform a fetch request against the Omeka-S REST API.
     *
     * @param {string} method  HTTP method.
     * @param {string} path    API path (e.g. '/api/items').
     * @param {Object} [body]  Request body for POST/PUT.
     * @returns {Promise<Object>} Parsed JSON response.
     */
    async function apiFetch(method, path, body) {
        const options = {
            method,
            headers: apiHeaders(),
            credentials: 'same-origin',
        };
        if (body !== undefined) {
            options.body = JSON.stringify(body);
        }
        const response = await fetch(path, options);
        if (!response.ok) {
            const text = await response.text();
            throw new Error(`API error ${response.status}: ${text}`);
        }
        return response.json();
    }

    /**
     * Structured error response returned by tool execute callbacks on failure.
     *
     * @param {Error|string} err
     * @returns {{error: boolean, message: string}}
     */
    function errorResult(err) {
        return { error: true, message: err instanceof Error ? err.message : String(err) };
    }

    // -------------------------------------------------------------------------
    // Tool group detection via WebMCPConfig injected by PHP
    // -------------------------------------------------------------------------
    const config = window.WebMCPConfig || {};
    const groupItems        = config.items        === true;
    const groupMedia        = config.media        === true;
    const groupItemSets     = config.item_sets    === true;
    const groupSites        = config.sites        === true;
    const groupUsers        = config.users        === true;
    const groupVocabularies = config.vocabularies === true;
    const groupBulk         = config.bulk         === true;

    // =========================================================================
    // Item / Resource Management Tools
    // =========================================================================

    if (groupItems) {
        navigator.modelContext.registerTool({
            name: 'create-item',
            description: 'Create a new item in Omeka-S.',
            inputSchema: {
                type: 'object',
                properties: {
                    resource_template_id: {
                        type: 'integer',
                        description: 'Optional resource template ID.',
                    },
                    item_set_ids: {
                        type: 'array',
                        items: { type: 'integer' },
                        description: 'Optional array of item set IDs.',
                    },
                    properties: {
                        type: 'object',
                        description: 'Object with property terms as keys, e.g. {"dcterms:title": [{"type": "literal", "@value": "My Item"}]}.',
                    },
                },
            },
            execute: async (input) => {
                try {
                    const body = { ...input.properties };
                    if (input.resource_template_id) {
                        body['o:resource_template'] = { 'o:id': input.resource_template_id };
                    }
                    if (input.item_set_ids && input.item_set_ids.length) {
                        body['o:item_set'] = input.item_set_ids.map((id) => ({ 'o:id': id }));
                    }
                    return await apiFetch('POST', '/api/items', body);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-item',
            description: 'Update an existing item in Omeka-S.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Item ID to update.' },
                    properties: {
                        type: 'object',
                        description: 'Partial update object with property terms as keys.',
                    },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('PUT', `/api/items/${input.id}`, input.properties || {});
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'delete-item',
            description: 'Delete an item from Omeka-S. Shows a confirmation dialog before deleting.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Item ID to delete.' },
                },
            },
            execute: async (input, client) => {
                try {
                    if (client && typeof client.requestUserInteraction === 'function') {
                        const confirmed = await client.requestUserInteraction({
                            type: 'confirm',
                            message: `Are you sure you want to delete item #${input.id}? This action cannot be undone.`,
                        });
                        if (!confirmed) {
                            return { cancelled: true, message: 'Deletion cancelled by user.' };
                        }
                    }
                    await apiFetch('DELETE', `/api/items/${input.id}`);
                    return { success: true, message: `Item #${input.id} deleted.` };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'search-items',
            description: 'Search for items in Omeka-S.',
            inputSchema: {
                type: 'object',
                properties: {
                    fulltext_search: { type: 'string', description: 'Full-text search query.' },
                    resource_template_id: { type: 'integer', description: 'Filter by resource template ID.' },
                    item_set_id: { type: 'integer', description: 'Filter by item set ID.' },
                    property: {
                        type: 'array',
                        description: 'Property filters.',
                        items: {
                            type: 'object',
                            properties: {
                                property: { type: 'string' },
                                type: { type: 'string' },
                                text: { type: 'string' },
                            },
                        },
                    },
                    per_page: { type: 'integer', default: 25, description: 'Results per page.' },
                    page: { type: 'integer', default: 1, description: 'Page number.' },
                },
            },
            execute: async (input) => {
                try {
                    const params = new URLSearchParams();
                    if (input.fulltext_search) params.set('fulltext_search', input.fulltext_search);
                    if (input.resource_template_id) params.set('resource_template_id', String(input.resource_template_id));
                    if (input.item_set_id) params.set('item_set_id', String(input.item_set_id));
                    params.set('per_page', String(input.per_page || 25));
                    params.set('page', String(input.page || 1));
                    if (input.property && Array.isArray(input.property)) {
                        input.property.forEach((filter, i) => {
                            if (filter.property) params.set(`property[${i}][property]`, filter.property);
                            if (filter.type) params.set(`property[${i}][type]`, filter.type);
                            if (filter.text) params.set(`property[${i}][text]`, filter.text);
                        });
                    }
                    const response = await fetch(`/api/items?${params.toString()}`, {
                        headers: apiHeaders(),
                        credentials: 'same-origin',
                    });
                    if (!response.ok) throw new Error(`API error ${response.status}`);
                    return response.json();
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'get-item',
            description: 'Get a single item by ID from Omeka-S.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Item ID.' },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('GET', `/api/items/${input.id}`);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Media Management Tools
    // =========================================================================

    if (groupMedia) {
        navigator.modelContext.registerTool({
            name: 'upload-media',
            description: 'Upload media to an item in Omeka-S using an existing upload form.',
            inputSchema: {
                type: 'object',
                required: ['item_id'],
                properties: {
                    item_id: { type: 'integer', description: 'The item ID to attach the media to.' },
                },
            },
            execute: async (input) => {
                try {
                    // Navigate to the item edit page so the user can upload via the form.
                    const url = `/admin/item/${input.item_id}/edit#media`;
                    window.location.href = url;
                    return { success: true, message: `Navigated to item #${input.item_id} edit page for media upload.` };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-media',
            description: 'List media for a specific item in Omeka-S.',
            inputSchema: {
                type: 'object',
                required: ['item_id'],
                properties: {
                    item_id: { type: 'integer', description: 'Item ID.' },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('GET', `/api/media?item_id=${input.item_id}`);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Item Set Management Tools
    // =========================================================================

    if (groupItemSets) {
        navigator.modelContext.registerTool({
            name: 'create-item-set',
            description: 'Create a new item set (collection) in Omeka-S.',
            inputSchema: {
                type: 'object',
                properties: {
                    properties: {
                        type: 'object',
                        description: 'Object with property terms as keys.',
                    },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('POST', '/api/item_sets', input.properties || {});
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-item-set',
            description: 'Update an existing item set in Omeka-S.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Item set ID to update.' },
                    properties: {
                        type: 'object',
                        description: 'Partial update object with property terms as keys.',
                    },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('PUT', `/api/item_sets/${input.id}`, input.properties || {});
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'delete-item-set',
            description: 'Delete an item set from Omeka-S. Shows a confirmation dialog before deleting.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Item set ID to delete.' },
                },
            },
            execute: async (input, client) => {
                try {
                    if (client && typeof client.requestUserInteraction === 'function') {
                        const confirmed = await client.requestUserInteraction({
                            type: 'confirm',
                            message: `Are you sure you want to delete item set #${input.id}? This action cannot be undone.`,
                        });
                        if (!confirmed) {
                            return { cancelled: true, message: 'Deletion cancelled by user.' };
                        }
                    }
                    await apiFetch('DELETE', `/api/item_sets/${input.id}`);
                    return { success: true, message: `Item set #${input.id} deleted.` };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-item-sets',
            description: 'List item sets (collections) in Omeka-S.',
            inputSchema: {
                type: 'object',
                properties: {
                    per_page: { type: 'integer', default: 25, description: 'Results per page.' },
                    page: { type: 'integer', default: 1, description: 'Page number.' },
                },
            },
            execute: async (input) => {
                try {
                    const params = new URLSearchParams({
                        per_page: String(input.per_page || 25),
                        page: String(input.page || 1),
                    });
                    const response = await fetch(`/api/item_sets?${params.toString()}`, {
                        headers: apiHeaders(),
                        credentials: 'same-origin',
                    });
                    if (!response.ok) throw new Error(`API error ${response.status}`);
                    return response.json();
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Site Management Tools
    // =========================================================================

    if (groupSites) {
        navigator.modelContext.registerTool({
            name: 'create-site',
            description: 'Create a new Omeka-S site.',
            inputSchema: {
                type: 'object',
                required: ['o:title', 'o:slug'],
                properties: {
                    'o:title': { type: 'string', description: 'Site title.' },
                    'o:slug': { type: 'string', description: 'Site URL slug.' },
                    'o:theme': { type: 'string', description: 'Theme name (optional).' },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('POST', '/api/sites', input);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-site',
            description: 'Update an existing Omeka-S site.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Site ID to update.' },
                    'o:title': { type: 'string', description: 'New title.' },
                    'o:slug': { type: 'string', description: 'New URL slug.' },
                    'o:theme': { type: 'string', description: 'New theme name.' },
                },
            },
            execute: async (input) => {
                try {
                    const { id, ...fields } = input;
                    return await apiFetch('PUT', `/api/sites/${id}`, fields);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-sites',
            description: 'List all Omeka-S sites.',
            inputSchema: { type: 'object', properties: {} },
            execute: async () => {
                try {
                    return await apiFetch('GET', '/api/sites');
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // User Management Tools
    // =========================================================================

    if (groupUsers) {
        navigator.modelContext.registerTool({
            name: 'create-user',
            description: 'Create a new Omeka-S user.',
            inputSchema: {
                type: 'object',
                required: ['o:name', 'o:email', 'o:role'],
                properties: {
                    'o:name': { type: 'string', description: 'User display name.' },
                    'o:email': { type: 'string', description: 'User email address.' },
                    'o:role': {
                        type: 'string',
                        enum: ['global_admin', 'site_admin', 'editor', 'reviewer', 'author', 'researcher'],
                        description: 'User role.',
                    },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('POST', '/api/users', input);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-user',
            description: 'Update an existing Omeka-S user.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'User ID to update.' },
                    'o:name': { type: 'string', description: 'New display name.' },
                    'o:email': { type: 'string', description: 'New email address.' },
                    'o:role': {
                        type: 'string',
                        enum: ['global_admin', 'site_admin', 'editor', 'reviewer', 'author', 'researcher'],
                    },
                },
            },
            execute: async (input) => {
                try {
                    const { id, ...fields } = input;
                    return await apiFetch('PUT', `/api/users/${id}`, fields);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'delete-user',
            description: 'Delete an Omeka-S user. Shows a confirmation dialog before deleting.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'User ID to delete.' },
                },
            },
            execute: async (input, client) => {
                try {
                    if (client && typeof client.requestUserInteraction === 'function') {
                        const confirmed = await client.requestUserInteraction({
                            type: 'confirm',
                            message: `Are you sure you want to delete user #${input.id}? This action cannot be undone.`,
                        });
                        if (!confirmed) {
                            return { cancelled: true, message: 'Deletion cancelled by user.' };
                        }
                    }
                    await apiFetch('DELETE', `/api/users/${input.id}`);
                    return { success: true, message: `User #${input.id} deleted.` };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-users',
            description: 'List all Omeka-S users.',
            inputSchema: { type: 'object', properties: {} },
            execute: async () => {
                try {
                    return await apiFetch('GET', '/api/users');
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Vocabulary & Resource Template Tools
    // =========================================================================

    if (groupVocabularies) {
        navigator.modelContext.registerTool({
            name: 'list-vocabularies',
            description: 'List available vocabularies (e.g. Dublin Core) in Omeka-S.',
            inputSchema: { type: 'object', properties: {} },
            execute: async () => {
                try {
                    return await apiFetch('GET', '/api/vocabularies');
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-properties',
            description: 'List properties belonging to a vocabulary in Omeka-S.',
            inputSchema: {
                type: 'object',
                required: ['vocabulary_id'],
                properties: {
                    vocabulary_id: { type: 'integer', description: 'Vocabulary ID.' },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('GET', `/api/properties?vocabulary_id=${input.vocabulary_id}`);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-resource-templates',
            description: 'List resource templates available in Omeka-S.',
            inputSchema: { type: 'object', properties: {} },
            execute: async () => {
                try {
                    return await apiFetch('GET', '/api/resource_templates');
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'get-resource-template',
            description: 'Get a resource template by ID from Omeka-S.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id: { type: 'integer', description: 'Resource template ID.' },
                },
            },
            execute: async (input) => {
                try {
                    return await apiFetch('GET', `/api/resource_templates/${input.id}`);
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Bulk Operation Tools
    // =========================================================================

    if (groupBulk) {
        navigator.modelContext.registerTool({
            name: 'batch-create-items',
            description: 'Create multiple items in Omeka-S in a single operation.',
            inputSchema: {
                type: 'object',
                required: ['items'],
                properties: {
                    items: {
                        type: 'array',
                        description: 'Array of item objects to create.',
                        items: { type: 'object' },
                    },
                },
            },
            execute: async (input) => {
                try {
                    const results = [];
                    for (const item of input.items) {
                        const result = await apiFetch('POST', '/api/items', item);
                        results.push(result);
                    }
                    return { success: true, created: results.length, items: results };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'batch-delete-items',
            description: 'Delete multiple items from Omeka-S. Shows a confirmation dialog before deleting.',
            inputSchema: {
                type: 'object',
                required: ['ids'],
                properties: {
                    ids: {
                        type: 'array',
                        items: { type: 'integer' },
                        description: 'Array of item IDs to delete.',
                    },
                },
            },
            execute: async (input, client) => {
                try {
                    if (client && typeof client.requestUserInteraction === 'function') {
                        const confirmed = await client.requestUserInteraction({
                            type: 'confirm',
                            message: `Are you sure you want to delete ${input.ids.length} item(s)? This action cannot be undone.`,
                        });
                        if (!confirmed) {
                            return { cancelled: true, message: 'Batch deletion cancelled by user.' };
                        }
                    }
                    const deleted = [];
                    for (const id of input.ids) {
                        await apiFetch('DELETE', `/api/items/${id}`);
                        deleted.push(id);
                    }
                    return { success: true, deleted: deleted.length, ids: deleted };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Declarative API: add WebMCP attributes to known Omeka-S admin forms
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        /**
         * Add WebMCP declarative attributes to a form element.
         *
         * @param {HTMLFormElement} form
         * @param {string} toolname
         * @param {string} tooldescription
         * @param {boolean} autosubmit
         */
        function annotateForm(form, toolname, tooldescription, autosubmit) {
            if (!form) return;
            form.setAttribute('toolname', toolname);
            form.setAttribute('tooldescription', tooldescription);
            if (autosubmit) {
                form.setAttribute('toolautosubmit', 'true');
            }
        }

        // Item edit form
        const itemEditForm = document.querySelector('#item-form, form.resource-form[action*="/item/"]');
        annotateForm(
            itemEditForm,
            'edit-item-form',
            'Edit the current item\'s metadata and properties',
            true
        );

        // Item set edit form
        const itemSetEditForm = document.querySelector('#item-set-form, form.resource-form[action*="/item-set/"]');
        annotateForm(
            itemSetEditForm,
            'edit-item-set-form',
            'Edit the current item set\'s metadata and properties',
            true
        );

        // Site settings form
        const siteSettingsForm = document.querySelector('#site-settings, form[action*="/site/"]');
        annotateForm(
            siteSettingsForm,
            'edit-site-settings-form',
            'Edit the current site\'s settings',
            true
        );

        // User edit form
        const userEditForm = document.querySelector('#user-form, form[action*="/user/"]');
        annotateForm(
            userEditForm,
            'edit-user-form',
            'Edit the current user\'s profile and settings',
            true
        );

        // Admin sidebar search form
        const searchForm = document.querySelector('#search form, .search-form, form[action*="/admin/search"]');
        annotateForm(
            searchForm,
            'search-resources',
            'Search for items, item sets, or media in the Omeka-S catalog',
            false
        );
    });
})();
