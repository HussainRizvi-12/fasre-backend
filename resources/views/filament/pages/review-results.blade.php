<x-filament-panels::page>
    {{-- Form Filter Card --}}
    <div class="mb-6">
        {{ $this->form }}
    </div>

    @if (! $review_window_id)
        <div class="p-12 text-center bg-white/90 dark:bg-slate-900/90 backdrop-blur-md rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100" style="font-family: var(--font-display);">
                No Review Window Selected
            </h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1.5 max-w-md mx-auto">
                Please select an active or published review cycle above to generate aggregated course evaluation metrics and student feedback analytics.
            </p>
        </div>
    @else
        {{-- Section Cards Grid --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @forelse ($this->sections as $section)
                @php
                    $result = $this->getAggregatedResults($section->id);
                    $primaryFaculty = $section->facultyAssignments->firstWhere('is_primary', true)?->faculty;
                @endphp

                <div class="group bg-white/95 dark:bg-slate-900/95 backdrop-blur-md rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm hover:shadow-lg transition-all duration-200 p-6 flex flex-col justify-between">
                    <div>
                        {{-- Section Header --}}
                        <div class="flex items-start justify-between border-b border-slate-100 dark:border-slate-800/80 pb-4 mb-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="px-2.5 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300 border border-blue-200/60 dark:border-blue-800/60">
                                        {{ $section->course?->code }}
                                    </span>
                                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">
                                        {{ $section->term }} &bull; Sec {{ $section->name }}
                                    </span>
                                </div>
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white" style="font-family: var(--font-display);">
                                    {{ $section->course?->title }}
                                </h3>
                            </div>

                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold {{ $result['response_count'] >= 5 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $result['response_count'] >= 5 ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                                {{ $result['response_count'] }} {{ Str::plural('response', $result['response_count']) }}
                            </span>
                        </div>

                        {{-- Instructor Row --}}
                        <div class="flex items-center gap-2.5 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/50 mb-5 border border-slate-100 dark:border-slate-800">
                            <div class="flex items-center justify-center w-7 h-7 rounded-lg bg-gradient-to-br from-blue-600 to-indigo-700 text-white font-bold text-xs">
                                {{ strtoupper(substr($primaryFaculty?->name ?? 'U', 0, 1)) }}
                            </div>
                            <div class="text-xs">
                                <span class="text-slate-500 dark:text-slate-400">Instructor:</span>
                                <strong class="text-slate-900 dark:text-slate-100 ml-1">{{ $primaryFaculty?->name ?? 'Unassigned' }}</strong>
                            </div>
                        </div>

                        {{-- Anonymity Threshold Suppression Check --}}
                        @if ($result['is_suppressed'])
                            <div class="rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-dashed border-amber-300 dark:border-amber-700 p-5 text-center my-2">
                                <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/50 dark:text-amber-400 mb-2">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </div>
                                <h4 class="text-sm font-bold text-amber-900 dark:text-amber-200">
                                    Results Suppressed (< 5 Submissions)
                                </h4>
                                <p class="text-xs text-amber-700/90 dark:text-amber-400/90 mt-1 max-w-xs mx-auto">
                                    To protect student anonymity, evaluation scorecards are hidden until at least 5 students complete the survey.
                                </p>
                            </div>
                        @else
                            {{-- Evaluation Metric Scores --}}
                            <div class="space-y-4">
                                @foreach ($result['questions_data'] as $q)
                                    <div class="p-3 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800/60">
                                        <div class="flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1.5">
                                            <span class="truncate pr-2">{{ $q['text'] }}</span>

                                            @if ($q['type'] === 'rating')
                                                <span class="font-bold text-blue-700 dark:text-blue-300 bg-blue-100 dark:bg-blue-950 px-2 py-0.5 rounded text-[11px] shrink-0">
                                                    {{ $q['average'] }} / 5.0 ⭐
                                                </span>
                                            @elseif ($q['type'] === 'yes_no')
                                                <span class="font-bold text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-950 px-2 py-0.5 rounded text-[11px] shrink-0">
                                                    {{ $q['percentage_yes'] }}% Yes
                                                </span>
                                            @else
                                                <span class="font-bold text-slate-600 dark:text-slate-400 text-[11px] shrink-0">
                                                    {{ $q['submission_count'] }} comments
                                                </span>
                                            @endif
                                        </div>

                                        @if ($q['type'] === 'rating')
                                            <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2 overflow-hidden">
                                                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 h-2 rounded-full transition-all duration-500" style="width: {{ $q['percentage'] }}%"></div>
                                            </div>
                                        @elseif ($q['type'] === 'yes_no')
                                            <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2 overflow-hidden">
                                                <div class="bg-gradient-to-r from-emerald-500 to-teal-500 h-2 rounded-full transition-all duration-500" style="width: {{ $q['percentage_yes'] }}%"></div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full p-12 text-center bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <p class="text-sm font-medium text-slate-500">No course sections found matching the selected filter criteria.</p>
                </div>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
