<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

trait ApiResponse
{
    /**
     * Return a success JSON response.
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @return JsonResponse
     */
    public function success($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    public function error(string $message = 'Error', ?array $errors = [], int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?? [],
        ], $code);
    }

    /**
     * Return a paginated JSON response.
     *
     * @param mixed $resource
     * @param string $message
     * @return JsonResponse
     */
    public function paginated($resource, string $message = 'Success'): JsonResponse
    {
        if ($resource instanceof JsonResource || $resource instanceof ResourceCollection) {
            $resourceData = $resource->response()->getData(true);
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $resourceData['data'] ?? [],
                'links' => $resourceData['links'] ?? null,
                'meta' => $resourceData['meta'] ?? null,
            ]);
        }

        // Handle raw paginator objects
        $resourceArray = method_exists($resource, 'toArray') ? $resource->toArray() : (array) $resource;
        
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resourceArray['data'] ?? [],
            'links' => [
                'first' => $resourceArray['first_page_url'] ?? null,
                'last' => $resourceArray['last_page_url'] ?? null,
                'prev' => $resourceArray['prev_page_url'] ?? null,
                'next' => $resourceArray['next_page_url'] ?? null,
            ],
            'meta' => [
                'current_page' => $resourceArray['current_page'] ?? null,
                'from' => $resourceArray['from'] ?? null,
                'last_page' => $resourceArray['last_page'] ?? null,
                'path' => $resourceArray['path'] ?? null,
                'per_page' => $resourceArray['per_page'] ?? null,
                'to' => $resourceArray['to'] ?? null,
                'total' => $resourceArray['total'] ?? null,
            ],
        ]);
    }
}
