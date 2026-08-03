<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

use App\Models\Task;
use App\Policies\TaskPolicy;
use App\Models\ChecklistSubmission;
use App\Policies\SubmissionPolicy;
use App\Models\Verification;
use App\Policies\VerificationPolicy;
use App\Models\Room;
use App\Policies\RoomPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(ChecklistSubmission::class, SubmissionPolicy::class);
        Gate::policy(Verification::class, VerificationPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);

        // 1. Input Sanitization Global (Trim & Strip Tags)
        $request = request();
        if ($request) {
            
            $sanitize = function ($value, $key) use (&$sanitize) {
                // Jangan bersihkan password
                if (in_array($key, ['password', 'password_confirmation'], true)) {
                    return $value;
                }
                if (is_array($value)) {
                    $cleaned = [];
                    foreach ($value as $k => $v) {
                        $cleaned[$k] = $sanitize($v, $k);
                    }
                    return $cleaned;
                }
                if (is_string($value)) {
                    return strip_tags(trim($value));
                }
                return $value;
            };

            if ($request->isJson()) {
                $json = $request->json()->all();
                $sanitized = [];
                foreach ($json as $k => $v) {
                    $sanitized[$k] = $sanitize($v, $k);
                }
                $request->json()->replace($sanitized);
            } else {
                $input = $request->all();
                $sanitized = [];
                foreach ($input as $k => $v) {
                    $sanitized[$k] = $sanitize($v, $k);
                }
                $request->replace($sanitized);
            }
        }

        // 2. Rate Limiting Login (10 kali per 15 menit per username/email + IP)
        RateLimiter::for('login', function (Request $request) {
            $username = (string) $request->input('email');
            $ip = $request->ip();
            return Limit::perMinutes(15, 10)->by($username . '|' . $ip)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam 15 menit.',
                    'errors' => [
                        'login' => ['Batas percobaan login terlampaui.']
                    ]
                ], 429, $headers);
            });
        });

        // 3. Rate Limiting API Global (120 kali per menit per user token)
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $key = $user ? $user->id : $request->ip();
            return Limit::perMinute(120)->by($key)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batas request terlampaui (maksimal 120 request per menit).',
                    'errors' => [
                        'throttle' => ['Batas request terlampaui.']
                    ]
                ], 429, $headers);
            });
        });
    }
}
