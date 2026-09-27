<div class="grid gap-6 lg:grid-cols-3">
    <x-filament::section
        class="lg:col-span-1"
        :heading="__('attachment_batches.sections.items')"
        :description="__('attachment_batches.hints.seq_order')"
    >
        <ul wire:sort="sortItem" class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->batch->items as $item)
                <li
                    wire:key="attachment-batch-item-{{ $item->id }}"
                    wire:sort:item="{{ $item->id }}"
                    @class([
                        'flex items-center gap-3 px-2 py-2',
                        'rounded-lg bg-primary-50 dark:bg-primary-950' => $item->id === $selectedItemId,
                    ])
                >
                    <span wire:sort:handle class="cursor-move text-gray-400">
                        <x-filament::icon icon="heroicon-o-bars-3" class="h-5 w-5" />
                    </span>

                    <span class="font-mono text-xs text-gray-500">
                        {{ str_pad((string) $loop->iteration, max(3, strlen((string) $loop->count)), '0', STR_PAD_LEFT) }}
                    </span>

                    <x-filament::link
                        tag="button"
                        wire:click="selectItem('{{ $item->id }}')"
                        class="min-w-0 flex-1 truncate text-start"
                    >
                        {{ $item->attachment?->displayName() ?? '—' }}
                    </x-filament::link>

                    <x-filament::badge :color="$item->status->getColor()">
                        {{ $item->status->getLabel() }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <div class="space-y-6 lg:col-span-2">
        @if ($this->selectedItem !== null)
            @php($attachment = $this->selectedItem->attachment)
            @php($previewUrl = $attachment?->temporaryUrl())

            <x-filament::section :heading="__('attachment_batches.sections.preview')">
                @if ($previewUrl !== null && $attachment->isImage())
                    <img
                        src="{{ $previewUrl }}"
                        alt="{{ $attachment->displayName() }}"
                        class="max-h-[32rem] rounded-lg"
                    />
                @elseif ($previewUrl !== null && $attachment->isPdf())
                    <iframe
                        src="{{ $previewUrl }}"
                        title="{{ $attachment->displayName() }}"
                        class="h-[32rem] w-full rounded-lg"
                    ></iframe>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('attachment_batches.hints.preview_unavailable') }}
                    </p>
                @endif

                <div class="mt-4">
                    <x-filament::button
                        color="gray"
                        icon="heroicon-o-arrow-down-tray"
                        wire:click="downloadSelected"
                    >
                        {{ __('attachment_batches.actions.download') }}
                    </x-filament::button>
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('attachment_batches.sections.classify')">
                <form wire:submit="save" class="space-y-4">
                    {{ $this->form }}

                    <x-filament::button type="submit">
                        {{ __('attachment_batches.actions.save_classification') }}
                    </x-filament::button>
                </form>
            </x-filament::section>
        @else
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('attachment_batches.hints.select_item') }}
                </p>
            </x-filament::section>
        @endif
    </div>

    <x-filament-actions::modals />
</div>
