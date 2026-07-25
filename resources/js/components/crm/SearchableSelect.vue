<script setup lang="ts">
import { Check, ChevronDown, Search, X } from '@lucide/vue';
import AppSelectionCheckbox from '@/components/AppSelectionCheckbox.vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

export interface SearchableSelectOption {
    value: string;
    label: string;
    sublabel?: string;
}

const props = withDefaults(defineProps<{
    options: SearchableSelectOption[];
    multiple?: boolean;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    disabled?: boolean;
    allowClear?: boolean;
    clearLabel?: string;
    panelMaxHeightClass?: string;
}>(), {
    multiple: false,
    placeholder: 'Select…',
    searchPlaceholder: 'Search…',
    emptyText: 'No matches',
    disabled: false,
    allowClear: false,
    clearLabel: 'Clear selection',
    panelMaxHeightClass: 'max-h-52',
});

const model = defineModel<string | string[]>({ default: '' });

const open = ref(false);
const search = ref('');
const rootRef = ref<HTMLElement | null>(null);

function openDropdown(event: MouseEvent) {
    event.stopPropagation();
    if (props.disabled) return;
    open.value = !open.value;
}

function isInsideSelect(target: EventTarget | null) {
    if (!(target instanceof Node)) return false;
    return Boolean(rootRef.value?.contains(target));
}

watch(open, (isOpen) => {
    if (!isOpen) search.value = '';
});

const selectedValues = computed<string[]>(() => {
    if (props.multiple) {
        return Array.isArray(model.value) ? model.value : [];
    }
    const value = String(model.value ?? '');
    return value !== '' ? [value] : [];
});

const filteredOptions = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.options;

    return props.options.filter((option) => {
        const haystack = `${option.label} ${option.sublabel ?? ''}`.toLowerCase();
        return haystack.includes(query);
    });
});

const selectedOptions = computed(() =>
    selectedValues.value
        .map((value) => props.options.find((option) => option.value === value))
        .filter((option): option is SearchableSelectOption => Boolean(option)),
);

const triggerLabel = computed(() => {
    if (props.multiple) {
        if (!selectedOptions.value.length) return props.placeholder;
        if (selectedOptions.value.length === 1) return selectedOptions.value[0].label;
        return `${selectedOptions.value.length} selected`;
    }

    const selected = selectedOptions.value[0];
    return selected?.label ?? props.placeholder;
});

function isSelected(value: string) {
    return selectedValues.value.includes(value);
}

function toggleOption(value: string, event?: MouseEvent) {
    event?.stopPropagation();

    if (props.multiple) {
        const next = new Set(selectedValues.value);
        if (next.has(value)) next.delete(value);
        else next.add(value);
        model.value = [...next];
        return;
    }

    model.value = value;
    open.value = false;
    search.value = '';
}

function removeValue(value: string, event: MouseEvent) {
    event.stopPropagation();
    if (!props.multiple) return;
    model.value = selectedValues.value.filter((item) => item !== value);
}

function clearSelection(event: MouseEvent) {
    event.stopPropagation();
    model.value = props.multiple ? [] : '';
    open.value = false;
    search.value = '';
}

function onDocumentClick(event: MouseEvent) {
    if (!open.value || isInsideSelect(event.target)) {
        return;
    }
    open.value = false;
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onUnmounted(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div ref="rootRef" data-searchable-select-root class="relative z-50 w-full min-w-0">
        <div v-if="multiple && selectedOptions.length" class="mb-2 flex flex-wrap gap-1.5">
            <span
                v-for="option in selectedOptions"
                :key="option.value"
                class="inline-flex max-w-full items-center gap-1 rounded-full border border-primary/20 bg-primary/10 py-0.5 pl-2.5 pr-1 text-xs font-medium text-primary"
            >
                <span class="max-w-48 truncate">{{ option.label }}</span>
                <button
                    type="button"
                    class="rounded-full p-0.5 hover:bg-primary/20"
                    :aria-label="`Remove ${option.label}`"
                    @click="removeValue(option.value, $event)"
                >
                    <X class="h-3 w-3" />
                </button>
            </span>
        </div>

        <button
            type="button"
            class="flex w-full min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-3 py-2 text-left text-sm transition-colors hover:bg-muted/30 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="disabled"
            @click="openDropdown"
        >
            <span class="min-w-0 flex-1 truncate" :class="selectedOptions.length ? 'text-foreground' : 'text-muted-foreground'">
                {{ triggerLabel }}
            </span>
            <ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground transition-transform" :class="open ? 'rotate-180' : ''" />
        </button>

        <div
            v-if="open"
            data-searchable-select-panel
            class="absolute left-0 right-0 top-full z-[100] mt-1 overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg"
            @click.stop
        >
            <div class="border-b border-border p-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="searchPlaceholder"
                        class="w-full rounded-md border border-border bg-background py-2 pl-9 pr-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        @keydown.stop
                        @click.stop
                    />
                </div>
            </div>

            <button
                v-if="allowClear && selectedOptions.length"
                type="button"
                class="w-full border-b border-border px-3 py-2 text-left text-xs text-muted-foreground hover:bg-muted/40"
                @click="clearSelection($event)"
            >
                {{ clearLabel }}
            </button>

            <ul class="overflow-y-auto py-1" :class="panelMaxHeightClass">
                <li v-if="!filteredOptions.length" class="px-3 py-6 text-center text-xs text-muted-foreground">
                    {{ emptyText }}
                </li>
                <li v-for="option in filteredOptions" :key="option.value">
                    <button
                        type="button"
                        class="flex w-full min-w-0 items-start gap-2 px-3 py-2 text-left text-sm hover:bg-muted/40"
                        :class="isSelected(option.value) ? 'bg-primary/5' : ''"
                        @click="toggleOption(option.value, $event)"
                    >
                        <span class="mt-0.5 shrink-0 pointer-events-none">
                            <AppSelectionCheckbox v-if="multiple" :checked="isSelected(option.value)" size="sm" />
                            <Check v-else-if="isSelected(option.value)" class="h-4 w-4 text-primary" />
                            <span v-else class="inline-block h-4 w-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ option.label }}</span>
                            <span v-if="option.sublabel" class="mt-0.5 block truncate text-xs text-muted-foreground">{{ option.sublabel }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
