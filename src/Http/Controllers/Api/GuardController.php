<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Templite\Cms\Services\GuardRegistry;

class GuardController extends Controller
{
    public function __construct(
        protected GuardRegistry $guardRegistry
    ) {}

    /** @OA\Get(path="/guards", summary="Список доступных guard'ов", tags={"Guards"}, security={{"bearerAuth":{}}}, @OA\Response(response=200, description="Список")) */
    public function index(): JsonResponse
    {
        return $this->success($this->guardRegistry->getOptions());
    }

    /** @OA\Get(path="/guards/{guard}/permissions", summary="Permissions для guard'а", tags={"Guards"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="guard", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Список permissions")) */
    public function permissions(string $guard): JsonResponse
    {
        $permissions = $this->guardRegistry->getPermissionsForGuard($guard);

        return $this->success($permissions);
    }
}
