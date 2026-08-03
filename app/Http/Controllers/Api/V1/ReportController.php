<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Building;
use App\Models\AuditLog;
use App\Http\Requests\ExportReportRequest;
use App\Http\Resources\AuditLogResource;
use App\Traits\ApiResponse;
use App\Exports\TasksExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    /**
     * Ekspor laporan tugas kebersihan ke format PDF (streamed).
     */
    public function exportPdf(ExportReportRequest $request)
    {
        $filters = $request->validated();
        $query = Task::query()->with(['room.building', 'cs', 'shift']);

        if (!empty($filters['date_from'])) {
            $query->whereDate('tanggal_task', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('tanggal_task', '<=', $filters['date_to']);
        }

        if (!empty($filters['building_id'])) {
            $buildingId = $filters['building_id'];
            $query->whereHas('room', function ($q) use ($buildingId) {
                $q->where('building_id', $buildingId);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $tasks = $query->orderBy('tanggal_task', 'asc')->get();

        $buildingName = null;
        if (!empty($filters['building_id'])) {
            $buildingName = Building::find($filters['building_id'])?->nama_gedung;
        }

        $pdf = Pdf::loadView('reports.report_pdf', [
            'tasks' => $tasks,
            'filters' => $filters,
            'building_name' => $buildingName,
        ]);

        return $pdf->stream('report-tasks.pdf');
    }

    /**
     * Ekspor laporan tugas kebersihan ke format Excel (streamed).
     */
    public function exportExcel(ExportReportRequest $request)
    {
        $filters = $request->validated();
        return Excel::download(new TasksExport($filters), 'report-tasks.xlsx');
    }

    /**
     * Ekspor laporan temuan kerusakan ke format PDF (streamed).
     */
    public function exportFindingsPdf(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'building_id' => ['nullable', 'exists:buildings,id'],
            'status' => ['nullable', 'string', 'in:open,in_progress,resolved'],
            'prioritas' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        $query = \App\Models\Finding::query()->with(['room.building', 'reporter', 'assignee']);

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->building_id) {
            $query->whereHas('room', function($q) use ($request) {
                $q->where('building_id', $request->building_id);
            });
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->prioritas) {
            $query->where('prioritas', $request->prioritas);
        }

        $findings = $query->orderBy('created_at', 'asc')->get();

        $buildingName = null;
        if ($request->building_id) {
            $buildingName = Building::find($request->building_id)?->nama_gedung;
        }

        $pdf = Pdf::loadView('reports.findings_pdf', [
            'findings' => $findings,
            'filters' => $request->all(),
            'building_name' => $buildingName,
        ]);

        return $pdf->stream('report-temuan-kerusakan.pdf');
    }

    /**
     * Ekspor laporan temuan kerusakan ke format Excel (streamed).
     */
    public function exportFindingsExcel(Request $request)
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'building_id' => ['nullable', 'exists:buildings,id'],
            'status' => ['nullable', 'string', 'in:open,in_progress,resolved'],
            'prioritas' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        return Excel::download(new \App\Exports\FindingsExport($request->all()), 'report-temuan-kerusakan.xlsx');
    }

    /**
     * Ambil data audit logs/trail dengan filter dan pagination (Admin Only).
     */
    public function auditLogs(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $search = $request->get('search');
        $action = $request->get('action');
        $userId = $request->get('user_id');

        $user = $request->user();
        $query = AuditLog::query()->with('user');

        if ($user->hasRole(\App\Enums\RoleEnum::CS)) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'tasks')
                          ->whereIn('entity_id', function ($taskQuery) use ($user) {
                              $taskQuery->select('id')
                                  ->from('tasks')
                                  ->where('cs_user_id', $user->id);
                          });
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'checklist_submissions')
                          ->whereIn('entity_id', function ($subQuery) use ($user) {
                              $subQuery->select('id')
                                  ->from('checklist_submissions')
                                  ->where('cs_user_id', $user->id);
                          });
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'verifications')
                          ->whereIn('entity_id', function ($verifyQuery) use ($user) {
                              $verifyQuery->select('v.id')
                                  ->from('verifications as v')
                                  ->join('checklist_submissions as cs', 'v.submission_id', '=', 'cs.id')
                                  ->where('cs.cs_user_id', $user->id);
                          });
                  });
            });
        } elseif ($user->hasRole(\App\Enums\RoleEnum::PIC)) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'tasks')
                          ->whereIn('entity_id', function ($taskQuery) use ($user) {
                              $taskQuery->select('t.id')
                                  ->from('tasks as t')
                                  ->join('rooms as r', 't.room_id', '=', 'r.id')
                                  ->where('r.pic_user_id', $user->id);
                          });
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'checklist_submissions')
                          ->whereIn('entity_id', function ($subQuery) use ($user) {
                              $subQuery->select('cs.id')
                                  ->from('checklist_submissions as cs')
                                  ->join('tasks as t', 'cs.task_id', '=', 't.id')
                                  ->join('rooms as r', 't.room_id', '=', 'r.id')
                                  ->where('r.pic_user_id', $user->id);
                          });
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'verifications')
                          ->whereIn('entity_id', function ($verifyQuery) use ($user) {
                              $verifyQuery->select('v.id')
                                  ->from('verifications as v')
                                  ->join('checklist_submissions as cs', 'v.submission_id', '=', 'cs.id')
                                  ->join('tasks as t', 'cs.task_id', '=', 't.id')
                                  ->join('rooms as r', 't.room_id', '=', 'r.id')
                                  ->where('r.pic_user_id', $user->id);
                          });
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('entity_type', 'rooms')
                          ->whereIn('entity_id', function ($roomQuery) use ($user) {
                              $roomQuery->select('id')
                                  ->from('rooms')
                                  ->where('pic_user_id', $user->id);
                          });
                  });
            });
        } else {
            if (!empty($userId)) {
                $query->where('user_id', $userId);
            }
        }

        if (!empty($action)) {
            $query->where('action', $action);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('entity_type', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($qu) use ($search) {
                      $qu->where('full_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                  });
            });
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->paginated(AuditLogResource::collection($logs), 'Data audit logs berhasil diambil.');
    }
}
