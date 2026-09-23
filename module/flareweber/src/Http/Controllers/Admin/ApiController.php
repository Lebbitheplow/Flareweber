<?php

namespace FlareWeber\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Base for the SPA JSON API: validation always answers 422 JSON (never a
 * redirect), and the common error envelopes live in one place.
 */
abstract class ApiController extends Controller
{
    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $rules): array
    {
        try {
            return Validator::make($request->all(), $rules)->validate();
        } catch (ValidationException $e) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['error' => 'validation_failed', 'errors' => $e->errors()], 422)
            );
        }
    }

    protected function notFound(string $what = 'not_found'): JsonResponse
    {
        return response()->json(['error' => $what], 404);
    }

    protected function failed(\Throwable $e, string $code = 'failed', int $status = 500): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $e->getMessage()], $status);
    }
}
