<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Classifier\CostCapExceededException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class HandleApiErrors
{
    public function handle(Request $request, Closure $next): JsonResponse|Response
    {
        try {
            return $next($request);
        } catch (ValidationException $exception) {
            return ApiResponse::error('validation_failed', 'The given data was invalid.', $exception->errors(), 422);
        } catch (ModelNotFoundException|NotFoundHttpException $exception) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        } catch (CostCapExceededException $exception) {
            return ApiResponse::error('cost_cap_exceeded', $exception->getMessage(), [], 422);
        } catch (AuthorizationException|AuthenticationException $exception) {
            return ApiResponse::error('unauthorized', $exception->getMessage(), [], 401);
        } catch (ConflictHttpException $exception) {
            return ApiResponse::error('conflict', $exception->getMessage(), [], 409);
        } catch (TooManyRequestsHttpException $exception) {
            return ApiResponse::error('rate_limited', $exception->getMessage(), [], 429);
        } catch (HttpExceptionInterface $exception) {
            return ApiResponse::error(
                $this->errorCodeForStatus($exception->getStatusCode()),
                $exception->getMessage(),
                [],
                $exception->getStatusCode(),
            );
        } catch (Throwable) {
            return ApiResponse::error('internal_error', 'Internal server error', [], 500);
        }
    }

    private function errorCodeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => 'internal_error',
        };
    }
}
