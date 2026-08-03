<?php

namespace App\Services;

use App\Mail\ChecklistSubmittedMail;
use App\Mail\ChecklistApprovedMail;
use App\Mail\ChecklistRejectedMail;
use App\Mail\TaskReminderMail;
use App\Mail\TaskOverdueMail;
use App\Mail\EscalationPicMail;
use App\Mail\FindingReportedMail;
use App\Mail\PasswordResetMail;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * Kirim email asinkron (Mailable ShouldQueue) berdasarkan tipe pemicu.
     *
     * @param mixed $user User model
     * @param string $type Tipe pemicu notifikasi
     * @param string $title Judul notifikasi
     * @param string $message Isi pesan
     * @param array $data Data tambahan
     * @return void
     */
    public static function sendNotificationMail($user, string $type, string $title, string $message, array $data = []): void
    {
        $mailable = null;

        switch ($type) {
            case 'checklist_submitted':
                $mailable = new ChecklistSubmittedMail($title, $message, $data);
                break;
            case 'checklist_approved':
                $mailable = new ChecklistApprovedMail($title, $message, $data);
                break;
            case 'checklist_rejected':
                $mailable = new ChecklistRejectedMail($title, $message, $data);
                break;
            case 'task_reminder':
                $mailable = new TaskReminderMail($title, $message, $data);
                break;
            case 'task_overdue':
                $mailable = new TaskOverdueMail($title, $message, $data);
                break;
            case 'escalation_pic':
                $mailable = new EscalationPicMail($title, $message, $data);
                break;
            case 'finding_reported':
                $mailable = new FindingReportedMail($title, $message, $data);
                break;
            case 'password_reset':
                $mailable = new PasswordResetMail($title, $message, $data);
                break;
            case 'welcome':
                $mailable = new WelcomeMail($title, $message, $data);
                break;
            default:
                $mailable = new WelcomeMail($title, $message, $data);
                break;
        }

        if ($mailable) {
            Mail::to($user->email)->send($mailable);
        }
    }
}
