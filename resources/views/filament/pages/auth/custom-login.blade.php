<div class="relative w-full" x-data="{
    fillDemo(email, password = 'Password@123') {
        $wire.set('data.email', email);
        $wire.set('data.password', password);
    }
}">
    {{-- Header with Academic Branding --}}
    <div class="text-center mb-6">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-[#00236f] via-[#1e3a8a] to-[#2546a8] shadow-lg shadow-blue-950/20 ring-1 ring-white/30 mb-3">
            <svg class="w-8 h-8 text-[#f5c518] drop-shadow" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18L12 21L19 17.18V13.18L12 17L5 13.18Z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white" style="font-family: var(--font-display);">
            FASRE <span class="text-[#f5c518] font-bold">Admin Portal</span>
        </h1>
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">
            Faculty Audit &amp; Student Review Ecosystem
        </p>

        {{-- Live Cloud Pill --}}
        <div class="inline-flex items-center gap-1.5 px-3 py-1 mt-3 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Azure Production Cloud Active
        </div>
    </div>

    {{-- Main Login Form Card --}}
    <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur-xl rounded-2xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-xl shadow-blue-950/5">
        <x-filament-panels::form wire:submit="authenticate">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{-- 1-Click Demo Accounts Quick-Fill --}}
        <div class="mt-8 pt-6 border-t border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    ⚡ 1-Click Demo Logins
                </span>
                <span class="text-[10px] text-slate-400">
                    Auto-populates fields
                </span>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    @click="fillDemo('admin@fasre.test')"
                    class="group flex items-center gap-2 p-2 rounded-xl text-left text-xs bg-slate-50 hover:bg-blue-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-blue-300 transition-all"
                >
                    <span class="flex items-center justify-center w-6 h-6 rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300 font-bold text-[10px]">
                        🛡️
                    </span>
                    <div class="truncate">
                        <div class="font-semibold text-slate-900 dark:text-slate-100 group-hover:text-blue-600">System Admin</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">admin@fasre.test</div>
                    </div>
                </button>

                <button
                    type="button"
                    @click="fillDemo('usman.raza@fasre.test')"
                    class="group flex items-center gap-2 p-2 rounded-xl text-left text-xs bg-slate-50 hover:bg-amber-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-amber-300 transition-all"
                >
                    <span class="flex items-center justify-center w-6 h-6 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300 font-bold text-[10px]">
                        📝
                    </span>
                    <div class="truncate">
                        <div class="font-semibold text-slate-900 dark:text-slate-100 group-hover:text-amber-600">Faculty Auditor</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">Dr. Usman Raza</div>
                    </div>
                </button>

                <button
                    type="button"
                    @click="fillDemo('sara.ali@fasre.test')"
                    class="group flex items-center gap-2 p-2 rounded-xl text-left text-xs bg-slate-50 hover:bg-emerald-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-emerald-300 transition-all"
                >
                    <span class="flex items-center justify-center w-6 h-6 rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 font-bold text-[10px]">
                        📊
                    </span>
                    <div class="truncate">
                        <div class="font-semibold text-slate-900 dark:text-slate-100 group-hover:text-emerald-600">Faculty Auditee</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">Dr. Sara Ali</div>
                    </div>
                </button>

                <button
                    type="button"
                    @click="fillDemo('ali.hassan@fasre.test')"
                    class="group flex items-center gap-2 p-2 rounded-xl text-left text-xs bg-slate-50 hover:bg-indigo-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-indigo-300 transition-all"
                >
                    <span class="flex items-center justify-center w-6 h-6 rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 font-bold text-[10px]">
                        🎓
                    </span>
                    <div class="truncate">
                        <div class="font-semibold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600">Student Account</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">Ali Hassan</div>
                    </div>
                </button>
            </div>
        </div>
    </div>

    {{-- Footer Institutional Badge --}}
    <div class="text-center mt-6 text-[11px] text-slate-400 dark:text-slate-500 flex items-center justify-center gap-2">
        <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <span>Zero-Trust Cryptographic Anonymity Enforced &bull; Laravel Sanctum Guarded</span>
    </div>
</div>
