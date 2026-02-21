# WebMCP — Omeka-S Module

An Omeka-S module that implements the [WebMCP standard](https://webmachinelearning.github.io/webmcp/) to expose Omeka-S backend management capabilities to AI agents through the browser.

## What is WebMCP?

WebMCP is a [W3C Community Group draft specification](https://webmachinelearning.github.io/webmcp/) that allows web pages to register "tools" via JavaScript so AI agents (browser extensions, built-in browser agents) can discover and invoke them. Tools are registered using `navigator.modelContext.registerTool()` with a name, description, JSON Schema input, and an execute callback. There is also a declarative API using HTML attributes (`toolname`, `tooldescription`, `toolautosubmit`) on `<form>` elements.

This module uses both APIs where appropriate.

## Quick Start (Docker)

- Requirements: Docker Desktop 4+, Make
- Start stack: `make up` then open `http://localhost:8080`
- Stop stack: `make down`

Users created automatically:
- `admin@example.com` (global_admin) password: `PLEASE_CHANGEME`
- `editor@example.com` (editor) password: `1234`

### Useful Make Targets

- `make up` / `make upd`: Run in foreground/background
- `make down` / `make clean`: Stop, optionally remove volumes
- `make test`: Run PHPUnit tests
- `make lint`: Run PHP code style checker

## Installation

1. Download or clone this repository into `modules/WebMCP` inside your Omeka-S installation.
2. In the Omeka-S admin panel, go to **Modules** and install **WebMCP**.
3. Configure which tool groups to expose under **Modules → WebMCP → Configure**.

## How to Test with WebMCP

1. Install [Chrome Canary](https://www.google.com/chrome/canary/).
2. Enable the WebMCP flag: navigate to `chrome://flags/#enable-web-mcp` and enable it.
3. Install the [WebMCP Chrome extension](https://github.com/webmachinelearning/webmcp) (if available).
4. Open the Omeka-S admin interface — the module automatically registers tools and resources.
5. Open the AI agent side-panel in Chrome to interact with the registered tools.

## Exposed Tools

### Item / Resource Management
| Tool | Description |
|------|-------------|
| `create-item` | Create a new item |
| `update-item` | Update an existing item |
| `delete-item` | Delete an item (with confirmation) |
| `search-items` | Search items by full-text, template, item set, or properties |
| `get-item` | Get a single item by ID |

### Media Management
| Tool | Description |
|------|-------------|
| `upload-media` | Navigate to item edit page for media upload |
| `list-media` | List media for a specific item |

### Item Set Management
| Tool | Description |
|------|-------------|
| `create-item-set` | Create a new item set (collection) |
| `update-item-set` | Update an existing item set |
| `delete-item-set` | Delete an item set (with confirmation) |
| `list-item-sets` | List all item sets |

### Site Management
| Tool | Description |
|------|-------------|
| `create-site` | Create a new Omeka-S site |
| `update-site` | Update a site |
| `list-sites` | List all sites |

### User Management
| Tool | Description |
|------|-------------|
| `create-user` | Create a new user |
| `update-user` | Update a user |
| `delete-user` | Delete a user (with confirmation) |
| `list-users` | List all users |

### Vocabulary & Resource Templates
| Tool | Description |
|------|-------------|
| `list-vocabularies` | List available vocabularies |
| `list-properties` | List properties of a vocabulary |
| `list-resource-templates` | List resource templates |
| `get-resource-template` | Get a resource template by ID |

### Bulk Operations
| Tool | Description |
|------|-------------|
| `batch-create-items` | Create multiple items at once |
| `batch-delete-items` | Delete multiple items (with confirmation) |

## Exposed Resources

| Resource | URI | Description |
|----------|-----|-------------|
| `omeka-dashboard` | `omeka://dashboard` | Instance summary (totals, recent items) |
| `omeka-item` | `omeka://items/{id}` | Full JSON-LD for a specific item |
| `omeka-site-navigation` | `omeka://sites/{id}/navigation` | Site navigation structure |
| `omeka-api-info` | `omeka://api-info` | Available API endpoints and current user role |

## Configuration

After installation, visit **Modules → WebMCP → Configure** to enable or disable individual tool groups:
- Item tools
- Media tools
- Item Set tools
- Site tools
- User tools
- Vocabulary tools
- Bulk operation tools

## Project Structure

```text
WebMCP/
├── Module.php                       # Main module class
├── config/
│   ├── module.ini                   # Module metadata
│   └── module.config.php            # Laminas MVC configuration
├── src/
│   └── Form/
│       └── ConfigForm.php           # Admin configuration form
├── asset/
│   └── js/
│       ├── webmcp.js                # Tool registrations (imperative API)
│       └── webmcp-resources.js      # Resource registrations
├── language/                        # Translations
├── test/                            # PHPUnit tests
├── docker-compose.yml               # Dev stack
├── Makefile                         # Dev helpers
└── README.md                        # This file
```

## Requirements

- Omeka S 4.x or later
- PHP 8.1+

## License

Published under the GNU GPLv3 license. See [LICENSE](LICENSE).
