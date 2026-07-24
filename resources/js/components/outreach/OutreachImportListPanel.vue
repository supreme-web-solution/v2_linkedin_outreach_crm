<script setup lang="ts">
import { Download, Loader2, Upload } from '@lucide/vue';
import { ref } from 'vue';

export interface ImportedListOption {
    list_name: string;
    list_hash: string;
    total_leads: number;
    source: string;
    src: 'csv';
    type: string;
}

const emit = defineEmits<{
    imported: [list: ImportedListOption];
}>();

const ACCEPTED_EXTENSIONS = ['.csv', '.xlsx', '.xls', '.ods'];

const listName = ref('');
const importing = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function defaultListNameFromFile(fileName: string): string {
    const base = fileName.replace(/\.[^.]+$/, '');

    return base || 'Imported contacts';
}

async function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const extension = file.name.includes('.') ? `.${file.name.split('.').pop()?.toLowerCase()}` : '';
    if (!ACCEPTED_EXTENSIONS.includes(extension)) {
        error.value = 'Please upload a CSV, Excel (.xlsx, .xls), or ODS spreadsheet.';
        input.value = '';
        return;
    }

    if (!listName.value.trim()) {
        listName.value = defaultListNameFromFile(file.name);
    }

    importing.value = true;
    error.value = null;
    message.value = null;

    try {
        const formData = new FormData();
        formData.append('name', listName.value.trim());
        formData.append('file', file);

        const res = await fetch('/outreach/import-lists', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: formData,
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'Import failed.');
        }

        message.value = data.message;
        listName.value = '';
        emit('imported', data.list as ImportedListOption);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Import failed.';
    } finally {
        importing.value = false;
        input.value = '';
    }
}
</script>

<template>
    <div class="rounded-xl border border-violet-200 bg-violet-50/40 p-4">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
            <div>
                <p class="text-sm font-semibold text-violet-950">Import your own contact list</p>
                <p class="mt-0.5 text-xs text-violet-900/80">
                    CSV, Excel, or ODS — WhatsApp, email, Instagram, etc. No LinkedIn list required.
                </p>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <a
                    href="/outreach/import-lists/template"
                    download="outreach-contacts-template.csv"
                    class="inline-flex items-center gap-1 rounded-lg border border-violet-300 bg-white px-2.5 py-1.5 text-xs font-medium text-violet-900 hover:bg-violet-50"
                >
                    <Download class="h-3.5 w-3.5" />
                    CSV template
                </a>
                <a
                    href="/outreach/import-lists/template?format=xlsx"
                    download="outreach-contacts-template.xlsx"
                    class="inline-flex items-center gap-1 rounded-lg border border-violet-300 bg-white px-2.5 py-1.5 text-xs font-medium text-violet-900 hover:bg-violet-50"
                >
                    <Download class="h-3.5 w-3.5" />
                    Excel template
                </a>
            </div>
        </div>

        <input
            v-model="listName"
            type="text"
            placeholder="List name (e.g. WhatsApp prospects)"
            class="mb-2 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm outline-none focus:border-primary"
        />

        <input
            ref="fileInputRef"
            type="file"
            accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet"
            class="hidden"
            @change="onFileSelected"
        />

        <button
            type="button"
            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-violet-600 px-3 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-60"
            :disabled="importing"
            @click="fileInputRef?.click()"
        >
            <Loader2 v-if="importing" class="h-4 w-4 animate-spin" />
            <Upload v-else class="h-4 w-4" />
            {{ importing ? 'Importing…' : 'Upload spreadsheet' }}
        </button>

        <p class="mt-2 text-[11px] leading-relaxed text-violet-900/70">
            Columns: <code class="rounded bg-white/80 px-1">full_name</code>,
            <code class="rounded bg-white/80 px-1">email</code>,
            <code class="rounded bg-white/80 px-1">phone</code>,
            <code class="rounded bg-white/80 px-1">linkedin_url</code>,
            <code class="rounded bg-white/80 px-1">instagram</code>,
            <code class="rounded bg-white/80 px-1">telegram</code>,
            <code class="rounded bg-white/80 px-1">twitter</code>
            — at least one contact field per row.
        </p>

        <p class="mt-1.5 text-[11px] leading-relaxed text-violet-900/70">
            <strong>Phone / WhatsApp:</strong> include the country code as digits only (no <code class="rounded bg-white/80 px-1">+</code>).
            Example: France <code class="rounded bg-white/80 px-1">33612345678</code>, US <code class="rounded bg-white/80 px-1">14155551234</code>.
            In Excel, format the phone column as Text so leading digits are not lost.
        </p>

        <p v-if="error" class="mt-2 rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs text-red-700">{{ error }}</p>
        <p v-if="message" class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1.5 text-xs text-emerald-800">{{ message }}</p>
    </div>
</template>
