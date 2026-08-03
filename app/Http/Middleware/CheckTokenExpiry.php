<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenExpiry
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token && $token->expires_at && $token->expires_at->isPast()) {
                // Delete token from database
                $token->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Token telah kedaluwarsa. Silakan login kembali.',
                    'errors' => [
                        'auth' => ['Token expired.']
                    ]
                ], 401);
            }
        }

        return $next($request);
    }
}
