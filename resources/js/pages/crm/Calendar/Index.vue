<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    ExternalLink,
    GripVertical,
    Loader2,
    Phone,
    Send,
    Sparkles,
    Bell,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Calendar', href: '/calendar' },
        ],
    },
});

type CalendarEvent = {
    id: string;
    type: string;
    title: string;
    start: string;
    end: string | null;
    color: string;
    status: string | null;
    prospect_name: string | null;
    provider: string | null;
    href: string | null;
    meta: Record<string, unknown>;
};

const props = defineProps<{
    events: CalendarEvent[];
    month: string;
    hasOrg: boolean;
}>();

const localEvents = ref<CalendarEvent[]>([...props.events]);
const selectedEvent = ref<CalendarEvent | null>(null);
const detailOpen = ref(false);
const dragPayload = ref<{ type: string; recordId: number; start: string } | null>(null);
const dropTargetKey = ref<string | null>(null);
const reschedulingId = ref<string | null>(null);
const toast = ref<{ type: 'success' | 'error'; message: string } | null>(null);

const csrf = computed(
    () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
);

const colorClasses: Record<string, string> = {
    blue: 'bg-blue-500/15 text-blue-800 border-blue-500/35 dark:text-blue-200',
    sky: 'bg-sky-500/15 text-sky-800 border-sky-500/35 dark:text-sky-200',
    violet: 'bg-violet-500/15 text-violet-800 border-violet-500/35 dark:text-violet-200',
    amber: 'bg-amber-500/15 text-amber-900 border-amber-500/35 dark:text-amber-200',
};

const typeLabels: Record<string, string> = {
    call: 'Booked call',
    call_send: 'Scheduled message',
    content: 'Content post',
    reminder: 'Reminder',
};

const weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
});

const todayKey = computed(() => formatDayKey(new Date()));

