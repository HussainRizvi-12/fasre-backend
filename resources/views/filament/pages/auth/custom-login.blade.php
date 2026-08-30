<x-filament-panels::page.simple>
    <x-slot name="heading">
        <div class="text-center pb-2">
            <h1 class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white" style="font-family: var(--font-display);">
                FASRE <span class="text-[#f5c518] font-bold">Admin Portal</span>
            </h1>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">
                Faculty Audit &amp; Student Review Ecosystem
            </p>
        </div>
    </x-slot>

    <div x-data="{
        fillDemo(email, password = 'Password@123') {
            const emailInput = document.querySelector('input[type=\'email\'], input[id*=\'email\']');
            const passInput = document.querySelector('input[type=\'password\'], input[id*=\'password\']');
            if (emailInput) {
                emailInput.value = email;
                emailInput.dispatchEvent(new Event('input', { bubbles: true }));
                emailInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (passInput) {
                passInput.value = password;
                passInput.dispatchEvent(new Event('input', { bubbles: true }));
                passInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            try {
                $wire.set('data.email', email);
                $wire.set('data.password', password);
            } catch(e) {}
        }
    }">
        <x-filament-panels::form wire:submit="authenticate">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        {{-- 1-Click Demo Logins --}}
        <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between mb-3">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    ⚡ 1-Click Demo Persona Credentials
                </span>
                <span class="text-[10px] text-slate-400">Click to fill</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    @click="fillDemo('admin@fasre.test')"
                    class="group flex items-center gap-2 p-2.5 rounded-xl text-left text-xs bg-slate-50 hover:bg-blue-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-blue-300 transition-all cursor-pointer shadow-sm"
                >
                    <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300 font-bold text-xs shrink-0">
                        🛡️
                    </span>
                    <div class="truncate">
                        <div class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-blue-600">System Admin</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">admin@fasre.test</div>
                    </div>
                </button>

                <button
                    type="button"
                    @click="fillDemo('usman.raza@fasre.test')"
                    class="group flex items-center gap-2 p-2.5 rounded-xl text-left text-xs bg-slate-50 hover:bg-amber-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-amber-300 transition-all cursor-pointer shadow-sm"
                >
                    <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300 font-bold text-xs shrink-0">
                        📝
                    </span>
                    <div class="truncate">
                        <div class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-amber-600">Faculty Auditor</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">Dr. Usman Raza</div>
                    </div>
                </button>

                <button
                    type="button"
                    @click="fillDemo('sara.ali@fasre.test')"
                    class="group flex items-center gap-2 p-2.5 rounded-xl text-left text-xs bg-slate-50 hover:bg-emerald-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-emerald-300 transition-all cursor-pointer shadow-sm"
                >
                    <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 font-bold text-xs shrink-0">
                        📊
                    </span>
                    <div class="truncate">
                        <div class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-emerald-600">Faculty Auditee</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">Dr. Sara Ali</div>
                    </div>
                </button>

                <button
                    type="button"
                    @click="fillDemo('ali.hassan@fasre.test')"
                    class="group flex items-center gap-2 p-2.5 rounded-xl text-left text-xs bg-slate-50 hover:bg-indigo-50 dark:bg-slate-800/60 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 hover:border-indigo-300 transition-all cursor-pointer shadow-sm"
                >
                    <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 font-bold text-xs shrink-0">
                        🎓
                    </span>
                    <div class="truncate">
                        <div class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600">Student Account</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">Ali Hassan</div>
                    </div>
                </button>
            </div>
        </div>
    </div>
</x-filament-panels::page.simple>
