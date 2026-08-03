<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\RoleEnum;
use App\Helpers\ShiftValidatorHelper;

class CsShiftMiddleware
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

        if ($user && $user->hasRole(RoleEnum::CS)) {
            // 1. Ambil cs_assignment aktif hari ini
            $assignment = $user->activeAssignment;

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak: Anda tidak memiliki penugasan hari ini.'
                ], 403);
            }

            // 2. Deteksi shift aktif saat ini berdasarkan jam
            $shift = ShiftValidatorHelper::getCurrentShift();
            if (!$shift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak: di luar jam operasional shift kerja apa pun.'
                ], 403);
            }
        }

        return $next($request);
    }
}
