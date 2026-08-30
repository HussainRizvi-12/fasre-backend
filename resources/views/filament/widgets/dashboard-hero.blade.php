<div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#001d5c] via-[#00236f] to-[#1e3a8a] text-white p-6 sm:p-8 shadow-xl shadow-blue-950/20 border border-blue-800/40">
    {{-- Ambient Decorative Glow Elements --}}
    <div class="absolute -top-24 -right-24 w-72 h-72 bg-gradient-to-br from-amber-400/20 to-blue-500/0 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-20 -left-20 w-60 h-60 bg-blue-400/10 rounded-full blur-2xl pointer-events-none"></div>

    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        {{-- Greeting & Overview --}}
        <div class="space-y-2">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-white/10 backdrop-blur-md border border-white/15 text-amber-300">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span>Fall 2026 Academic Evaluation Cycle Active</span>
            </div>

            <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white" style="font-family: var(--font-display);">
                Welcome back, {{ auth()->user()->name ?? 'Administrator' }} 👋
            </h2>

            <p class="text-sm text-blue-100/80 max-w-xl">
                Higher Education Institutional Quality Assurance Portal. Monitor student evaluation metrics, assign peer faculty audits, and inspect aggregated course feedback.
            </p>
        </div>

        {{-- Command Center Quick Actions --}}
        <div class="flex flex-wrap items-center gap-3">
            <a
                href="{{ \App\Filament\Resources\ReviewWindowResource::getUrl('create') }}"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-900 bg-gradient-to-r from-[#f5c518] to-amber-400 hover:from-amber-400 hover:to-amber-500 shadow-md shadow-amber-950/20 transition-all transform hover:-translate-y-0.5"
            >
                <svg class="w-4 h-4 text-slate-900" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                </svg>
                <span>New Review Window</span>
            </a>

            <a
                href="{{ \App\Filament\Resources\AuditAssignmentResource::getUrl('create') }}"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-white bg-white/15 hover:bg-white/25 backdrop-blur-md border border-white/20 shadow-sm transition-all transform hover:-translate-y-0.5"
            >
                <svg class="w-4 h-4 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                <span>Assign Peer Audit</span>
            </a>

            <a
                href="{{ \App\Filament\Pages\ReviewResults::getUrl() }}"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-white bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/15 shadow-sm transition-all transform hover:-translate-y-0.5"
            >
                <svg class="w-4 h-4 text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span>Analytics Matrix</span>
            </a>
        </div>
    </div>
</div>
