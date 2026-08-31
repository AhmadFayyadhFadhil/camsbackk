<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

use App\Http\Resources\NotificationResource;

class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * Stream push notification realtime menggunakan Server-Sent Events (SSE).
     */
    public function stream(Request $request)
    {
        $userId = $request->user()->id;

        $response = new StreamedResponse(function () use ($userId) {
            // Naikkan batas waktu eksekusi PHP menjadi tidak terbatas
            set_time_limit(0);

            // Bersihkan buffering output di level web server (misal Nginx)
            echo ":" . str_repeat(" ", 2048) . "\n";
            echo "event: connected\n";
            echo "data: " . json_encode(['message' => 'Koneksi realtime SSE aktif.']) . "\n\n";
            ob_flush();
            flush();

            try {
                $redis = Redis::connection();
                $pubsub = $redis->pubSubLoop();
                $pubsub->subscribe("sse:user:{$userId}");

                foreach ($pubsub as $message) {
                    if ($message->kind === 'message') {
                        echo "event: notification\n";
                        echo "data: {$message->payload}\n\n";
                        ob_flush();
                        flush();
                    }

                    // Jika client memutuskan koneksi, hentikan subskripsi Redis
                    if (connection_aborted()) {
                        $pubsub->unsubscribe();
                        break;
                    }
                }
            } catch (\Exception $e) {
                logger()->error("SSE Redis Pub/Sub Gagal, masuk ke fallback heartbeat: " . $e->getMessage());

                // Fallback heartbeat agar koneksi client tetap hidup jika Redis tidak terinstal
                while (true) {
                    if (connection_aborted()) {
                        break;
                    }
                    echo "event: heartbeat\n";
                    echo "data: " . json_encode(['timestamp' => now()->toDateTimeString()]) . "\n\n";
                    ob_flush();
                    flush();
                    sleep(10);
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, private');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Tampilkan riwayat notifikasi user (paginated).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Notification::where('user_id', $user->id)->orderBy('created_at', 'desc');

        $unreadCount = Notification::where('user_id', $user->id)->where('is_read', false)->count();
        $readCount = Notification::where('user_id', $user->id)->where('is_read', true)->count();
        $totalCount = $unreadCount + $readCount;

        if ($request->has('is_read')) {
            $query->where('is_read', filter_var($request->get('is_read'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = $request->get('per_page', 20);
        $notifications = $query->paginate($perPage);

        $resourceData = NotificationResource::collection($notifications)->response()->getData(true);

        return response()->json([
            'success' => true,
            'message' => 'Daftar notifikasi berhasil diambil.',
            'data' => $resourceData['data'] ?? [],
            'links' => $resourceData['links'] ?? null,
            'meta' => array_merge(
                $resourceData['meta'] ?? [],
                [
                    'unread_count' => $unreadCount,
                    'read_count' => $readCount,
                    'total_count' => $totalCount,
                ]
            )
        ]);
    }

    /**
     * Tandai notifikasi tertentu sebagai terbaca.
     */
    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)->findOrFail($id);

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return $this->success(
            new NotificationResource($notification),
            'Notifikasi berhasil ditandai sebagai terbaca.'
        );
    }

    /**
     * Tandai semua notifikasi user sebagai terbaca.
     */
    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->success(null, 'Semua notifikasi berhasil ditandai sebagai terbaca.');
    }

    /**
     * Hapus satu notifikasi.
     */
    public function destroy(Request $request, $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)->findOrFail($id);
        $notification->delete();

        return $this->success(null, 'Notifikasi berhasil dihapus.');
    }

    /**
     * Hapus semua notifikasi milik user.
     */
    public function destroyAll(Request $request)
    {
        Notification::where('user_id', $request->user()->id)->delete();

        return $this->success(null, 'Semua notifikasi berhasil dihapus.');
    }
}
