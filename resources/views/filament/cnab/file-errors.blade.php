@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\CnabFileItem> $items */
@endphp

<ul class="space-y-2 text-sm">
    @foreach ($items as $item)
        <li>
            <span class="font-medium">{{ $item->settlementItem?->paymentRequest?->supplier?->name ?? $item->reference }}</span>
            <div class="mt-1 flex flex-wrap gap-1">
                @foreach ($item->validation_errors ?? [] as $error)
                    <x-filament::badge color="danger">
                        {{ __('cnab_files.validation.'.($error['code'] ?? 'unknown'), $error['params'] ?? []) }}
                    </x-filament::badge>
                @endforeach
            </div>
        </li>
    @endforeach
</ul>
