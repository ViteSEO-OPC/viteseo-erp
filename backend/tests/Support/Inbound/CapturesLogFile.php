<?php

declare(strict_types=1);

namespace Tests\Support\Inbound;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * Runs a callable with logging pointed at a real file and returns what was written.
 *
 * A REAL FILE, not a spy, because the question these tests ask is what the log ENDS UP
 * CONTAINING — including whatever the formatter adds around the context. A spy proves only what
 * the application asked to log, which is the easier half.
 */
trait CapturesLogFile
{
    protected function captureLog(callable $work): string
    {
        $path = storage_path('logs/capture-'.uniqid().'.log');

        config([
            'logging.channels.capture' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'],
            'logging.default' => 'capture',
        ]);

        // The Log facade caches its manager and the manager caches resolved channels; without both
        // of these the work would keep writing to whichever channel was resolved first.
        Facade::clearResolvedInstance('log');
        $this->app->forgetInstance('log');

        try {
            $work();
        } finally {
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            Log::forgetChannel('capture');
            @unlink($path);
        }

        return $contents;
    }

    /** Only the lines a given log-event prefix wrote. */
    protected function linesFor(string $log, string $prefix): string
    {
        return implode("\n", array_filter(
            explode("\n", $log),
            fn (string $line): bool => str_contains($line, $prefix),
        ));
    }
}
