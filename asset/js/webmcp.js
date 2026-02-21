/**
 * WebMCP tool registration for Omeka-S admin interface.
 *
 * Registers tools via navigator.modelContext.registerTool() so that AI agents
 * (browser extensions, built-in browser agents) can discover and invoke them.
 *
 * All operations are routed through the server-side proxy at
 * /admin/webmcp/proxy, which uses Omeka\ApiManager internally and therefore
 * runs inside Omeka-S's own request lifecycle. This bypasses any JWT
 * middleware that would block direct /api/* calls.
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

    // Runtime config injected by PHP (tool-group flags, CSRF token, proxy URL).
    const config           = window.WebMCPConfig || {};
    const groupItems       = config.items        === true;
    const groupMedia       = config.media        === true;
    const groupItemSets    = config.item_sets    === true;
    const groupSites       = config.sites        === true;
    const groupUsers       = config.users        === true;
    const groupVocabs      = config.vocabularies === true;
    const groupBulk        = config.bulk         === true;
    const _proxyUrl        = config.proxy_url    || '/admin/webmcp/proxy';

    /**
     * Retrieve the CSRF token injected by PHP into window.WebMCPConfig.
     *
     * @returns {string}
     */
    function getCsrfToken() {
        return (window.WebMCPConfig && window.WebMCPConfig.csrf_token) || '';
    }

    /**
     * POST a payload to the server-side proxy and return the parsed response.
     *
     * The proxy wraps results in {success:true, data:...} or {error:true, message:...}.
     * This function returns the full wrapper object — callers should check
     * result.error before using result.data.
     *
     * @param {Object} payload  {op, resource, id?, query?, data?, ids?}
     * @returns {Promise<Object>}
     */
    async function proxyFetch(payload) {
        const response = await fetch(_proxyUrl, {
            method: 'POST',
            // 'include' (not 'same-origin') is required because the WebMCP
            // extension calls execute callbacks from an isolated context whose
            // origin is chrome-extension://, not the page origin.
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken(),
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            const text = await response.text();
            let message = `Proxy error ${response.status}`;
            try {
                const json = JSON.parse(text);
                message = json.message || message;
                if (json.details) message += `: ${json.details}`;
            } catch (_) { /* keep generic message */ }
            throw new Error(message);
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
    // Role-awareness: detect the current user's role via the proxy so the AI
    // can skip privileged operations it would not be permitted to run.
    // -------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        const userLink = document.querySelector('#user-bar a[href*="/admin/user/"]');
        if (!userLink) return;
        const match = userLink.getAttribute('href').match(/\/user\/(\d+)/);
        if (!match) return;
        proxyFetch({ op: 'get', resource: 'users', id: parseInt(match[1], 10) })
            .then((result) => {
                if (!result.error && result.data && result.data['o:role']) {
                    window.WebMCPConfig = window.WebMCPConfig || {};
                    window.WebMCPConfig.currentRole = result.data['o:role'];
                }
            })
            .catch(() => {});
    });

    // =========================================================================
    // Item / Resource Management Tools
    // =========================================================================

    if (groupItems) {
        navigator.modelContext.registerTool({
            name: 'create-item',
            description: 'Create a new item in Omeka-S. Requires role: editor, site_admin, or global_admin.',
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
                    const data = { ...input.properties };
                    if (input.resource_template_id) {
                        data['o:resource_template'] = { 'o:id': input.resource_template_id };
                    }
                    if (input.item_set_ids && input.item_set_ids.length) {
                        data['o:item_set'] = input.item_set_ids.map((id) => ({ 'o:id': id }));
                    }
                    const result = await proxyFetch({ op: 'create', resource: 'items', data });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-item',
            description: 'Update an existing item in Omeka-S. Requires role: editor, site_admin, or global_admin.',
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
                    const result = await proxyFetch({
                        op: 'update',
                        resource: 'items',
                        id: input.id,
                        data: input.properties || {},
                    });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'delete-item',
            description: 'Delete an item from Omeka-S. Shows a confirmation dialog before deleting. Requires role: editor, site_admin, or global_admin.',
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
                    const result = await proxyFetch({ op: 'delete', resource: 'items', id: input.id });
                    if (result.error) return result;
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
                    const query = {};
                    if (input.fulltext_search)     query.fulltext_search     = input.fulltext_search;
                    if (input.resource_template_id) query.resource_template_id = input.resource_template_id;
                    if (input.item_set_id)          query.item_set_id          = input.item_set_id;
                    query.per_page = input.per_page || 25;
                    query.page     = input.page     || 1;
                    if (input.property && Array.isArray(input.property)) {
                        query.property = input.property;
                    }
                    const result = await proxyFetch({ op: 'search', resource: 'items', query });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({ op: 'get', resource: 'items', id: input.id });
                    if (result.error) return result;
                    return result.data;
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
                    window.location.href = `/admin/item/${input.item_id}/edit#media`;
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
                    const result = await proxyFetch({
                        op: 'search',
                        resource: 'media',
                        query: { item_id: input.item_id },
                    });
                    if (result.error) return result;
                    return result.data;
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
            description: 'Create a new item set (collection) in Omeka-S. Requires role: editor, site_admin, or global_admin.',
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
                    const result = await proxyFetch({
                        op: 'create',
                        resource: 'item_sets',
                        data: input.properties || {},
                    });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-item-set',
            description: 'Update an existing item set in Omeka-S. Requires role: editor, site_admin, or global_admin.',
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
                    const result = await proxyFetch({
                        op: 'update',
                        resource: 'item_sets',
                        id: input.id,
                        data: input.properties || {},
                    });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'delete-item-set',
            description: 'Delete an item set from Omeka-S. Shows a confirmation dialog before deleting. Requires role: editor, site_admin, or global_admin.',
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
                    const result = await proxyFetch({ op: 'delete', resource: 'item_sets', id: input.id });
                    if (result.error) return result;
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
                    const result = await proxyFetch({
                        op: 'search',
                        resource: 'item_sets',
                        query: { per_page: input.per_page || 25, page: input.page || 1 },
                    });
                    if (result.error) return result;
                    return result.data;
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
            description: 'Create a new Omeka-S site. Requires role: global_admin.',
            inputSchema: {
                type: 'object',
                required: ['o:title', 'o:slug'],
                properties: {
                    'o:title': { type: 'string', description: 'Site title.' },
                    'o:slug':  { type: 'string', description: 'Site URL slug.' },
                    'o:theme': { type: 'string', description: 'Theme name (optional).' },
                },
            },
            execute: async (input) => {
                try {
                    const result = await proxyFetch({ op: 'create', resource: 'sites', data: input });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-site',
            description: 'Update an existing Omeka-S site. Requires role: global_admin.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id:        { type: 'integer', description: 'Site ID to update.' },
                    'o:title': { type: 'string',  description: 'New title.' },
                    'o:slug':  { type: 'string',  description: 'New URL slug.' },
                    'o:theme': { type: 'string',  description: 'New theme name.' },
                },
            },
            execute: async (input) => {
                try {
                    const { id, ...fields } = input;
                    const result = await proxyFetch({ op: 'update', resource: 'sites', id, data: fields });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({ op: 'search', resource: 'sites' });
                    if (result.error) return result;
                    return result.data;
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
            description: 'Create a new Omeka-S user. Requires role: global_admin.',
            inputSchema: {
                type: 'object',
                required: ['o:name', 'o:email', 'o:role'],
                properties: {
                    'o:name':  { type: 'string', description: 'User display name.' },
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
                    // o:is_active must be true or the account cannot log in.
                    const result = await proxyFetch({
                        op: 'create',
                        resource: 'users',
                        data: { ...input, 'o:is_active': true },
                    });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'update-user',
            description: 'Update an existing Omeka-S user. Requires role: global_admin.',
            inputSchema: {
                type: 'object',
                required: ['id'],
                properties: {
                    id:        { type: 'integer', description: 'User ID to update.' },
                    'o:name':  { type: 'string',  description: 'New display name.' },
                    'o:email': { type: 'string',  description: 'New email address.' },
                    'o:role': {
                        type: 'string',
                        enum: ['global_admin', 'site_admin', 'editor', 'reviewer', 'author', 'researcher'],
                    },
                },
            },
            execute: async (input) => {
                try {
                    const { id, ...fields } = input;
                    const result = await proxyFetch({ op: 'update', resource: 'users', id, data: fields });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'delete-user',
            description: 'Delete an Omeka-S user. Shows a confirmation dialog before deleting. Requires role: global_admin.',
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
                    const result = await proxyFetch({ op: 'delete', resource: 'users', id: input.id });
                    if (result.error) return result;
                    return { success: true, message: `User #${input.id} deleted.` };
                } catch (err) {
                    return errorResult(err);
                }
            },
        });

        navigator.modelContext.registerTool({
            name: 'list-users',
            description: 'List all Omeka-S users. Requires role: global_admin.',
            inputSchema: { type: 'object', properties: {} },
            execute: async () => {
                try {
                    const result = await proxyFetch({ op: 'search', resource: 'users' });
                    if (result.error) return result;
                    return result.data;
                } catch (err) {
                    return errorResult(err);
                }
            },
        });
    }

    // =========================================================================
    // Vocabulary & Resource Template Tools
    // =========================================================================

    if (groupVocabs) {
        navigator.modelContext.registerTool({
            name: 'list-vocabularies',
            description: 'List available vocabularies (e.g. Dublin Core) in Omeka-S.',
            inputSchema: { type: 'object', properties: {} },
            execute: async () => {
                try {
                    const result = await proxyFetch({ op: 'search', resource: 'vocabularies' });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({
                        op: 'search',
                        resource: 'properties',
                        query: { vocabulary_id: input.vocabulary_id },
                    });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({ op: 'search', resource: 'resource_templates' });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({ op: 'get', resource: 'resource_templates', id: input.id });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({
                        op: 'batch_create',
                        resource: 'items',
                        data: input.items,
                    });
                    if (result.error) return result;
                    return result.data;
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
                    const result = await proxyFetch({
                        op: 'batch_delete',
                        resource: 'items',
                        ids: input.ids,
                    });
                    if (result.error) return result;
                    return result.data;
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
