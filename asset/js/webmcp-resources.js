/**
 * WebMCP resource registration for Omeka-S admin interface.
 *
 * Exposes read-only data resources that AI agents can query for context
 * about the current Omeka-S instance.
 *
 * @see https://webmachinelearning.github.io/webmcp/
 */

'use strict';

(function () {
    // Feature detection: only proceed if WebMCP API is available.
    if (
        typeof navigator === 'undefined' ||
        !navigator.modelContext ||
        typeof navigator.modelContext.registerResource !== 'function'
    ) {
        return;
    }

    /**
     * Build common fetch headers for Omeka-S REST API requests.
     *
     * @returns {Object} Headers object.
     */
    function apiHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
    }

    /**
     * Perform a GET fetch request against the Omeka-S REST API.
     *
     * @param {string} path  API path (e.g. '/api/items').
     * @returns {Promise<Object>} Parsed JSON response.
     */
    async function apiGet(path) {
        const response = await fetch(path, {
            method: 'GET',
            headers: apiHeaders(),
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error(`API error ${response.status}`);
        }
        return response.json();
    }

    // =========================================================================
    // Resource: omeka-dashboard
    // URI: omeka://dashboard
    // Returns a JSON summary of the Omeka-S instance.
    // =========================================================================

    navigator.modelContext.registerResource({
        uri: 'omeka://dashboard',
        name: 'omeka-dashboard',
        description: 'Returns a JSON summary of the Omeka-S instance: total items, item sets, sites, users, and recent items.',
        read: async () => {
            try {
                const [items, itemSets, sites, users, recentItems] = await Promise.all([
                    apiGet('/api/items?per_page=0'),
                    apiGet('/api/item_sets?per_page=0'),
                    apiGet('/api/sites?per_page=0'),
                    apiGet('/api/users?per_page=0'),
                    apiGet('/api/items?per_page=5&sort_by=created&sort_order=desc'),
                ]);

                return {
                    total_items: Array.isArray(items) ? items.length : (items['totalResults'] || 0),
                    total_item_sets: Array.isArray(itemSets) ? itemSets.length : (itemSets['totalResults'] || 0),
                    total_sites: Array.isArray(sites) ? sites.length : (sites['totalResults'] || 0),
                    total_users: Array.isArray(users) ? users.length : (users['totalResults'] || 0),
                    recent_items: recentItems,
                };
            } catch (err) {
                return { error: true, message: err.message };
            }
        },
    });

    // =========================================================================
    // Resource: omeka-item
    // URI template: omeka://items/{id}
    // Returns full JSON-LD representation of a specific item.
    // =========================================================================

    navigator.modelContext.registerResource({
        uriTemplate: 'omeka://items/{id}',
        name: 'omeka-item',
        description: 'Returns the full JSON-LD representation of a specific Omeka-S item.',
        read: async ({ id }) => {
            try {
                return await apiGet(`/api/items/${id}`);
            } catch (err) {
                return { error: true, message: err.message };
            }
        },
    });

    // =========================================================================
    // Resource: omeka-site-navigation
    // URI template: omeka://sites/{id}/navigation
    // Returns the navigation structure of a site.
    // =========================================================================

    navigator.modelContext.registerResource({
        uriTemplate: 'omeka://sites/{id}/navigation',
        name: 'omeka-site-navigation',
        description: 'Returns the navigation structure of an Omeka-S site.',
        read: async ({ id }) => {
            try {
                const site = await apiGet(`/api/sites/${id}`);
                return {
                    site_id: id,
                    navigation: site['o:navigation'] || [],
                };
            } catch (err) {
                return { error: true, message: err.message };
            }
        },
    });

    // =========================================================================
    // Resource: omeka-api-info
    // URI: omeka://api-info
    // Returns available API endpoints and the current user's permissions/role.
    // =========================================================================

    navigator.modelContext.registerResource({
        uri: 'omeka://api-info',
        name: 'omeka-api-info',
        description: 'Returns available API endpoints and the current user\'s permissions and role.',
        read: async () => {
            try {
                const [apiInfo, user] = await Promise.all([
                    apiGet('/api'),
                    apiGet('/api/users/me').catch(() => null),
                ]);

                return {
                    api_info: apiInfo,
                    current_user: user,
                };
            } catch (err) {
                return { error: true, message: err.message };
            }
        },
    });
})();
