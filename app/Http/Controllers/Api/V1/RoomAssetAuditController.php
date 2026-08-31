<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomAssetAuditResource;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\RoomAssetAudit;
use App\Models\RoomAssetAuditItem;
use App\Models\Finding;
use App\Models\FindingCategory;
use App\Models\User;
use App\Enums\RoleEnum;
use App\Enums\PriorityEnum;
use App\Enums\FindingStatusEnum;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RoomAssetAuditController extends Controller
{
    use ApiResponse;

    /**
     * GET /room-asset-audits
     * Daftar riwayat audit fisik aset ruangan (paginated)
     */
    public function index(Request $request)
    {
        $query = RoomAssetAudit::query()->with([
            'room.building',
            'auditor',
            'verifier',
            'items.asset',
        ]);

        if ($request->has('room_id') && $request->room_id) {
            $query->where('room_id', $request->room_id);
        }

        if ($request->has('building_id') && $request->building_id) {
            $buildingId = $request->building_id;
            $query->whereHas('room', function ($q) use ($buildingId) {
                $q->where('building_id', $buildingId);
            });
        }

        if ($request->has('periode') && $request->periode) {
            $query->where('periode', $request->periode);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('has_discrepancy') && $request->has_discrepancy !== '') {
            $query->where('has_discrepancy', filter_var($request->has_discrepancy, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('periode', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('room', function ($rq) use ($search) {
                      $rq->where('nama_ruangan', 'like', "%{$search}%")
                         ->orWhere('kode_ruangan', 'like', "%{$search}%");
                  })
                  ->orWhereHas('auditor', function ($uq) use ($search) {
                      $uq->where('full_name', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = $request->get('per_page', 15);
        $audits = $query->orderBy('audit_date', 'desc')->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->paginated(
            RoomAssetAuditResource::collection($audits),
            'Daftar audit aset ruangan berhasil diambil.'
        );
    }

    /**
     * GET /room-asset-audits/{id}
     */
    public function show($id)
    {
        $audit = RoomAssetAudit::with([
            'room.building',
            'auditor',
            'verifier',
            'items.asset',
        ])->findOrFail($id);

        return $this->success(
            new RoomAssetAuditResource($audit),
            'Detail audit aset ruangan berhasil diambil.'
        );
    }

    /**
     * POST /room-asset-audits
     * Petugas (CS / Supervisor / Admin) submit laporan audit fisik aset ruangan
     */
    public function store(Request $request)
    {
        $request->validate([
            'room_id' => ['required', 'uuid', 'exists:rooms,id'],
            'periode' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.room_asset_id' => ['required', 'uuid', 'exists:room_assets,id'],
            'items.*.jumlah_actual' => ['required', 'integer', 'min:0'],
            'items.*.kondisi' => ['required', 'string', 'in:good,damaged,missing'],
            'items.*.catatan' => ['nullable', 'string', 'max:500'],
        ]);

        $room = Room::with('assets')->findOrFail($request->room_id);
        $user = $request->user();
        $periode = $request->filled('periode') ? trim($request->periode) : Carbon::now()->format('Y-m');

        $audit = DB::transaction(function () use ($request, $room, $user, $periode) {
            $totalExpected = 0;
            $totalActual = 0;
            $hasDiscrepancy = false;
            $itemsToCreate = [];

            // Index room assets by id
            $assetsMap = $room->assets->keyBy('id');

            foreach ($request->items as $idx => $itemData) {
                $assetId = $itemData['room_asset_id'];
                $asset = $assetsMap->get($assetId);

                $expectedQty = $asset ? (int)$asset->jumlah : 1;
                $actualQty = (int)$itemData['jumlah_actual'];
                $kondisi = $itemData['kondisi'] ?? 'good';
                $catatan = $itemData['catatan'] ?? null;

                $totalExpected += $expectedQty;
                $totalActual += $actualQty;

                if ($actualQty !== $expectedQty || $kondisi !== 'good') {
                    $hasDiscrepancy = true;
                }

                // Handle file upload for this item if provided
                $fotoPath = null;
                $fileKey = "items.{$idx}.foto_bukti";
                $directKey = "foto_bukti_{$assetId}";
                if ($request->hasFile($fileKey)) {
                    $fotoPath = $request->file($fileKey)->store('asset_audits', 'public');
                } elseif ($request->hasFile($directKey)) {
                    $fotoPath = $request->file($directKey)->store('asset_audits', 'public');
                }

                $itemsToCreate[] = [
                    'id' => (string) Str::uuid(),
                    'room_asset_id' => $assetId,
                    'nama_aset_snapshot' => $asset?->nama_aset,
                    'kode_aset_snapshot' => $asset?->kode_aset,
                    'jumlah_expected' => $expectedQty,
                    'jumlah_actual' => $actualQty,
                    'kondisi' => $kondisi,
                    'foto_bukti' => $fotoPath,
                    'catatan' => $catatan,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $auditRecord = RoomAssetAudit::create([
                'id' => (string) Str::uuid(),
                'room_id' => $room->id,
                'auditor_id' => $user->id,
                'periode' => $periode,
                'audit_date' => Carbon::today(),
                'status' => 'submitted',
                'total_expected' => $totalExpected,
                'total_actual' => $totalActual,
                'has_discrepancy' => $hasDiscrepancy,
                'notes' => $request->notes,
            ]);

            foreach ($itemsToCreate as $item) {
                $item['room_asset_audit_id'] = $auditRecord->id;
                RoomAssetAuditItem::create($item);
            }

            // Update room's last audit time and calculate next due date
            $intervalDays = (int)($room->asset_audit_interval_days ?: 60);
            $room->update([
                'last_asset_audit_at' => now(),
                'next_asset_audit_due' => Carbon::today()->addDays($intervalDays),
            ]);

            AuditLogService::log('SUBMIT_ASSET_AUDIT', 'room_asset_audits', $auditRecord->id, null, $auditRecord->toArray());

            return $auditRecord;
        });

        // Notify Supervisors
        $supervisors = User::whereHas('roles', function ($q) {
            $q->where('name', RoleEnum::SUPERVISOR->value);
        })->where('is_active', true)->get();

        $discrepancyText = $audit->has_discrepancy ? ' (Ditemukan Selisih / Kerusakan)' : ' (Lengkap & Sesuai)';
        foreach ($supervisors as $supervisor) {
            NotificationService::send(
                $supervisor->id,
                'asset_audit_submitted',
                "Hasil Audit Aset: {$room->nama_ruangan}{$discrepancyText}",
                "Petugas {$user->full_name} telah mengirimkan laporan audit fisik aset di ruang {$room->nama_ruangan} untuk periode {$periode}.",
                [
                    'audit_id' => $audit->id,
                    'room_id' => $room->id,
                    'room_name' => $room->nama_ruangan,
                    'has_discrepancy' => $audit->has_discrepancy,
                ],
                'both'
            );
        }

        $audit->load(['room.building', 'auditor', 'items.asset']);

        return $this->success(
            new RoomAssetAuditResource($audit),
            'Laporan audit aset ruangan berhasil dikirim.',
            201
        );
    }

    /**
     * POST /room-asset-audits/{id}/verify
     * Supervisor / Admin menyetujui hasil audit, dengan opsi 1-klik buat Finding dan/atau sinkronisasi master paten
     */
    public function verify(Request $request, $id)
    {
        $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
            'verification_notes' => ['nullable', 'string', 'max:1000'],
            'auto_create_findings' => ['nullable', 'boolean'],
            'sync_master_baseline' => ['nullable', 'boolean'],
            'next_due_date' => ['nullable', 'date'],
        ]);

        $audit = RoomAssetAudit::with(['room.pic', 'items.asset', 'auditor'])->findOrFail($id);
        $user = $request->user();
        $oldData = $audit->toArray();

        $audit = DB::transaction(function () use ($request, $audit, $user) {
            $status = $request->status;

            $audit->update([
                'status' => $status,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'verification_notes' => $request->verification_notes,
            ]);

            // Jika status disetujui
            if ($status === 'approved') {
                // 1. Opsi Sinkronisasi Master Paten
                if ($request->boolean('sync_master_baseline')) {
                    foreach ($audit->items as $auditItem) {
                        if ($auditItem->asset) {
                            $updateData = [];
                            if ($auditItem->jumlah_actual !== $auditItem->jumlah_expected) {
                                $updateData['jumlah'] = $auditItem->jumlah_actual;
                            }
                            if ($auditItem->kondisi === 'damaged') {
                                $updateData['status'] = 'damaged';
                            }
                            if (!empty($updateData)) {
                                $auditItem->asset->update($updateData);
                            }
                        }
                    }
                }

                // 2. Opsi Auto Create Findings untuk item rusak/hilang
                if ($request->boolean('auto_create_findings')) {
                    $category = FindingCategory::firstOrCreate(
                        ['kode_kategori' => 'ASSET_AUDIT'],
                        ['nama_kategori' => 'Kerusakan / Ketidaksesuaian Aset Ruangan']
                    );

                    foreach ($audit->items as $auditItem) {
                        if ($auditItem->kondisi !== 'good' || $auditItem->jumlah_actual < $auditItem->jumlah_expected) {
                            $selisih = $auditItem->jumlah_expected - $auditItem->jumlah_actual;
                            $deskripsi = "Temuan dari Hasil Audit Fisik Aset Ruangan ({$audit->periode}): Aset '{$auditItem->nama_aset_snapshot}' ({$auditItem->kode_aset_snapshot}). ";
                            if ($auditItem->kondisi === 'damaged') {
                                $deskripsi .= "Kondisi: Rusak. ";
                            } elseif ($auditItem->kondisi === 'missing') {
                                $deskripsi .= "Kondisi: Hilang / Tidak Ditemukan. ";
                            }
                            if ($selisih > 0) {
                                $deskripsi .= "Jumlah paten: {$auditItem->jumlah_expected}, riil fisik: {$auditItem->jumlah_actual} (Kurang {$selisih} unit). ";
                            }
                            if ($auditItem->catatan) {
                                $deskripsi .= "Catatan petugas: {$auditItem->catatan}.";
                            }

                            Finding::create([
                                'id' => (string) Str::uuid(),
                                'room_id' => $audit->room_id,
                                'room_asset_id' => $auditItem->room_asset_id,
                                'finding_category_id' => $category->id,
                                'reported_by' => $audit->auditor_id ?: $user->id,
                                'deskripsi' => $deskripsi,
                                'prioritas' => PriorityEnum::HIGH,
                                'status' => FindingStatusEnum::OPEN,
                                'deadline_perbaikan' => Carbon::today()->addDays(3),
                            ]);
                        }
                    }
                }

                // 3. Update room schedule if custom next due date provided
                if ($request->filled('next_due_date')) {
                    $audit->room->update([
                        'next_asset_audit_due' => Carbon::parse($request->next_due_date),
                    ]);
                }
            }

            AuditLogService::log('VERIFY_ASSET_AUDIT', 'room_asset_audits', $audit->id, $oldData, $audit->toArray());

            return $audit;
        });

        // Notify Auditor (CS)
        if ($audit->auditor_id) {
            $statusLabel = $request->status === 'approved' ? 'Disetujui' : 'Ditolak / Perlu Ditinjau';
            NotificationService::send(
                $audit->auditor_id,
                'asset_audit_verified',
                "Hasil Verifikasi Audit Aset: {$audit->room->nama_ruangan} ({$statusLabel})",
                "Laporan audit aset periode {$audit->periode} telah diverifikasi oleh {$user->full_name}. Status: {$statusLabel}." . ($request->verification_notes ? " Catatan: {$request->verification_notes}" : ""),
                [
                    'audit_id' => $audit->id,
                    'status' => $request->status,
                ],
                'both'
            );
        }

        $audit->load(['room.building', 'auditor', 'verifier', 'items.asset']);

        return $this->success(
            new RoomAssetAuditResource($audit),
            "Hasil audit aset berhasil di-{$request->status}."
        );
    }

    /**
     * PUT /rooms/{id}/asset-audit-schedule
     * Supervisor / Admin mengatur siklus dan jadwal audit ruangan
     */
    public function updateSchedule(Request $request, $roomId)
    {
        $room = Room::with('building')->findOrFail($roomId);

        $request->validate([
            'asset_audit_interval' => ['required', 'string', 'in:biweekly,monthly,bimonthly,quarterly,custom'],
            'asset_audit_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'next_asset_audit_due' => ['nullable', 'date'],
        ]);

        $interval = $request->asset_audit_interval;
        $days = match ($interval) {
            'biweekly' => 14,
            'monthly' => 30,
            'bimonthly' => 60,
            'quarterly' => 90,
            'custom' => (int)($request->asset_audit_interval_days ?: 60),
            default => 60,
        };

        $nextDue = null;
        if ($request->filled('next_asset_audit_due')) {
            $nextDue = Carbon::parse($request->next_asset_audit_due);
        } else {
            $lastAudit = $room->last_asset_audit_at ? Carbon::parse($room->last_asset_audit_at) : Carbon::today();
            $nextDue = $lastAudit->copy()->addDays($days);
        }

        $oldData = $room->toArray();

        $room->update([
            'asset_audit_interval' => $interval,
            'asset_audit_interval_days' => $days,
            'next_asset_audit_due' => $nextDue,
        ]);

        AuditLogService::log('UPDATE_ASSET_AUDIT_SCHEDULE', 'rooms', $room->id, $oldData, $room->toArray());

        return $this->success(
            new RoomResource($room),
            'Jadwal dan interval audit aset ruangan berhasil diperbarui.'
        );
    }

    /**
     * GET /room-asset-audits/schedule-summary
     * Ringkasan status jadwal audit seluruh ruangan
     */
    public function scheduleSummary(Request $request)
    {
        $today = Carbon::today();
        $soon = Carbon::today()->addDays(7);

        $rooms = Room::with(['building', 'assets'])->where('is_active', true)->get();

        $summary = [
            'total_rooms' => $rooms->count(),
            'up_to_date' => 0,
            'due_soon' => 0,
            'overdue' => 0,
            'never_audited' => 0,
        ];

        $roomsList = $rooms->map(function ($r) use ($today, $soon, &$summary) {
            $nextDue = $r->next_asset_audit_due ? Carbon::parse($r->next_asset_audit_due) : null;
            $lastAudit = $r->last_asset_audit_at ? Carbon::parse($r->last_asset_audit_at) : null;

            $status = 'up_to_date';
            if (!$lastAudit && !$nextDue) {
                $status = 'never_audited';
                $summary['never_audited']++;
            } elseif ($nextDue && $nextDue->lt($today)) {
                $status = 'overdue';
                $summary['overdue']++;
            } elseif ($nextDue && $nextDue->lte($soon)) {
                $status = 'due_soon';
                $summary['due_soon']++;
            } else {
                $summary['up_to_date']++;
            }

            return [
                'id' => $r->id,
                'nama_ruangan' => $r->nama_ruangan,
                'kode_ruangan' => $r->kode_ruangan,
                'nama_gedung' => $r->building?->nama_gedung,
                'total_assets' => $r->assets->count(),
                'total_units' => $r->assets->sum('jumlah'),
                'asset_audit_interval' => $r->asset_audit_interval ?? 'bimonthly',
                'asset_audit_interval_days' => $r->asset_audit_interval_days ?? 60,
                'last_asset_audit_at' => $lastAudit?->toIso8601String(),
                'next_asset_audit_due' => $nextDue?->toDateString(),
                'audit_status' => $status,
            ];
        });

        return $this->success([
            'summary' => $summary,
            'rooms' => $roomsList,
        ], 'Ringkasan jadwal audit aset berhasil diambil.');
    }

    /**
     * GET /room-asset-audits/{auditId}/items/{itemId}/foto
     * Stream foto bukti temuan item audit secara aman
     */
    public function streamFoto($auditId, $itemId)
    {
        $item = RoomAssetAuditItem::where('room_asset_audit_id', $auditId)->findOrFail($itemId);

        if (!$item->foto_bukti || !Storage::disk('public')->exists($item->foto_bukti)) {
            return response()->json(['message' => 'Foto bukti tidak ditemukan.'], 404);
        }

        $filePath = Storage::disk('public')->path($item->foto_bukti);
        $mimeType = mime_content_type($filePath) ?: 'image/jpeg';

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}

