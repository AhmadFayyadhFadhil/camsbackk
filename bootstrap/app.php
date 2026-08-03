<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'cs.shift' => \App\Http\Middleware\CsShiftMiddleware::class,
            'check.expiry' => \App\Http\Middleware\CheckTokenExpiry::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('cams:generate-tasks')->dailyAt('00:01')->timezone('Asia/Jakarta');
        $schedule->command('cams:check-overdue')->everyFifteenMinutes();
        $schedule->command('cams:send-reminders')->everyFifteenMinutes();
        $schedule->command('cams:check-escalations')->everyThirtyMinutes();
        $schedule->command('cams:check-finding-deadline')->everyThirtyMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tangani QueryException untuk mencegah error serialisasi JSON karena data binary pada query SQL/bindings
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $errorData = [
                    'success' => false,
                    'message' => 'Terjadi kesalahan pada database.',
                ];

                if (config('app.debug')) {
                    $sql = $e->getSql();
                    if (!mb_check_encoding($sql, 'UTF-8')) {
                        $sql = preg_replace('/[\x7F-\xFF]/', '?', $sql);
                    }
                    $message = $e->getMessage();
                    if (!mb_check_encoding($message, 'UTF-8')) {
                        $message = preg_replace('/[\x7F-\xFF]/', '?', $message);
                    }

                    $errorData['error'] = $message;
                    $errorData['sql'] = $sql;
                }

                return response()->json($errorData, 500);
            }
        });
    })->create();
