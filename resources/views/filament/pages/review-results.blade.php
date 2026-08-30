<x-filament-panels::page>
    {{ $this->form }}

    @if (! $review_window_id)
        <div class="p-8 text-center bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <x-heroicon-o-information-circle class="w-12 h-12 mx-auto text-gray-400 mb-3" />
            <h3 class="text-base font-semibold text-gray-700 dark:text-gray-200">No Review Window Selected</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Please select a review window above to view aggregated evaluation results.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-2">
            @forelse ($this->sections as $section)
                @php
                    $result = $this->getAggregatedResults($section->id);
                    $primaryFaculty = $section->facultyAssignments->firstWhere('is_primary', true)?->faculty;
                @endphp

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 flex flex-col justify-between">
                    <div>
                        {{-- Section Header --}}
                        <div class="flex items-start justify-between border-b border-gray-100 dark:border-gray-700 pb-4 mb-4">
                            <div>
                                <span class="text-xs font-semibold text-primary-600 dark:text-primary-400 uppercase tracking-wider">
                                    {{ $section->course?->code }} &bull; {{ $section->term }}
                                </span>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white mt-1">
                                    {{ $section->course?->title }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Section: <strong class="text-gray-700 dark:text-gray-200">{{ $section->name }}</strong>
                                </p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                {{ $result['response_count'] }} {{ Str::plural('response', $result['response_count']) }}
                            </span>
                        </div>

                        {{-- Faculty Info --}}
                        <div class="flex items-center text-sm text-gray-600 dark:text-gray-300 mb-5">
                            <x-heroicon-m-user class="w-4 h-4 mr-1.5 text-gray-400" />
                            <span>Instructor: <strong>{{ $primaryFaculty?->name ?? 'Unassigned' }}</strong></span>
                        </div>

                        {{-- Results Content --}}
                        @if ($result['is_suppressed'])
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-900/50 border border-dashed border-gray-200 dark:border-gray-700 p-5 text-center my-4">
                                <x-heroicon-o-lock-closed class="w-8 h-8 mx-auto text-gray-400 mb-2" />
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                    Results suppressed — fewer than 5 responses
                                </h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
                                    To protect student anonymity, evaluation metrics are only calculated and displayed when at least 5 reviews are submitted.
                                </p>
                            </div>
                        @else
                            <div class="space-y-4">
                                @foreach ($result['questions_data'] as $q)
                                    <div>
                                        <div class="flex justify-between text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            <span>{{ $q['text'] }}</span>
                                            @if ($q['type'] === 'rating')
                                                <span class="font-bold text-primary-600 dark:text-primary-400">{{ $q['average'] }} / 5.0</span>
                                            @elseif ($q['type'] === 'yes_no')
                                                <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $q['percentage_yes'] }}% Yes</span>
                                            @else
                                                <span class="font-bold text-gray-600 dark:text-gray-400">{{ $q['submission_count'] }} submitted</span>
                                            @endif
                                        </div>

                                        @if ($q['type'] === 'rating')
                                            <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                                <div class="bg-primary-600 h-2 rounded-full" style="width: {{ $q['percentage'] }}%"></div>
                                            </div>
                                        @elseif ($q['type'] === 'yes_no')
                                            <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                                <div class="bg-emerald-500 h-2 rounded-full" style="width: {{ $q['percentage_yes'] }}%"></div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full p-8 text-center bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-500">No sections found for the selected filter.</p>
                </div>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
