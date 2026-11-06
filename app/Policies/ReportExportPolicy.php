<?php

namespace App\Policies;

use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReportExportPolicy
{
    /** Exports may contain financial data: only the requester can see or download them. */
    public function view(User $user, ReportExport $export): Response
    {
        return $export->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
