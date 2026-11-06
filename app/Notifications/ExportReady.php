<?php

namespace App\Notifications;

use App\Models\ReportExport;
use Illuminate\Notifications\Notification;

class ExportReady extends Notification
{
    public function __construct(private ReportExport $export) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['export_id' => $this->export->id, 'report' => $this->export->report, 'format' => $this->export->format, 'rows' => $this->export->row_count];
    }
}
