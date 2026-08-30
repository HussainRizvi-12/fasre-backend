<?php

namespace App\Filament\Widgets;

use App\Enums\AuditAssignmentStatus;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\ReviewWindow;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $facultyCount = User::where('role', UserRole::Faculty)->count();
        $studentCount = User::where('role', UserRole::Student)->count();
        $activeWindowsCount = ReviewWindow::where('status', ReviewWindowStatus::Active)->count();
        $pendingAuditsCount = AuditAssignment::where('status', AuditAssignmentStatus::Submitted)->count();

        return [
            Stat::make('Total Faculty', $facultyCount)
                ->description('Active teaching staff')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('warning'),

            Stat::make('Total Students', $studentCount)
                ->description('Enrolled students')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info'),

            Stat::make('Active Review Windows', $activeWindowsCount)
                ->description('Ongoing evaluation cycles')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success'),

            Stat::make('Pending Audit Approvals', $pendingAuditsCount)
                ->description('Audits awaiting admin review')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingAuditsCount > 0 ? 'danger' : 'gray'),
        ];
    }
}