function formatDayKey(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function parseRecordId(event: CalendarEvent): number {
    const [, id] = event.id.split(':');
    return Number(id);
}

function eventsForDay(dayKey: string): CalendarEvent[] {
    return localEvents.value.filter((event) => formatDayKey(new Date(event.start)) === dayKey);
}

const calendarWeeks = computed(() => {
    const [year, monthNum] = props.month.split('-').map(Number);
    const first = new Date(year, monthNum - 1, 1);
    const startOffset = (first.getDay() + 6) % 7;
    const gridStart = new Date(first);
    gridStart.setDate(first.getDate() - startOffset);

    const weeks: Array<Array<{ key: string; date: number; inMonth: boolean }>> = [];
    const cursor = new Date(gridStart);

    for (let w = 0; w < 6; w++) {
        const week: Array<{ key: string; date: number; inMonth: boolean }> = [];
        for (let d = 0; d < 7; d++) {
            week.push({
                key: formatDayKey(cursor),
                date: cursor.getDate(),
                inMonth: cursor.getMonth() === monthNum - 1,
            });
            cursor.setDate(cursor.getDate() + 1);
        }
        weeks.push(week);
    }

    return weeks;
});

const upcomingEvents = computed(() =>
    [...localEvents.value]
        .filter((e) => new Date(e.start) >= new Date())
        .sort((a, b) => a.start.localeCompare(b.start))
        .slice(0, 8),
);

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function goMonth(delta: number) {
    const [y, m] = props.month.split('-').map(Number);
    const next = new Date(y, m - 1 + delta, 1);
    const month = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
    router.get('/calendar', { month }, { preserveScroll: true, preserveState: false });
}

function openDetail(event: CalendarEvent) {
    selectedEvent.value = event;
    detailOpen.value = true;
}

function onDragStart(event: CalendarEvent, e: DragEvent) {
    dragPayload.value = {
        type: event.type,
        recordId: parseRecordId(event),
        start: event.start,
    };
    e.dataTransfer?.setData('text/plain', event.id);
    e.dataTransfer!.effectAllowed = 'move';
}

function onDragOver(dayKey: string, e: DragEvent) {
    e.preventDefault();
    dropTargetKey.value = dayKey;
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
}

function onDragLeave(dayKey: string) {
    if (dropTargetKey.value === dayKey) dropTargetKey.value = null;
}

function onDragEnd() {
    dragPayload.value = null;
    dropTargetKey.value = null;
}

async function onDrop(dayKey: string) {
    dropTargetKey.value = null;
    const payload = dragPayload.value;
    dragPayload.value = null;
    if (!payload) return;

    const original = new Date(payload.start);
    const [y, m, d] = dayKey.split('-').map(Number);
    const newStart = new Date(y, m - 1, d, original.getHours(), original.getMinutes(), 0, 0);

    if (formatDayKey(original) === dayKey) return;

    await reschedule(payload.type, payload.recordId, newStart.toISOString(), `${payload.type}:${payload.recordId}`);
}

async function reschedule(type: string, recordId: number, startIso: string, eventKey: string) {
    reschedulingId.value = eventKey;
    toast.value = null;

    try {
        const res = await fetch(`/calendar/events/${type}/${recordId}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf.value,
            },
            body: JSON.stringify({ start: startIso }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message ?? 'Could not reschedule.');

        const updated = data.event as CalendarEvent;
        const idx = localEvents.value.findIndex((e) => e.id === updated.id);
        if (idx >= 0) {
            localEvents.value[idx] = updated;
        } else {
            localEvents.value.push(updated);
        }

        if (selectedEvent.value?.id === updated.id) {
            selectedEvent.value = updated;
        }

        toast.value = { type: 'success', message: 'Event rescheduled.' };
    } catch (err) {
        toast.value = {
            type: 'error',
            message: err instanceof Error ? err.message : 'Could not reschedule.',
        };
    } finally {
        reschedulingId.value = null;
    }
}

function typeIcon(type: string) {
    if (type === 'call') return Phone;
    if (type === 'call_send') return Send;
    if (type === 'content') return Sparkles;
    return Bell;
}
</script>

<template>
    <Head title="Calendar" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Calendar</h1>
                <p class="text-sm text-muted-foreground">
                    Calls, scheduled messages, content posts, and reminders in one view. Drag events to another day to reschedule.
                </p>
            </div>
            <div class="flex items-center gap-1 rounded-lg border border-border bg-card p-1 shadow-sm">
                <button
                    type="button"
                    class="rounded-md p-2 hover:bg-muted"
                    aria-label="Previous month"
                    @click="goMonth(-1)"
                >
                    <ChevronLeft class="h-4 w-4" />
                </button>
                <span class="min-w-[10rem] px-2 text-center text-sm font-medium">{{ monthLabel }}</span>
                <button
                    type="button"
                    class="rounded-md p-2 hover:bg-muted"
                    aria-label="Next month"
                    @click="goMonth(1)"
                >
                    <ChevronRight class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div
            v-if="toast"
            class="rounded-lg border px-4 py-2 text-sm"
            :class="toast.type === 'success'
                ? 'border-green-500/30 bg-green-500/10 text-green-800 dark:text-green-300'
                : 'border-red-500/30 bg-red-500/10 text-red-800 dark:text-red-300'"
        >
            {{ toast.message }}
        </div>

        <div v-if="!hasOrg" class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-sm text-yellow-700 dark:text-yellow-400">
            Link your workspace through the extension first.
        </div>

        <template v-else>
            <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-500" /> Calls</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-sky-500" /> Message sends</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-violet-500" /> Content</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500" /> Reminders</span>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_280px]">
                <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div class="grid grid-cols-7 border-b border-border bg-muted/40 text-center text-xs font-medium text-muted-foreground">
                        <div v-for="label in weekdayLabels" :key="label" class="px-2 py-2">{{ label }}</div>
                    </div>

                    <div class="grid grid-cols-7">
                        <template v-for="(week, wi) in calendarWeeks" :key="wi">
                            <div
                                v-for="cell in week"
                                :key="cell.key"
                                class="min-h-[7.5rem] border-b border-r border-border p-1.5 transition-colors last:border-r-0"
                                :class="[
                                    !cell.inMonth && 'bg-muted/20 text-muted-foreground',
                                    cell.key === todayKey && 'ring-1 ring-inset ring-primary/40',
                                    dropTargetKey === cell.key && 'bg-primary/5',
                                ]"
                                @dragover="onDragOver(cell.key, $event)"
                                @dragleave="onDragLeave(cell.key)"
                                @drop.prevent="onDrop(cell.key)"
                            >
                                <div
                                    class="mb-1 flex items-center justify-between text-xs"
                                    :class="cell.key === todayKey ? 'font-semibold text-primary' : ''"
                                >
                                    <span>{{ cell.date }}</span>
                                </div>

                                <div class="flex flex-col gap-0.5">
                                    <button
                                        v-for="event in eventsForDay(cell.key).slice(0, 3)"
                                        :key="event.id"
                                        type="button"
                                        draggable="true"
                                        class="group flex w-full items-center gap-1 rounded border px-1 py-0.5 text-left text-[10px] leading-tight transition hover:opacity-90"
                                        :class="colorClasses[event.color] ?? colorClasses.blue"
                                        @click.stop="openDetail(event)"
                                        @dragstart="onDragStart(event, $event)"
                                        @dragend="onDragEnd"
                                    >
                                        <GripVertical class="h-3 w-3 shrink-0 opacity-40 group-hover:opacity-70" />
                                        <span class="truncate">{{ formatTime(event.start) }} {{ event.title }}</span>
                                        <Loader2
                                            v-if="reschedulingId === event.id"
                                            class="ml-auto h-3 w-3 shrink-0 animate-spin opacity-70"
                                        />
                                    </button>
                                    <p
                                        v-if="eventsForDay(cell.key).length > 3"
                                        class="px-1 text-[10px] text-muted-foreground"
                                    >
                                        +{{ eventsForDay(cell.key).length - 3 }} more
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <aside class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <div class="mb-3 flex items-center gap-2 text-sm font-semibold">
                        <CalendarDays class="h-4 w-4 text-primary" />
                        Upcoming
                    </div>
                    <ul v-if="upcomingEvents.length" class="space-y-2">
                        <li v-for="event in upcomingEvents" :key="event.id">
                            <button
                                type="button"
                                class="w-full rounded-lg border border-border px-3 py-2 text-left text-sm transition hover:bg-muted/50"
                                @click="openDetail(event)"
                            >
                                <div class="truncate font-medium">{{ event.title }}</div>
                                <div class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                    <Clock class="h-3 w-3" />
                                    {{ formatDateTime(event.start) }}
                                </div>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">No upcoming events this month.</p>
                </aside>
            </div>
        </template>
    </div>

    <Dialog v-model:open="detailOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader v-if="selectedEvent">
                <DialogTitle class="flex items-center gap-2">
                    <component :is="typeIcon(selectedEvent.type)" class="h-4 w-4 text-primary" />
                    {{ typeLabels[selectedEvent.type] ?? 'Event' }}
                </DialogTitle>
                <DialogDescription>{{ selectedEvent.title }}</DialogDescription>
            </DialogHeader>

            <div v-if="selectedEvent" class="space-y-3 text-sm">
                <div class="flex items-center gap-2 text-muted-foreground">
                    <Clock class="h-4 w-4 shrink-0" />
                    {{ formatDateTime(selectedEvent.start) }}
                </div>
                <div v-if="selectedEvent.prospect_name">
                    <span class="text-muted-foreground">Prospect:</span>
                    {{ selectedEvent.prospect_name }}
                </div>
                <div v-if="selectedEvent.status">
                    <span class="text-muted-foreground">Status:</span>
                    <span class="capitalize">{{ selectedEvent.status.replaceAll('_', ' ') }}</span>
                </div>
                <p
                    v-if="selectedEvent.meta?.preview || selectedEvent.meta?.message"
                    class="rounded-lg bg-muted/50 p-3 text-xs leading-relaxed text-muted-foreground"
                >
                    {{ selectedEvent.meta.preview ?? selectedEvent.meta.message }}
                </p>
            </div>

            <DialogFooter class="gap-2 sm:gap-0">
                <Link
                    v-if="selectedEvent?.href"
                    :href="selectedEvent.href"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Open
                    <ExternalLink class="h-3.5 w-3.5" />
                </Link>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
