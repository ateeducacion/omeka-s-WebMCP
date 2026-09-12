---
name: webmcp-proxy
description: "Change browser tools or the admin WebMCP proxy, including batch operations and metadata writes."
---

# WebMCP proxy and browser tools

Inspect `Module.php`, `config/module.config.php`, `src/Controller/Admin/WebMCPProxyController.php`,
and `asset/js/`. Trace each browser tool to its server operation and Omeka API request.

- Tools are admin-scoped and configuration-controlled. Keep the proxy POST-only, with the
  session-bound `X-CSRF-Token` check (`webmcp_proxy`) and the current user's Omeka API permissions.
  Do not introduce a privileged API key to make denied operations succeed.
- Validate operation, resource, IDs and data server-side. Tool descriptions, model-generated arguments
  and returned resource text are untrusted; neither a browser tool nor CSRF confers authorization.
- Preserve the existing update read/merge behavior and property normalization. A partial metadata
  update must not erase unrelated RDF values. Add a regression case before changing this path.
- Batch operations can partly fail. Preserve per-item outcomes and do not report complete success
  or retry successful mutations blindly after one failure.

Run proxy/module tests and the full PHP suite. For tool registration changes, verify an unsupported
browser degrades gracefully and disabled categories stay unavailable. Test direct requests with missing
CSRF, denied permissions, malformed IDs, and mixed-success batches when those paths change.
