<script setup lang="ts">
import { CheckCircle2, Download, FileSpreadsheet, Loader2, Upload } from '@lucide/vue';
import { ref } from 'vue';

export interface ImportedListOption {
    list_name: string;
    list_hash: string;
    total_leads: number;
    source: string;
    src: 'csv';
    type: string;
}

defineProps<{
    inModal?: boolean;
}>();

const emit = defineEmits<{
    imported: [list: ImportedListOption];
}>();

const ACCEPTED_EXTENSIONS = ['.csv', '.xlsx', '.xls', '.ods'];
const ACCEPT_ATTR = '.csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet';

const listName = ref('');
const importing = ref(false);
const error = ref<string | null>(null);
const message = ref<string | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);
const pendingFile = ref<File | null>(null);

function xsrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function defaultListNameFromFile(fileName: string): string {
    const base = fileName.replace(/\.[^.]+$/, '');
    return base || 'Imported contacts';
}

function validateExtension(file: File): boolean {
    const extension = file.name.includes('.') ? `.${file.name.split('.').pop()?.toLowerCase()}` : '';
    return ACCEPTED_EXTENSIONS.includes(extension);
}

async function uploadFile(file: File) {
    if (!validateExtension(file)) {
        error.value = 'Use CSV, Excel (.xlsx, .xls), or ODS.';
        return;
    }

    if (!listName.value.trim()) {
        listName.value = defaultListNameFromFile(file.name);
    }

    importing.value = true;
    error.value = null;
    message.value = null;
    pendingFile.value = file;

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
        pendingFile.value = null;
        emit('imported', data.list as ImportedListOption);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Import failed.';
    } finally {
        importing.value = false;
        if (fileInputRef.value) {
            fileInputRef.value.value = '';
        }
    }
}

function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) {
        void uploadFile(file);
    }
}

function onDrop(event: DragEvent) {
    dragOver.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) {
        void uploadFile(file);
    }
}

</script>

<template>
    <div class="space-y-4">
        <input
            v-model="listName"
            type="text"
            placeholder="List name (optional — uses file name if empty)"
            class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
        />

        <input
            ref="fileInputRef"
            type="file"
            :accept="ACCEPT_ATTR"
            class="hidden"
            @change="onFileSelected"
        />

        <button
            type="button"
            class="group relative flex w-full flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors"
            :class="dragOver
                ? 'border-blue-500 bg-blue-50/80 dark:bg-blue-950/30'
                : importing
                    ? 'border-blue-300 bg-blue-50/40'
                    : 'border-border bg-muted/20 hover:border-blue-400 hover:bg-blue-50/50 dark:hover:bg-blue-950/20'"
            :disabled="importing"
            @click="!importing && fileInputRef?.click()"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
        >
            <div
                class="mb-3 flex h-14 w-14 items-center justify-center rounded-full transition-colors"
                :class="dragOver || importing ? 'bg-blue-500/15 text-blue-600' : 'bg-muted text-muted-foreground group-hover:bg-blue-500/10 group-hover:text-blue-600'"
            >
                <Loader2 v-if="importing" class="h-7 w-7 animate-spin" />
                <Upload v-else class="h-7 w-7" />
            </div>

            <p class="text-sm font-semibold text-foreground">
                {{ importing ? 'Importing…' : 'Drop your spreadsheet here' }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                or <span class="font-medium text-blue-600">click to browse</span> · CSV, Excel, ODS
            </p>

            <div
                v-if="pendingFile && importing"
                class="mt-4 inline-flex max-w-full items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 py-1.5 text-xs dark:border-blue-800 dark:bg-card"
            >
                <FileSpreadsheet class="h-4 w-4 shrink-0 text-blue-600" />
                <span class="truncate">{{ pendingFile.name }}</span>
            </div>
        </button>

        <div class="flex flex-wrap items-center justify-center gap-2 text-xs text-muted-foreground">
            <span>Need a template?</span>
            <a
                href="/outreach/import-lists/template"
                download="outreach-contacts-template.csv"
                class="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1 font-medium text-foreground transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 dark:hover:bg-blue-950/30"
                @click.stop
            >
                <Download class="h-3.5 w-3.5" />
                CSV
            </a>
            <a
                href="/outreach/import-lists/template?format=xlsx"
                download="outreach-contacts-template.xlsx"
                class="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1 font-medium text-foreground transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 dark:hover:bg-blue-950/30"
                @click.stop
            >
                <Download class="h-3.5 w-3.5" />
                Excel
            </a>
        </div>

        <div class="rounded-lg border border-border bg-muted/20 px-3 py-2.5 text-xs text-muted-foreground">
            <p class="font-medium text-foreground">Phone format for WhatsApp</p>
            <p class="mt-1">
                Use international digits with country code — no <code class="rounded bg-muted px-1">+</code>, spaces, or leading <code class="rounded bg-muted px-1">0</code>.
                Nigeria example: <code class="rounded bg-muted px-1">2348085204156</code>
                (same as <code class="rounded bg-muted px-1">+234 808 520 4156</code>).
            </p>
            <p class="mt-1">In Excel, set the phone column to <strong>Text</strong> so digits are not changed.</p>
        </div>

        <details class="rounded-lg border border-border bg-muted/20 text-xs">
            <summary class="cursor-pointer select-none px-3 py-2 font-medium text-muted-foreground hover:text-foreground">
                Column guide (optional)
            </summary>
            <div class="space-y-2 border-t border-border px-3 py-2.5 text-muted-foreground">
                <p>
                    Include at least one of:
                    <code class="rounded bg-muted px-1">email</code>,
                    <code class="rounded bg-muted px-1">phone</code>,
                    <code class="rounded bg-muted px-1">linkedin_url</code>,
                    <code class="rounded bg-muted px-1">instagram</code>,
                    <code class="rounded bg-muted px-1">telegram</code>,
                    <code class="rounded bg-muted px-1">twitter</code>
                    per row. Optional: <code class="rounded bg-muted px-1">full_name</code>.
                </p>
            </div>
        </details>

        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ error }}</p>
        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            <CheckCircle2 class="h-4 w-4 shrink-0" />
            {{ message }}
        </p>
    </div>
</template>
