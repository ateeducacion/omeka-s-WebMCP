<?php
declare(strict_types=1);

namespace WebMCP\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\PermissionDeniedException;
use Omeka\Api\Exception\ValidationException;

/**
 * Server-side proxy for WebMCP tool and resource operations.
 *
 * All JS tool/resource callbacks POST to /admin/webmcp/proxy instead of
 * calling /api/* directly. The proxy uses Omeka\ApiManager internally, so
 * it runs inside Omeka-S's own request lifecycle and benefits from the
 * authenticated PHP session — bypassing any JWT middleware that would block
 * direct /api/* calls.
 */
class WebMCPProxyController extends AbstractActionController
{
    public function proxyAction(): JsonModel
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            $this->getResponse()->setStatusCode(405);
            return new JsonModel(['error' => true, 'message' => 'Method not allowed.']);
        }

        // CSRF validation: token is sent in the X-CSRF-Token request header.
        $csrfHeader = $request->getHeader('X-CSRF-Token');
        $token      = $csrfHeader ? $csrfHeader->getFieldValue() : '';
        if (!$this->isCsrfTokenValid($token)) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => true, 'message' => 'Invalid CSRF token.']);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => true, 'message' => 'Invalid JSON body.']);
        }

        $op       = isset($body['op'])       ? (string) $body['op']       : '';
        $resource = isset($body['resource']) ? (string) $body['resource'] : '';
        $id       = $body['id']       ?? null;
        $query    = isset($body['query'])    && is_array($body['query'])    ? $body['query']    : [];
        $data     = $body['data']     ?? null;
        $ids      = isset($body['ids'])      && is_array($body['ids'])      ? $body['ids']      : [];

        if ($op === '' || $resource === '') {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => true, 'message' => 'Missing required fields: op, resource.']);
        }

        try {
            $result = $this->runOperation($op, $resource, $id, $query, $data, $ids);
            return new JsonModel(['success' => true, 'data' => $result]);
        } catch (PermissionDeniedException $e) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => true, 'message' => 'Permission denied.', 'details' => $e->getMessage()]);
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(404);
            return new JsonModel(['error' => true, 'message' => 'Not found.', 'details' => $e->getMessage()]);
        } catch (ValidationException $e) {
            $this->getResponse()->setStatusCode(422);
            return new JsonModel(['error' => true, 'message' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => true, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(500);
            return new JsonModel(['error' => true, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Validate the CSRF token provided in the request header.
     *
     * Extracted to a protected method so test subclasses can bypass the
     * session-backed Laminas\Validator\Csrf without needing a real session.
     *
     * @param string $token
     * @return bool
     */
    protected function isCsrfTokenValid(string $token): bool
    {
        $csrf = new Csrf(['name' => 'webmcp_proxy', 'timeout' => null]);
        return $csrf->isValid($token);
    }

    /**
     * Dispatch the requested operation to Omeka\ApiManager.
     *
     * @param string     $op       Operation: search|get|create|update|delete|batch_create|batch_delete
     * @param string     $resource API resource type (e.g. 'items', 'item_sets', 'users')
     * @param mixed      $id       Resource ID (for get/update/delete)
     * @param array      $query    Search query parameters
     * @param mixed      $data     Resource data (array) or array of items for batch_create
     * @param array      $ids      Array of IDs for batch_delete
     * @return array
     */
    protected function runOperation(string $op, string $resource, $id, array $query, $data, array $ids): array
    {
        switch ($op) {
            case 'search':
                $response = $this->api()->search($resource, $query);
                return [
                    'items'         => json_decode(json_encode($response->getContent()), true),
                    'total_results' => $response->getTotalResults(),
                ];

            case 'get':
                return json_decode(json_encode(
                    $this->api()->read($resource, $id)->getContent()
                ), true);

            case 'create':
                return json_decode(json_encode(
                    $this->api()->create($resource, is_array($data) ? $data : [])->getContent()
                ), true);

            case 'update':
                // Read current representation first so that the PUT does not wipe
                // fields that the caller did not explicitly include in $data.
                $current = json_decode(json_encode(
                    $this->api()->read($resource, $id)->getContent()
                ), true);
                $merged  = array_merge($current, is_array($data) ? $data : []);
                return json_decode(json_encode(
                    $this->api()->update($resource, $id, $merged)->getContent()
                ), true);

            case 'delete':
                $this->api()->delete($resource, $id);
                return ['deleted' => true, 'id' => $id];

            case 'batch_create':
                $results = [];
                $errors  = [];
                foreach ((array) $data as $item) {
                    try {
                        $results[] = json_decode(json_encode(
                            $this->api()->create($resource, is_array($item) ? $item : [])->getContent()
                        ), true);
                    } catch (\Exception $e) {
                        $errors[] = ['error' => true, 'message' => $e->getMessage()];
                    }
                }
                return [
                    'success' => count($errors) === 0,
                    'created' => count($results),
                    'failed'  => count($errors),
                    'items'   => $results,
                    'errors'  => $errors,
                ];

            case 'batch_delete':
                $deleted = [];
                $errors  = [];
                foreach ($ids as $itemId) {
                    try {
                        $this->api()->delete($resource, $itemId);
                        $deleted[] = $itemId;
                    } catch (\Exception $e) {
                        $errors[] = ['id' => $itemId, 'error' => true, 'message' => $e->getMessage()];
                    }
                }
                return [
                    'deleted' => count($deleted),
                    'failed'  => count($errors),
                    'ids'     => $deleted,
                    'errors'  => $errors,
                ];

            default:
                throw new \InvalidArgumentException("Unknown operation: {$op}");
        }
    }
}
