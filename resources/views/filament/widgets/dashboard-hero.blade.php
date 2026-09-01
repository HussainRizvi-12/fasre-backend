<x-filament-widgets::widget>
    @php
        $activeWindow = \App\Models\ReviewWindow::where('status', \App\Enums\ReviewWindowStatus::Active)->first();
    @endphp

    <div class="relative overflow-hidden rounded-2xl p-6 sm:p-7 shadow-xl w-full" style="background: linear-gradient(135deg, #00143d 0%, #0a1b4d 50%, #172a6b 100%); border: 1px solid rgba(59, 130, 246, 0.3); color: #ffffff; isolation: isolate; max-width: 100%;">
        {{-- Ambient Lighting --}}
        <div class="absolute -top-24 -right-24 w-72 h-72 rounded-full pointer-events-none" style="background: radial-gradient(circle, rgba(245, 197, 24, 0.15) 0%, rgba(30, 58, 138, 0) 70%);"></div>
        <div class="absolute -bottom-20 -left-20 w-60 h-60 rounded-full pointer-events-none" style="background: radial-gradient(circle, rgba(59, 130, 246, 0.12) 0%, rgba(30, 58, 138, 0) 70%);"></div>

        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            {{-- Greeting & Overview --}}
            <div class="space-y-2.5 max-w-2xl">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.18); color: #fde047;">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    @if($activeWindow)
                        <span>{{ $activeWindow->title }} (Active Cycle)</span>
                    @else
                        <span>Fall 2026 Academic Evaluation Cycle</span>
                    @endif
                </div>

                <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white" style="font-family: 'Outfit', sans-serif;">
                    Welcome back, {{ auth()->user()->name ?? 'Administrator' }} 👋
                </h2>

                <p class="text-xs sm:text-sm text-slate-200 leading-relaxed">
                    Higher Education Institutional Quality Assurance Portal. Monitor student evaluation metrics, assign peer faculty audits, and inspect aggregated course feedback.
                </p>
            </div>

            {{-- Command Center Quick Actions --}}
            <div class="flex flex-wrap items-center gap-3 shrink-0">
                <a
                    href="{{ url('/admin/review-windows/create') }}"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-950 transition-all transform hover:-translate-y-0.5 shadow-md"
                    style="background: linear-gradient(135deg, #f5c518 0%, #eab308 100%);"
                >
                    <svg class="w-4 h-4 text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>New Review Window</span>
                </a>

                <a
                    href="{{ url('/admin/audit-assignments/create') }}"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-white transition-all transform hover:-translate-y-0.5 shadow-sm"
                    style="background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.22);"
                >
                    <svg class="w-4 h-4 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    <span>Assign Peer Audit</span>
                </a>

                <a
                    href="{{ url('/admin/review-results') }}"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-white transition-all transform hover:-translate-y-0.5 shadow-sm"
                    style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.16);"
                >
                    <svg class="w-4 h-4 text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Analytics Matrix</span>
                </a>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
