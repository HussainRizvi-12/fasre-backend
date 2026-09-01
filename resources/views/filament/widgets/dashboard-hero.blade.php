<x-filament-widgets::widget>
    @php
        $activeWindow = \App\Models\ReviewWindow::where('status', \App\Enums\ReviewWindowStatus::Active->value)->first();
        $hour = now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    @endphp

    <div class="relative w-full max-w-full overflow-hidden rounded-2xl p-6 shadow-xl isolation isolate sm:p-7" style="background: linear-gradient(135deg, #0e153f 0%, #16215e 55%, #1e3a8a 100%); border: 1px solid rgba(59, 130, 246, 0.25); color: #ffffff;">
        {{-- Ambient Lighting --}}
        <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full" style="background: radial-gradient(circle, rgba(245, 197, 24, 0.14) 0%, rgba(30, 58, 138, 0) 70%);"></div>
        <div class="pointer-events-none absolute -bottom-20 -left-20 h-60 w-60 rounded-full" style="background: radial-gradient(circle, rgba(59, 130, 246, 0.14) 0%, rgba(30, 58, 138, 0) 70%);"></div>

        <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            {{-- Greeting & Overview --}}
            <div class="max-w-2xl space-y-2.5">
                <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.18); color: #fde047;">
                    <span class="fasre-pulse h-2 w-2 rounded-full" style="background-color: #34d399;"></span>
                    @if($activeWindow)
                        <span>{{ $activeWindow->title }} — Active Cycle</span>
                    @else
                        <span>No active review cycle</span>
                    @endif
                </div>

                <h2 class="text-2xl font-extrabold tracking-tight text-white sm:text-3xl" style="font-family: 'Plus Jakarta Sans', sans-serif;">
                    {{ $greeting }}, {{ explode(' ', auth()->user()->name ?? 'Administrator')[0] }}
                </h2>

                <p class="text-xs leading-relaxed text-slate-300 sm:text-sm">
                    Institutional quality assurance command center — monitor evaluation cycles, review peer audits, and inspect aggregated course feedback.
                </p>
            </div>

            {{-- Quick Actions --}}
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <a
                    href="{{ url('/admin/review-windows/create') }}"
                    class="inline-flex transform items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-bold text-slate-950 shadow-md transition-all hover:-translate-y-0.5"
                    style="background: linear-gradient(135deg, #f5c518 0%, #d4a50f 100%);"
                >
                    <svg class="h-4 w-4 text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>New Review Window</span>
                </a>

                <a
                    href="{{ url('/admin/audit-assignments/create') }}"
                    class="inline-flex transform items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition-all hover:-translate-y-0.5"
                    style="background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.22); backdrop-filter: blur(8px);"
                >
                    <svg class="h-4 w-4" style="color: #f5c518;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h6M5 21h14a2 2 0 002-2V7l-5-5H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    <span>Assign Peer Audit</span>
                </a>

                <a
                    href="{{ url('/admin/review-results') }}"
                    class="inline-flex transform items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition-all hover:-translate-y-0.5"
                    style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.16); backdrop-filter: blur(8px);"
                >
                    <svg class="h-4 w-4" style="color: #7dd3fc;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Analytics Matrix</span>
                </a>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
