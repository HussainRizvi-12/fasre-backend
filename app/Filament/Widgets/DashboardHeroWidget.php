<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class DashboardHeroWidget extends Widget
{
    protected static string $view = 'filament.widgets.dashboard-hero';

    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';
}
