<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomAssetAuditResource;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\RoomResource;
use App\Models\Building;
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
     * Daftar riwayat audit fisik aset (paginated)
     */
    public function index(Request $request)
    {
        $query = RoomAssetAudit::query()->with([
            'building',
            'room.building',
            'auditor',
            'verifier',
            'items.asset',
            'items.room',
        ]);

        if ($request->has('building_id') && $request->building_id) {
            $buildingId = $request->building_id;
            $query->where(function ($q) use ($buildingId) {
                $q->where('building_id', $buildingId)
                  ->orWhereHas('room', function ($rq) use ($buildingId) {
                      $rq->where('building_id', $buildingId);
                  });
            });
        }

        if ($request->has('room_id') && $request->room_id) {
            $query->where('room_id', $request->room_id);
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
                  ->orWhereHas('building', function ($bq) use ($search) {
                      $bq->where('nama_gedung', 'like', "%{$search}%")
                         ->orWhere('kode_gedung', 'like', "%{$search}%");
                  })
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
            'Daftar audit aset berhasil diambil.'
        );
    }

    /**
     * GET /room-asset-audits/{id}
     */
    public function show($id)
    {
        $audit = RoomAssetAudit::with([
            'building',
            'room.building',
            'auditor',
            'verifier',
            'items.asset',
            'items.room',
        ])->findOrFail($id);

        return $this->success(
            new RoomAssetAuditResource($audit),
            'Detail audit aset berhasil diambil.'
        );
    }

    /**
     * POST /room-asset-audits
     * Petugas submit laporan audit fisik aset (Per Gedung atau Per Ruangan)
     */
    public function store(Request $request)
    {
        $request->validate([
            'building_id' => ['nullable', 'uuid', 'exists:buildings,id'],
            'room_id' => ['nullable', 'uuid', 'exists:rooms,id'],
            'periode' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.room_asset_id' => ['required', 'uuid', 'exists:room_assets,id'],
            'items.*.room_id' => ['nullable', 'uuid', 'exists:rooms,id'],
            'items.*.jumlah_actual' => ['required', 'integer', 'min:0'],
            'items.*.kondisi' => ['required', 'string', 'in:good,damaged,missing'],
            'items.*.catatan' => ['nullable', 'string', 'max:500'],
        ]);

        if (!$request->filled('building_id') && !$request->filled('room_id')) {
            return $this->error('Gedung atau Ruangan yang diaudit harus ditentukan.', [], 422);
        }

        $user = $request->user();
        $periode = $request->filled('periode') ? trim($request->periode) : Carbon::now()->format('Y-m');
        $buildingId = $request->building_id;
        $roomId = $request->room_id;

        $building = $buildingId ? Building::find($buildingId) : null;
        $room = $roomId ? Room::with('building')->find($roomId) : null;
        if (!$building && $room) {
            $building = $room->building;
            $buildingId = $building?->id;
        }

        $audit = DB::transaction(function () use ($request, $building, $room, $user, $periode, $buildingId, $roomId) {
            $totalExpected = 0;
            $totalActual = 0;
            $hasDiscrepancy = false;
            $itemsToCreate = [];

            // Load assets involved
            $assetIds = collect($request->items)->pluck('room_asset_id')->filter()->unique();
            $assetsMap = RoomAsset::with('room')->whereIn('id', $assetIds)->get()->keyBy('id');

            foreach ($request->items as $idx => $itemData) {
                $assetId = $itemData['room_asset_id'];
                $asset = $assetsMap->get($assetId);
                $itemRoomId = $itemData['room_id'] ?? $asset?->room_id ?? $roomId;
                $itemRoomName = $asset?->room?->nama_ruangan ?: ($room?->nama_ruangan);

                $expectedQty = $asset ? (int)$asset->jumlah : 1;
                $actualQty = (int)$itemData['jumlah_actual'];
                $kondisi = $itemData['kondisi'] ?? 'good';
                $catatan = $itemData['catatan'] ?? null;

                $totalExpected += $expectedQty;
                $totalActual += $actualQty;

                if ($actualQty !== $expectedQty || $kondisi !== 'good') {
                    $hasDiscrepancy = true;
                }

                // Handle photo upload
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
                    'room_id' => $itemRoomId,
                    'nama_ruangan_snapshot' => $itemRoomName,
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
                'building_id' => $buildingId,
                'room_id' => $roomId,
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

            // Update building last audit time and next due date
            if ($building) {
                $intervalDays = (int)($building->asset_audit_interval_days ?: 60);
                $building->update([
                    'last_asset_audit_at' => now(),
                    'next_asset_audit_due' => Carbon::today()->addDays($intervalDays),
                ]);

                // Also update child rooms
                Room::where('building_id', $building->id)->update([
                    'last_asset_audit_at' => now(),
                    'next_asset_audit_due' => Carbon::today()->addDays($intervalDays),
                ]);
            } elseif ($room) {
                $intervalDays = (int)($room->asset_audit_interval_days ?: 60);
                $room->update([
                    'last_asset_audit_at' => now(),
                    'next_asset_audit_due' => Carbon::today()->addDays($intervalDays),
                ]);
            }

            AuditLogService::log('SUBMIT_ASSET_AUDIT', 'room_asset_audits', $auditRecord->id, null, $auditRecord->toArray());

            return $auditRecord;
        });

        // Notify Supervisors
        $supervisors = User::whereHas('roles', function ($q) {
            $q->where('name', RoleEnum::SUPERVISOR->value);
        })->where('is_active', true)->get();

        $targetName = $building ? "Gedung {$building->nama_gedung}" : "Ruang {$room->nama_ruangan}";
        $discrepancyText = $audit->has_discrepancy ? ' (Ditemukan Selisih / Kerusakan)' : ' (Lengkap & Sesuai)';

        foreach ($supervisors as $supervisor) {
            NotificationService::send(
                $supervisor->id,
                'asset_audit_submitted',
                "Hasil Audit Aset: {$targetName}{$discrepancyText}",
                "Petugas {$user->full_name} telah mengirimkan laporan audit fisik aset {$targetName} untuk periode {$periode}.",
                [
                    'audit_id' => $audit->id,
                    'building_id' => $buildingId,
                    'room_id' => $roomId,
                    'target_name' => $targetName,
                    'has_discrepancy' => $audit->has_discrepancy,
                ],
                'both'
            );
        }

        $audit->load(['building', 'room.building', 'auditor', 'items.asset', 'items.room']);

        return $this->success(
            new RoomAssetAuditResource($audit),
            'Laporan audit aset berhasil dikirim.',
            201
        );
    }

    /**
     * POST /room-asset-audits/{id}/verify
     * Supervisor / Admin menyetujui hasil audit
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

        $audit = RoomAssetAudit::with(['building', 'room.building', 'items.asset', 'items.room', 'auditor'])->findOrFail($id);
        $user = $request->user();
        $oldData = $audit->toArray();

        $audit = DB::transaction(function () use ($request, $audit, $user, $oldData) {
            $status = $request->status;

            $audit->update([
                'status' => $status,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'verification_notes' => $request->verification_notes,
            ]);

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
                            $roomName = $auditItem->nama_ruangan_snapshot ?: ($auditItem->room?->nama_ruangan ?: '-');
                            $deskripsi = "Temuan dari Hasil Audit Fisik Aset ({$audit->periode}) di Ruang '{$roomName}': Aset '{$auditItem->nama_aset_snapshot}' ({$auditItem->kode_aset_snapshot}). ";
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

                            $findingRoomId = $auditItem->room_id ?: ($audit->room_id ?: Room::where('building_id', $audit->building_id)->value('id'));

                            if ($findingRoomId) {
                                Finding::create([
                                    'id' => (string) Str::uuid(),
                                    'room_id' => $findingRoomId,
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
                }

                // 3. Update building / room schedule if custom next due date provided
                if ($request->filled('next_due_date')) {
                    $nextDueDate = Carbon::parse($request->next_due_date);
                    if ($audit->building) {
                        $audit->building->update(['next_asset_audit_due' => $nextDueDate]);
                        Room::where('building_id', $audit->building_id)->update(['next_asset_audit_due' => $nextDueDate]);
                    } elseif ($audit->room) {
                        $audit->room->update(['next_asset_audit_due' => $nextDueDate]);
                    }
                }
            }

            AuditLogService::log('VERIFY_ASSET_AUDIT', 'room_asset_audits', $audit->id, $oldData, $audit->toArray());

            return $audit;
        });

        // Notify Auditor
        if ($audit->auditor_id) {
            $statusLabel = $request->status === 'approved' ? 'Disetujui' : 'Ditolak / Perlu Ditinjau';
            $targetName = $audit->building ? "Gedung {$audit->building->nama_gedung}" : "Ruang " . ($audit->room?->nama_ruangan ?: '');
            NotificationService::send(
                $audit->auditor_id,
                'asset_audit_verified',
                "Hasil Verifikasi Audit Aset: {$targetName} ({$statusLabel})",
                "Laporan audit aset periode {$audit->periode} telah diverifikasi oleh {$user->full_name}. Status: {$statusLabel}." . ($request->verification_notes ? " Catatan: {$request->verification_notes}" : ""),
                [
                    'audit_id' => $audit->id,
                    'status' => $request->status,
                ],
                'both'
            );
        }

        $audit->load(['building', 'room.building', 'auditor', 'verifier', 'items.asset', 'items.room']);

        return $this->success(
            new RoomAssetAuditResource($audit),
            "Hasil audit aset berhasil di-{$request->status}."
        );
    }

    /**
     * PUT /buildings/{id}/asset-audit-schedule
     * Supervisor / Admin mengatur siklus dan jadwal audit per gedung
     */
    public function updateBuildingSchedule(Request $request, $buildingId)
    {
        $building = Building::with('rooms')->findOrFail($buildingId);

        $request->validate([
            'asset_audit_interval' => ['required', 'string', 'in:biweekly,monthly,bimonthly,quarterly,semi_annually,custom'],
            'asset_audit_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'next_asset_audit_due' => ['nullable', 'date'],
        ]);

        $interval = $request->asset_audit_interval;
        $days = match ($interval) {
            'biweekly' => 14,
            'monthly' => 30,
            'bimonthly' => 60,
            'quarterly' => 90,
            'semi_annually' => 180,
            'custom' => (int)($request->asset_audit_interval_days ?: 60),
            default => 60,
        };

        $nextDue = null;
        if ($request->filled('next_asset_audit_due')) {
            $nextDue = Carbon::parse($request->next_asset_audit_due);
        } else {
            $lastAudit = $building->last_asset_audit_at ? Carbon::parse($building->last_asset_audit_at) : Carbon::today();
            $nextDue = $lastAudit->copy()->addDays($days);
        }

        $oldData = $building->toArray();

        $building->update([
            'asset_audit_interval' => $interval,
            'asset_audit_interval_days' => $days,
            'next_asset_audit_due' => $nextDue,
        ]);

        // Sync to all rooms in this building
        Room::where('building_id', $building->id)->update([
            'asset_audit_interval' => $interval,
            'asset_audit_interval_days' => $days,
            'next_asset_audit_due' => $nextDue,
        ]);

        AuditLogService::log('UPDATE_BUILDING_ASSET_AUDIT_SCHEDULE', 'buildings', $building->id, $oldData, $building->toArray());

        return $this->success(
            new BuildingResource($building),
            "Jadwal dan interval audit aset untuk Gedung {$building->nama_gedung} berhasil diperbarui."
        );
    }

    /**
     * PUT /rooms/{id}/asset-audit-schedule
     * (Backward Compatibility) Mengatur jadwal audit ruangan
     */
    public function updateSchedule(Request $request, $roomId)
    {
        $room = Room::with('building')->findOrFail($roomId);

        $request->validate([
            'asset_audit_interval' => ['required', 'string', 'in:biweekly,monthly,bimonthly,quarterly,semi_annually,custom'],
            'asset_audit_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'next_asset_audit_due' => ['nullable', 'date'],
        ]);

        $interval = $request->asset_audit_interval;
        $days = match ($interval) {
            'biweekly' => 14,
            'monthly' => 30,
            'bimonthly' => 60,
            'quarterly' => 90,
            'semi_annually' => 180,
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
     * GET /building-asset-audits/summary
     * Ringkasan status jadwal audit berbasis per GEDUNG dengan daftar ruangan & asetnya
     */
    public function buildingSummary(Request $request)
    {
        $today = Carbon::today();
        $soon = Carbon::today()->addDays(7);

        $buildings = Building::with(['rooms' => function ($q) {
            $q->where('is_active', true)->with('assets');
        }])->where('is_active', true)->get();

        $summary = [
            'total_buildings' => $buildings->count(),
            'up_to_date' => 0,
            'due_soon' => 0,
            'overdue' => 0,
            'never_audited' => 0,
        ];

        $buildingsList = $buildings->map(function ($b) use ($today, $soon, &$summary) {
            $nextDue = $b->next_asset_audit_due ? Carbon::parse($b->next_asset_audit_due) : null;
            $lastAudit = $b->last_asset_audit_at ? Carbon::parse($b->last_asset_audit_at) : null;

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

            $totalAssets = 0;
            $totalUnits = 0;
            $roomsData = [];

            foreach ($b->rooms as $room) {
                $rAssetsCount = $room->assets->count();
                $rUnitsCount = $room->assets->sum('jumlah');
                $totalAssets += $rAssetsCount;
                $totalUnits += $rUnitsCount;

                $roomsData[] = [
                    'id' => $room->id,
                    'nama_ruangan' => $room->nama_ruangan,
                    'kode_ruangan' => $room->kode_ruangan,
                    'lantai' => $room->lantai,
                    'total_assets' => $rAssetsCount,
                    'total_units' => $rUnitsCount,
                    'assets' => $room->assets->map(function ($a) {
                        return [
                            'id' => $a->id,
                            'nama_aset' => $a->nama_aset,
                            'kode_aset' => $a->kode_aset,
                            'category' => $a->category,
                            'kondisi' => $a->kondisi,
                            'jumlah' => (int)$a->jumlah,
                            'status' => $a->status,
                        ];
                    }),
                ];
            }

            return [
                'id' => $b->id,
                'nama_gedung' => $b->nama_gedung,
                'kode_gedung' => $b->kode_gedung,
                'alamat' => $b->alamat,
                'rooms_count' => $b->rooms->count(),
                'total_assets' => $totalAssets,
                'total_units' => $totalUnits,
                'asset_audit_interval' => $b->asset_audit_interval ?? 'bimonthly',
                'asset_audit_interval_days' => (int)($b->asset_audit_interval_days ?? 60),
                'last_asset_audit_at' => $lastAudit?->toIso8601String(),
                'next_asset_audit_due' => $nextDue?->toDateString(),
                'audit_status' => $status,
                'rooms' => $roomsData,
            ];
        });

        return $this->success([
            'summary' => $summary,
            'buildings' => $buildingsList,
        ], 'Ringkasan jadwal audit aset per gedung berhasil diambil.');
    }

    /**
     * GET /buildings/{id}/assets-tree
     * Pohon data ruangan dan aset dalam 1 gedung untuk form audit fisik gedung
     */
    public function getBuildingAssetsTree($buildingId)
    {
        $building = Building::with(['rooms' => function ($q) {
            $q->where('is_active', true)->with('assets');
        }])->findOrFail($buildingId);

        $totalAssets = 0;
        $totalUnits = 0;

        $roomsData = $building->rooms->map(function ($r) use (&$totalAssets, &$totalUnits) {
            $assetsCount = $r->assets->count();
            $unitsCount = $r->assets->sum('jumlah');
            $totalAssets += $assetsCount;
            $totalUnits += $unitsCount;

            return [
                'id' => $r->id,
                'nama_ruangan' => $r->nama_ruangan,
                'kode_ruangan' => $r->kode_ruangan,
                'lantai' => $r->lantai,
                'assets' => $r->assets->map(function ($a) {
                    return [
                        'id' => $a->id,
                        'nama_aset' => $a->nama_aset,
                        'kode_aset' => $a->kode_aset,
                        'category' => $a->category,
                        'kondisi' => $a->kondisi,
                        'jumlah' => (int)$a->jumlah,
                        'status' => $a->status,
                    ];
                }),
            ];
        });

        return $this->success([
            'id' => $building->id,
            'nama_gedung' => $building->nama_gedung,
            'kode_gedung' => $building->kode_gedung,
            'alamat' => $building->alamat,
            'rooms_count' => $building->rooms->count(),
            'total_assets' => $totalAssets,
            'total_units' => $totalUnits,
            'rooms' => $roomsData,
        ], 'Struktur aset gedung berhasil diambil.');
    }

    /**
     * GET /room-asset-audits/schedule-summary
     * (Backward Compatibility) Ringkasan per ruangan
     */
    public function scheduleSummary(Request $request)
    {
        return $this->buildingSummary($request);
    }

    /**
     * GET /room-asset-audits/{auditId}/items/{itemId}/foto
     * Streaming foto bukti audit aset
     */
    public function streamFoto($auditId, $itemId)
    {
        $item = RoomAssetAuditItem::where('room_asset_audit_id', $auditId)->findOrFail($itemId);

        if (!$item->foto_bukti || !Storage::disk('public')->exists($item->foto_bukti)) {
            return response()->json(['message' => 'Foto bukti tidak ditemukan.'], 404);
        }

        return response()->file(Storage::disk('public')->path($item->foto_bukti));
    }

    /**
     * DELETE /room-asset-audits/{id}
     */
    public function destroy($id)
    {
        $audit = RoomAssetAudit::findOrFail($id);
        $oldData = $audit->toArray();

        $audit->delete();

        AuditLogService::log('DELETE_ASSET_AUDIT', 'room_asset_audits', $audit->id, $oldData, null);

        return $this->success(null, 'Riwayat audit aset berhasil dihapus.');
    }
}
