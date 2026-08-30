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
            Stat::make('Total Faculty Members', $facultyCount)
                ->description('Registered teaching staff')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->chart([3, 5, 4, 6, 7, 8, $facultyCount])
                ->color('warning'),

            Stat::make('Enrolled Students', $studentCount)
                ->description('Active course participants')
                ->descriptionIcon('heroicon-m-user-group')
                ->chart([10, 20, 25, 30, 40, 50, $studentCount])
                ->color('info'),

            Stat::make('Active Review Cycles', $activeWindowsCount)
                ->description($activeWindowsCount > 0 ? 'Surveys open for submissions' : 'No windows open')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->chart([1, 0, 1, 2, 1, 2, $activeWindowsCount])
                ->color('success'),

            Stat::make('Pending Audit Reviews', $pendingAuditsCount)
                ->description($pendingAuditsCount > 0 ? 'Awaiting Dean / Admin approval' : 'All audits reviewed')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->chart([2, 4, 1, 3, 2, 1, $pendingAuditsCount])
                ->color($pendingAuditsCount > 0 ? 'danger' : 'gray'),
        ];
    }
}
