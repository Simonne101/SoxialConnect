<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Enveloppe commune des réponses de l'API /api/v1 :
 *   succès  : { "data": …, "message": …, "meta": {…} }
 *   erreur  : { "message": …, "errors": {…} }  (voir bootstrap/app.php)
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return response()->json([
            'data' => $data,
            'message' => $message,
            'meta' => (object) $meta,
        ], $status);
    }

    public static function created(mixed $data, ?string $message = null): JsonResponse
    {
        return self::ok($data, $message, [], 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /** Liste paginée : les informations de pagination vont dans « meta ». */
    public static function paginated(LengthAwarePaginator $paginator, string $resource, ?string $message = null): JsonResponse
    {
        /** @var class-string<JsonResource> $resource */
        $items = $resource::collection($paginator->getCollection());

        return self::ok(
            $items instanceof ResourceCollection ? $items->resolve(request()) : $items,
            $message,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public static function error(string $message, int $status, array $errors = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => (object) $errors], $status);
    }
}
