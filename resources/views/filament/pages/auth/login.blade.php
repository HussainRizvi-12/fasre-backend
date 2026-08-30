<x-filament-panels::page.simple>
    <x-slot name="heading">
        <div class="text-center">
            <img
                src="{{ asset('images/crest.png') }}"
                alt="Meridian State University Crest"
                class="mx-auto h-16 w-16 rounded-full object-cover shadow-lg ring-4 ring-[#f5c518]/70"
            />
            <p class="mt-3 text-[11px] font-bold uppercase tracking-[0.2em] text-[#f5c518]">
                Meridian State University
            </p>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900">
                FASRE Admin Portal
            </h1>
            <p class="mt-0.5 text-xs text-slate-500">
                Faculty Audit &amp; Student Review Ecosystem
            </p>
        </div>
    </x-slot>

    <div class="mt-2">
        <x-filament-panels::form id="form" wire:submit="authenticate">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    </div>

    <div class="mt-4 rounded-lg bg-slate-50 border border-slate-200/80 p-2.5 text-center">
        <p class="text-[11.5px] text-slate-600">
            Demo Admin: <span class="font-semibold text-slate-800">admin@fasre.test</span> &bull; <span class="font-semibold text-slate-800">Password@123</span>
        </p>
    </div>
</x-filament-panels::page.simple>
