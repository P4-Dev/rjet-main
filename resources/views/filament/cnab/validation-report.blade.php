@php
    /** @var \App\DTOs\CnabValidationReport|null $report */
    /** @var array<string, string> $suppliers */
    /** @var string|null $failure */
@endphp

<div class="space-y-4">
    @if ($failure !== null)
        <x-filament::section>
            <x-filament::badge color="danger">{{ $failure }}</x-filament::badge>
        </x-filament::section>
    @elseif ($report?->isValid())
        <x-filament::section>
            <x-filament::badge color="success">{{ __('cnab_files.messages.remittance_valid') }}</x-filament::badge>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('cnab_files.messages.remittance_errors', ['count' => $report->errorCount()]) }}
        </p>

        @if ($report->configErrors !== [])
            <x-filament::section :heading="__('cnab_files.sections.config_errors')">
                <ul class="list-disc space-y-1 ps-5 text-sm">
                    @foreach ($report->configErrors as $error)
                        <li>{{ $error->message() }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($report->itemErrors !== [])
            <x-filament::section :heading="__('cnab_files.sections.item_errors')">
                <ul class="space-y-2 text-sm">
                    @foreach ($report->itemErrors as $itemId => $errors)
                        <li>
                            <span class="font-medium">{{ $suppliers[$itemId] ?? $itemId }}</span>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($errors as $error)
                                    <x-filament::badge color="danger">{{ $error->message() }}</x-filament::badge>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($report->structuralErrors !== [])
            <x-filament::section :heading="__('cnab_files.sections.structural_errors')">
                <ul class="list-disc space-y-1 ps-5 text-sm">
                    @foreach ($report->structuralErrors as $error)
                        <li>{{ $error->message() }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</div>
