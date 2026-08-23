<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\RuntimeRecoveryIncidentFamily;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RuntimeExceptionCapture
{
    private const int MaximumMessageLength = 2048;

    private const int MaximumStackFrames = 8;

    private const int MaximumPathLength = 512;

    private const int MaximumSymbolLength = 255;

    private bool $capturing = false;

    public function __construct(
        private RuntimeRecoveryIncidentRecorder $recorder,
        private SensitiveDataSanitizer $sanitizer,
        private RuntimeCommandContext $commands,
        private Application $app,
    ) {}

    /**
     * Capture one actionable Laravel exception without allowing ingestion failures to escape.
     */
    public function capture(Throwable $exception): void
    {
        if ($this->capturing) {
            return;
        }

        $this->capturing = true;

        try {
            $request = $this->currentRequest();
            $route = $this->currentRoute($request);

            if (! $this->isActionable($exception)) {
                return;
            }

            $message = $this->normalizeMessage($exception);
            $stack = $this->boundedApplicationStack($exception);
            $scope = $this->trustedScope($route);

            $this->recorder->record(
                family: RuntimeRecoveryIncidentFamily::ApplicationException,
                source: $this->resolveSource($route),
                exceptionClass: $exception::class,
                failureSummary: $this->fingerprintSummary($message, $stack),
                project: $scope['project'],
                task: $scope['task'],
                evidence: [
                    'message' => $message,
                    'stack' => $stack,
                ],
            );
        } catch (Throwable) {
            // Exception reporting must never replace or recursively report the original failure.
        } finally {
            $this->capturing = false;
        }
    }

    /**
     * Determine whether the exception represents actionable server-side runtime failure evidence.
     */
    private function isActionable(Throwable $exception): bool
    {
        if ($exception instanceof ShouldntReport
            || $exception instanceof ValidationException
            || $exception instanceof AuthorizationException
            || $exception instanceof AuthenticationException
            || $exception instanceof TokenMismatchException
            || $exception instanceof ModelNotFoundException) {
            return false;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            if ($status >= 400 && $status < 500) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return the current HTTP request only when Laravel has a request bound in the container.
     */
    private function currentRequest(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        return $this->app->make('request');
    }

    /**
     * Resolve the matched Laravel route without reading arbitrary request input or URL data.
     */
    private function currentRoute(?Request $request): ?Route
    {
        if ($request === null) {
            return null;
        }

        $route = $request->route();

        return $route instanceof Route ? $route : null;
    }

    /**
     * Build a stable route or command source identity that never includes query strings or argv.
     */
    private function resolveSource(?Route $route): string
    {
        if ($route !== null) {
            $routeName = $route->getName();

            if (is_string($routeName) && trim($routeName) !== '') {
                return 'route:'.$this->safeIdentity($routeName);
            }

            return 'route-uri:'.$this->safeIdentity($route->uri());
        }

        $command = $this->commands->current();

        if ($command !== null) {
            return 'command:'.$this->safeIdentity($command);
        }

        return $this->app->runningInConsole()
            ? 'command:unknown'
            : 'http:unmatched';
    }

    /**
     * Resolve only already-bound Project and Task route model instances as trusted durable scope.
     *
     * @return array{project: ?Project, task: ?Task}
     */
    private function trustedScope(?Route $route): array
    {
        if ($route === null) {
            return ['project' => null, 'task' => null];
        }

        $project = null;
        $task = null;

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Project) {
                $project = $parameter;
            }

            if ($parameter instanceof Task) {
                $task = $parameter;
            }
        }

        return ['project' => $project, 'task' => $task];
    }

    /**
     * Sanitize and bound the exception message before it can become fingerprint or incident evidence.
     */
    private function normalizeMessage(Throwable $exception): string
    {
        $message = Str::substr(
            Str::squish($this->normalizeOccurrenceNoise(
                $this->sanitizer->sanitizeText($exception->getMessage()),
            )),
            0,
            self::MaximumMessageLength,
        );

        return $message === '' ? 'No exception message provided.' : $message;
    }

    /**
     * Normalize occurrence-random timestamps and identifiers while preserving stable error codes.
     */
    private function normalizeOccurrenceNoise(string $text): string
    {
        $normalized = preg_replace(
            '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?\b/i',
            '[TIMESTAMP]',
            $text,
        ) ?? $text;

        $normalized = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
            '[UUID]',
            $normalized,
        ) ?? $normalized;

        $normalized = preg_replace(
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/i',
            '[ULID]',
            $normalized,
        ) ?? $normalized;

        return preg_replace(
            '/(?i)\b((?:request|correlation|trace)[_-]?id)\s*(?::|=|\s)\s*[^\s,;]+/',
            '$1=[ID]',
            $normalized,
        ) ?? $normalized;
    }

    /**
     * Return a bounded stack containing only repository-owned frames and no arguments or objects.
     *
     * @return list<array{file: string, line?: int, class?: string, function?: string}>
     */
    private function boundedApplicationStack(Throwable $exception): array
    {
        $frames = [[
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], ...$exception->getTrace()];
        $stack = [];

        foreach ($frames as $frame) {
            $file = isset($frame['file'])
                ? $this->relativeApplicationPath($frame['file'])
                : null;

            if ($file === null) {
                continue;
            }

            $sanitizedFrame = ['file' => $file];

            if (isset($frame['line']) && $frame['line'] > 0) {
                $sanitizedFrame['line'] = $frame['line'];
            }

            if (isset($frame['class'])) {
                $sanitizedFrame['class'] = $this->safeSymbol($frame['class']);
            }

            if (isset($frame['function'])) {
                $sanitizedFrame['function'] = $this->safeSymbol($frame['function']);
            }

            $stack[] = $sanitizedFrame;

            if (count($stack) >= self::MaximumStackFrames) {
                break;
            }
        }

        return $stack;
    }

    /**
     * Convert an absolute stack path to a bounded repository-relative path and exclude vendor data.
     */
    private function relativeApplicationPath(string $file): ?string
    {
        $base = realpath(base_path());
        $resolvedFile = realpath($file);

        if ($base === false || $resolvedFile === false) {
            return null;
        }

        $base = str_replace('\\', '/', $base);
        $resolvedFile = str_replace('\\', '/', $resolvedFile);

        if ($resolvedFile !== $base && ! str_starts_with($resolvedFile, $base.'/')) {
            return null;
        }

        $relative = ltrim(substr($resolvedFile, strlen($base)), '/');

        if ($relative === ''
            || preg_match('#^(?:vendor|storage|node_modules|\.git)(?:/|$)#', $relative) === 1) {
            return null;
        }

        return Str::substr(
            $this->sanitizer->sanitizeText($relative),
            0,
            self::MaximumPathLength,
        );
    }

    /**
     * Build stable fingerprint material from the sanitized message and bounded application location.
     *
     * @param  list<array{file: string, line?: int, class?: string, function?: string}>  $stack
     */
    private function fingerprintSummary(string $message, array $stack): string
    {
        $location = $stack[0] ?? null;

        if ($location === null) {
            return $message;
        }

        return $message.' @ '.$location['file'].':'.($location['line'] ?? 0);
    }

    /**
     * Sanitize and bound a route or command identity before handing it to the recorder.
     */
    private function safeIdentity(string $identity): string
    {
        $identity = Str::substr(
            Str::squish($this->sanitizer->sanitizeText($identity)),
            0,
            240,
        );

        return $identity === '' ? 'unknown' : $identity;
    }

    /**
     * Sanitize and bound class or function symbols retained in stack evidence.
     */
    private function safeSymbol(string $symbol): string
    {
        return Str::substr(
            Str::squish($this->sanitizer->sanitizeText($symbol)),
            0,
            self::MaximumSymbolLength,
        );
    }
}
