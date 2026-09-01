<x-filament-panels::page.simple>
    <x-slot name="heading">
        <div class="pb-1 text-center">
            <div class="mx-auto mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl shadow-xl"
                style="background: linear-gradient(135deg, #1e3a8a 0%, #2546a8 100%); box-shadow: 0 12px 28px -8px rgba(30, 58, 138, 0.55);">
                <svg class="h-7 w-7" style="color: #f5c518;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M22 10 12 5 2 10l10 5 10-5zM6 12v5c3.3 2.5 8.7 2.5 12 0v-5"/>
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white" style="font-family: 'Plus Jakarta Sans', sans-serif;">
                Sign in to <span class="text-navy-600 dark:text-sky-400" style="color: #1e3a8a;">FASRE</span>
            </h1>
            <p class="mt-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                Faculty Audit &amp; Student Review Ecosystem — Administrator Access
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
        <div class="mt-6 border-t border-slate-200/80 pt-5 dark:border-slate-800">
            <button
                type="button"
                @click="fillAdmin()"
                class="group flex w-full cursor-pointer items-center justify-between rounded-xl border border-slate-200 bg-slate-50/80 p-3 text-left shadow-sm transition-all hover:border-blue-300 hover:bg-blue-50/60 dark:border-slate-700 dark:bg-slate-800/60 dark:hover:border-blue-500 dark:hover:bg-slate-800"
            >
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-sm shadow-sm"
                        style="background: linear-gradient(135deg, #1e3a8a 0%, #2546a8 100%);">
                        <svg class="h-4 w-4" style="color: #f5c518;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-xs font-bold text-slate-900 group-hover:text-blue-700 dark:text-slate-100 dark:group-hover:text-blue-400">
                            Autofill demo admin credentials
                        </div>
                        <div class="font-mono text-[11px] text-slate-500 dark:text-slate-400">
                            admin@fasre.test &bull; Password@123
                        </div>
                    </div>
                </div>
                <span class="text-[11px] font-bold text-blue-600 transition-transform group-hover:translate-x-0.5 dark:text-blue-400">
                    Fill &rarr;
                </span>
            </button>
        </div>

        {{-- Privacy & anonymity reassurance strip --}}
        <div class="mt-5 flex items-center justify-center gap-4 text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
            <span class="flex items-center gap-1.5">
                <svg class="h-3 w-3" style="color: #10b981;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                K&#8209;anonymity enforced
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="h-3 w-3" style="color: #10b981;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Anonymous reviews
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="h-3 w-3" style="color: #10b981;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Azure secured
            </span>
        </div>
    </div>
</x-filament-panels::page.simple>
