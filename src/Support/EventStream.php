<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\AlfakitAi\Exceptions\StreamCancelled;
use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A `text/event-stream` response: `delta` events with the text as it arrives, then one
 * `done` (or `error`) event. Proxies are asked not to buffer it; when the browser goes away
 * the next write throws `StreamCancelled`, which stops generation.
 */
final class EventStream
{
    /**
     * @param  Closure(Closure(string, array<string, mixed>): void): void  $run  receives `$emit(event, data)`
     */
    public static function response(Closure $run): StreamedResponse
    {
        // The body is sent after the controller returned: keep the request's panel and tenant.
        $panel = alfakit()->getCurrentPanel();
        $tenant = alfakit()->getTenant();

        return new StreamedResponse(static function () use ($run, $panel, $tenant): void {
            if ($panel !== null && alfakit()->getCurrentPanel() !== $panel) {
                alfakit()->setCurrentPanel($panel);
                alfakit()->setTenant($tenant);
            }

            // Keep running when the browser leaves, so the next write notices and stops cleanly.
            ignore_user_abort(true);

            $emit = static function (string $event, array $data): void {
                if (connection_aborted() === 1) {
                    throw new StreamCancelled;
                }

                echo self::format($event, $data);

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            try {
                $run($emit);
            } catch (StreamCancelled) {
                // The browser stopped listening: nothing left to send.
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function format(string $event, array $data): string
    {
        return "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
    }
}
