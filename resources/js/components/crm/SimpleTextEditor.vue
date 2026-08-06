<script setup lang="ts">
import { Copy, List, Minus, Type } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: string;
    placeholder?: string;
    rows?: number;
    minHeightClass?: string;
    required?: boolean;
}>(), {
    placeholder: 'Write or paste your text…',
    rows: 10,
    minHeightClass: 'min-h-[220px]',
    required: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
    input: [];
}>();

const textareaRef = ref<HTMLTextAreaElement | null>(null);
const copied = ref(false);

const text = computed({
    get: () => props.modelValue ?? '',
    set: (value: string) => {
        emit('update:modelValue', value);
        emit('input');
    },
});

const wordCount = computed(() => {
    const trimmed = text.value.trim();
    return trimmed ? trimmed.split(/\s+/).length : 0;
});

function focus() {
    textareaRef.value?.focus();
}

function insertAtCursor(snippet: string, selectInserted = false) {
    const el = textareaRef.value;
    if (!el) {
        text.value = `${text.value}${snippet}`;
        return;
    }

    const start = el.selectionStart ?? text.value.length;
    const end = el.selectionEnd ?? start;
    const before = text.value.slice(0, start);
    const after = text.value.slice(end);
    text.value = `${before}${snippet}${after}`;

    requestAnimationFrame(() => {
        el.focus();
        const pos = start + snippet.length;
        if (selectInserted) {
            el.setSelectionRange(start, pos);
        } else {
            el.setSelectionRange(pos, pos);
        }
    });
}

function insertParagraphBreak() {
    const el = textareaRef.value;
    const start = el?.selectionStart ?? text.value.length;
    const needsLeading = start > 0 && text.value[start - 1] !== '\n';
    insertAtCursor(`${needsLeading ? '\n' : ''}\n`);
}

function insertBullet() {
    const el = textareaRef.value;
    const start = el?.selectionStart ?? text.value.length;
    const atLineStart = start === 0 || text.value[start - 1] === '\n';
    insertAtCursor(`${atLineStart ? '' : '\n'}• `);
}

async function copyAll() {
    if (!text.value.trim()) return;
    try {
        await navigator.clipboard.writeText(text.value);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 1500);
    } catch {
        // ignore
    }
}

defineExpose({ focus });
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-background shadow-sm">
        <div class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/30 px-2 py-1.5">
            <span class="mr-1 inline-flex items-center gap-1 px-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                <Type class="h-3 w-3" />
                Editor
            </span>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-foreground transition hover:bg-muted"
                title="Insert paragraph break"
                @click="insertParagraphBreak"
            >
                <Minus class="h-3.5 w-3.5" />
                New line
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-foreground transition hover:bg-muted"
                title="Insert bullet"
                @click="insertBullet"
            >
                <List class="h-3.5 w-3.5" />
                Bullet
            </button>
            <button
                type="button"
                class="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-foreground transition hover:bg-muted disabled:opacity-40"
                :disabled="!text.trim()"
                title="Copy all text"
                @click="copyAll"
            >
                <Copy class="h-3.5 w-3.5" />
                {{ copied ? 'Copied' : 'Copy' }}
            </button>
        </div>

        <textarea
            ref="textareaRef"
            v-model="text"
            :rows="rows"
            :required="required"
            :placeholder="placeholder"
            :class="[
                'w-full resize-y border-0 bg-transparent px-4 py-3 text-sm leading-relaxed outline-none focus:ring-0',
                'whitespace-pre-wrap break-words',
                minHeightClass,
            ]"
            spellcheck="true"
        />

        <div class="flex items-center justify-between border-t border-border bg-muted/20 px-3 py-1.5 text-[11px] text-muted-foreground">
            <span>Plain text with line breaks — ready to copy into LinkedIn or email.</span>
            <span class="tabular-nums">{{ wordCount }} words</span>
        </div>
    </div>
</template>
