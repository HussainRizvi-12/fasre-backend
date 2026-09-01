<x-filament-panels::page.simple>
    <x-slot name="heading">
        <div class="text-center pb-1">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl mb-3 shadow-md ring-1 ring-amber-400/40" style="background: linear-gradient(135deg, #00194e 0%, #1e3a8a 100%);">
                <svg class="w-7 h-7 text-[#f5c518]" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18L12 21L19 17.18V13.18L12 17L5 13.18Z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white" style="font-family: 'Outfit', sans-serif;">
                FASRE <span class="text-amber-500 font-bold">Admin Portal</span>
            </h1>
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mt-1">
                Institutional Quality Assurance Administration
            </p>
        </div>
    </x-slot>

    <div x-data="{
        fillAdmin(email = 'admin@fasre.test', password = 'Password@123') {
            const emailInput = document.querySelector('input[type=\'email\'], input[id*=\'email\'], input[name=\'email\']');
            const passInput = document.querySelector('input[type=\'password\'], input[id*=\'password\'], input[name=\'password\']');
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

        {{-- 1-Click Demo Admin Access --}}
        <div class="mt-6 pt-5 border-t border-slate-200/80 dark:border-slate-800">
            <button
                type="button"
                @click="fillAdmin()"
                class="w-full group flex items-center justify-between p-3 rounded-xl text-left bg-gradient-to-r from-blue-50/80 to-slate-50 dark:from-slate-800/80 dark:to-slate-800/50 hover:from-blue-100 hover:to-blue-50 dark:hover:from-slate-700/80 dark:hover:to-slate-800 border border-blue-200/80 dark:border-slate-700 hover:border-blue-400 dark:hover:border-blue-500 transition-all cursor-pointer shadow-xs"
            >
                <div class="flex items-center gap-3">
                    <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-600 text-white font-bold text-sm shadow-xs">
                        🛡️
                    </span>
                    <div>
                        <div class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover:text-blue-600 dark:group-hover:text-blue-400">
                            Autofill Demo Admin Credentials
                        </div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400">
                            admin@fasre.test &bull; Password@123
                        </div>
                    </div>
                </div>
                <span class="text-[11px] font-bold text-blue-600 dark:text-blue-400 group-hover:translate-x-0.5 transition-transform">
                    Fill &rarr;
                </span>
            </button>
        </div>
    </div>
</x-filament-panels::page.simple>
