<div class="relative flex min-h-screen items-center justify-center overflow-y-auto px-4 py-10" style="background-color: #0e153f;">
    <!-- Campus Backdrop Image -->
    <img
        src="{{ asset('images/campus.jpg') }}"
        alt="Campus Backdrop"
        class="absolute inset-0 h-full w-full object-cover"
    />
    <div class="absolute inset-0" style="background-color: rgba(14, 21, 63, 0.85);"></div>
    <div class="absolute inset-0 bg-gradient-to-br from-[#16215e]/50 via-transparent to-[#0e153f]/90"></div>

    <div class="relative z-10 w-full max-w-[460px]">
        <!-- University Branding -->
        <div class="mb-7 text-center">
            <img
                src="{{ asset('images/crest.png') }}"
                alt="Meridian State University Crest"
                class="mx-auto h-20 w-20 rounded-full object-cover shadow-xl ring-4 ring-[#f5c518]/70"
            />
            <p class="mt-4 text-[11px] font-bold uppercase tracking-[0.22em] text-[#f5c518]">
                Meridian State University
            </p>
            <h1 class="mt-1 text-[26px] font-extrabold tracking-tight text-white">
                FASRE Admin Portal
            </h1>
            <p class="mt-0.5 text-[13px] text-white/70">
                Faculty Audit &amp; Student Review Ecosystem
            </p>
        </div>

        <!-- Form Card -->
        <div class="rounded-2xl border border-white/15 bg-white p-7 sm:p-8 shadow-2xl">
            <div class="mb-5 flex items-center gap-2 text-[#1e3a8a]">
                <svg class="h-5 w-5 text-[#1e3a8a]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
                <h2 class="text-[16px] font-bold text-slate-800">Sign in to continue</h2>
            </div>

            <x-filament-panels::form wire:submit="authenticate">
                {{ $this->form }}

                <div class="mt-6">
                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </div>
            </x-filament-panels::form>

            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200/80 p-3 text-center">
                <p class="text-[11.5px] text-slate-500">
                    Demo Administrator: <span class="font-semibold text-slate-700">admin@fasre.test</span> &bull; <span class="font-semibold text-slate-700">Password@123</span>
                </p>
            </div>
        </div>
    </div>
</div>
