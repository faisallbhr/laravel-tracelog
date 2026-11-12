<?php

namespace TraceLog\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use TraceLog\Exceptions\TraceReaderException;
use TraceLog\Internal\TraceReader;

/** Serves the optional read-only TraceLog viewer. */
final class TraceLogViewerController
{
    public function __construct(private readonly TraceReader $reader) {}

    public function page(): View
    {
        $this->authorize();

        return view('tracelog::viewer');
    }

    public function css(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../../../resources/css/viewer.css', [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function js(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../../../resources/js/viewer.js', [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function dates(): JsonResponse
    {
        $this->authorize();

        return $this->json(['dates' => $this->reader->dates()]);
    }

    public function traces(Request $request): JsonResponse
    {
        $this->authorize();

        try {
            $date = $this->queryString($request, 'date');
            $tag = $this->optionalQueryString($request, 'tag');

            return $this->json($this->reader->tracesForDate(
                $date,
                $this->queryInt($request, 'page', 1),
                $this->queryInt($request, 'per_page', 25),
                $tag
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->error('Invalid trace query.', 400);
        } catch (TraceReaderException $exception) {
            return $this->error('TraceLog data is unavailable.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Unable to read TraceLog data.', 500);
        }
    }

    public function trace(Request $request, string $trace_id): JsonResponse
    {
        $this->authorize();

        try {
            $date = $this->queryString($request, 'date');
            $trace = $this->reader->traceForDate($date, $trace_id);

            if ($trace === null) {
                return $this->error('Trace was not found.', 404);
            }

            return $this->json($trace);
        } catch (InvalidArgumentException $exception) {
            return $this->error('Invalid trace query.', 400);
        } catch (TraceReaderException $exception) {
            return $this->error('TraceLog data is unavailable.', 404);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Unable to read TraceLog data.', 500);
        }
    }

    private function authorize(): void
    {
        abort_unless(Gate::allows('viewTraceLog'), 403);
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Invalid query value.');
        }

        return $value;
    }

    private function optionalQueryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null) {
            return null;
        }

        return $this->queryString($request, $key);
    }

    private function queryInt(Request $request, string $key, int $default): int
    {
        $value = $request->query($key);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Invalid query value.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['message' => $message])->setStatusCode($status);
    }
}
