<script setup lang="ts">
import { Loader2, Sparkles, X } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<{
    label?: string;
    currentText?: string;
    channel?: string;
    action?: string;
    field: 'message' | 'body' | 'subject';
    emailSubject?: string;
}>();

const emit = defineEmits<{
    apply: [text: string];
}>();

const open = ref(false);
const mode = ref<'generate' | 'paraphrase'>('generate');
const context = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const preview = ref<string | null>(null);

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function runAi() {
    loading.value = true;
    error.value = null;
    preview.value = null;

    try {
        const res = await fetch('/outreach/ai/content', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                mode: mode.value,
                channel: props.channel ?? 'linkedin',
                action: props.action ?? 'send_message',
                field: props.field,
                context: context.value.trim(),
                current_text: props.currentText ?? '',
                email_subject: props.emailSubject ?? '',
            }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'AI request failed.');
        }
        preview.value = data.content as string;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'AI request failed.';
    } finally {
        loading.value = false;
    }
}

function usePreview() {
    if (preview.value) {
        emit('apply', preview.value);
        open.value = false;
        preview.value = null;
    }
}

function toggle() {
    open.value = !open.value;
    if (open.value) {
        error.value = null;
        preview.value = null;
        mode.value = (props.currentText ?? '').trim() ? 'paraphrase' : 'generate';
    }
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md border border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700 transition hover:bg-violet-100"
            @click="toggle"
        >
            <Sparkles class="h-3 w-3" />
            AI help
        </button>

        <div
            v-if="open"
            class="absolute right-0 top-full z-50 mt-1.5 w-[min(300px,calc(100vw-2rem))] rounded-xl border border-slate-200 bg-white p-3 shadow-lg"
        >
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-xs font-semibold text-slate-800">{{ label ?? 'AI writing assistant' }}</p>
                <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-100" @click="open = false">
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>

            <div class="mb-2 flex gap-1 rounded-lg bg-slate-100 p-0.5">
                <button
                    type="button"
                    class="flex-1 rounded-md px-2 py-1 text-[10px] font-medium transition"
                    :class="mode === 'generate' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                    @click="mode = 'generate'"
                >
                    Generate
                </button>
                <button
                    type="button"
                    class="flex-1 rounded-md px-2 py-1 text-[10px] font-medium transition"
                    :class="mode === 'paraphrase' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                    :disabled="!(currentText ?? '').trim()"
                    @click="mode = 'paraphrase'"
                >
                    Paraphrase
                </button>
            </div>

            <label v-if="mode === 'generate'" class="mb-2 block text-[10px] font-medium text-slate-500">
                What should this message be about?
            </label>
            <textarea
                v-if="mode === 'generate'"
                v-model="context"
                rows="3"
                placeholder="e.g. Follow up after LinkedIn connect, offer a free audit, book a demo…"
                class="mb-2 w-full resize-none rounded-lg border border-slate-200 px-2 py-1.5 text-xs outline-none focus:border-violet-400"
            />

            <p v-else class="mb-2 text-[10px] text-slate-500" v-pre>
                Rewrites your current text to sound clearer and more natural. Placeholders like {{firstName}} are kept.
            </p>

            <button
                type="button"
                class="mb-2 flex w-full items-center justify-center gap-1.5 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700 disabled:opacity-60"
                :disabled="loading || (mode === 'generate' && !context.trim() && !(currentText ?? '').trim())"
                @click="runAi"
            >
                <Loader2 v-if="loading" class="h-3.5 w-3.5 animate-spin" />
                <Sparkles v-else class="h-3.5 w-3.5" />
                {{ loading ? 'Working…' : mode === 'paraphrase' ? 'Paraphrase' : 'Generate' }}
            </button>

            <p v-if="error" class="mb-2 rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-[10px] text-red-700">{{ error }}</p>

            <div v-if="preview" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-2">
                <p class="mb-1 text-[10px] font-semibold uppercase text-emerald-800">Preview</p>
                <p class="max-h-32 overflow-y-auto whitespace-pre-wrap text-xs text-slate-800">{{ preview }}</p>
                <button
                    type="button"
                    class="mt-2 w-full rounded-lg border border-emerald-300 bg-white py-1 text-[10px] font-semibold text-emerald-800 hover:bg-emerald-50"
                    @click="usePreview"
                >
                    Use this text
                </button>
            </div>
        </div>
    </div>
</template>
