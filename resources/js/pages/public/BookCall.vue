<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Calendar,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Loader2,
    Mail,
    Video,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({ layout: null });

const TIMES_PER_PAGE = 6;

const props = defineProps<{
    booked: boolean;
    token?: string;
    hostName: string;
    prospectName: string | null;
    scheduledAt?: string | null;
    durationMinutes?: number;
    slots: Array<{ start: string; end: string; label: string }>;
}>();

const page = usePage();
const appName = computed(() => (page.props.name as string) || 'Socifusion');
const flashSuccess = computed(() => (page.props.flash as { success?: string })?.success);
const flashError = computed(() => (page.props.flash as { error?: string })?.error);

const step = ref(1);
const selectedDayIndex = ref(0);
const timePage = ref(0);

const form = useForm({
    slot_start: '',
    prospect_email: '',
});

const emailError = computed(() => form.errors.prospect_email as string | undefined);

interface DayGroup {
    dateKey: string;
    weekday: string;
    monthDay: string;
    slots: Array<{ start: string; end: string; label: string; timeLabel: string }>;
}

const dayGroups = computed((): DayGroup[] => {
    const map = new Map<string, DayGroup>();

    for (const slot of props.slots) {
        const d = new Date(slot.start);
        const dateKey = d.toISOString().slice(0, 10);

        if (!map.has(dateKey)) {
            map.set(dateKey, {
                dateKey,
                weekday: d.toLocaleDateString(undefined, { weekday: 'short' }),
                monthDay: d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
                slots: [],
            });
        }

        map.get(dateKey)!.slots.push({
            ...slot,
            timeLabel: d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
        });
    }

    return Array.from(map.values()).sort((a, b) => a.dateKey.localeCompare(b.dateKey));
});

const selectedDay = computed(() => dayGroups.value[selectedDayIndex.value] ?? null);

const paginatedTimes = computed(() => {
    if (!selectedDay.value) {
        return [];
    }

    const start = timePage.value * TIMES_PER_PAGE;

    return selectedDay.value.slots.slice(start, start + TIMES_PER_PAGE);
});

const totalTimePages = computed(() => {
    if (!selectedDay.value) {
        return 0;
    }

    return Math.max(1, Math.ceil(selectedDay.value.slots.length / TIMES_PER_PAGE));
});

const hasMultipleDays = computed(() => dayGroups.value.length > 1);
const hasMultipleTimePages = computed(() => totalTimePages.value > 1);

watch(selectedDayIndex, () => {
    timePage.value = 0;
});

function continueToCalendar() {
    const email = form.prospect_email.trim();

    if (!email) {
        form.setError('prospect_email', 'Enter your email to receive the calendar invite.');
        return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        form.setError('prospect_email', 'Enter a valid email address.');
        return;
    }

    form.clearErrors('prospect_email');
    step.value = 2;
    selectedDayIndex.value = 0;
    timePage.value = 0;
}

function backToEmail() {
    step.value = 1;
}

function prevDay() {
    if (selectedDayIndex.value > 0) {
        selectedDayIndex.value -= 1;
    }
}

function nextDay() {
    if (selectedDayIndex.value < dayGroups.value.length - 1) {
        selectedDayIndex.value += 1;
    }
}

function prevTimePage() {
    if (timePage.value > 0) {
        timePage.value -= 1;
    }
}

function nextTimePage() {
    if (timePage.value < totalTimePages.value - 1) {
        timePage.value += 1;
    }
}

function bookSlot(start: string) {
    form.clearErrors('prospect_email');
    form.slot_start = start;
    form.post(`/book/${props.token}`, { preserveScroll: true });
}

function formatBookedTime(at: string | null | undefined) {
    if (!at) {
        return '';
    }

    try {
        return new Date(at).toLocaleString(undefined, {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return at;
    }
}

function onEmailKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter') {
        event.preventDefault();
        continueToCalendar();
    }
}
</script>

<template>
    <Head>
        <title>{{ booked ? `Call confirmed with ${hostName}` : `Book a call with ${hostName}` }}</title>
        <meta
            head-key="description"
            name="description"
            :content="booked
                ? `Your call with ${hostName} is confirmed.`
                : (prospectName
                    ? `Hi ${prospectName} — pick a time for a ${durationMinutes ?? 30}-minute call with ${hostName}.`
                    : `Pick a time for a ${durationMinutes ?? 30}-minute video call with ${hostName}.`)"
        />
    </Head>

    <div class="relative flex min-h-svh flex-col items-center justify-center bg-slate-50 p-4 sm:p-6 md:p-10 dark:bg-slate-950">
        <!-- Background -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -top-40 -left-32 h-96 w-96 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 opacity-25 blur-3xl dark:opacity-20" />
            <div class="absolute top-1/2 -right-32 h-[26rem] w-[26rem] -translate-y-1/2 rounded-full bg-gradient-to-tr from-sky-400 to-blue-600 opacity-20 blur-3xl dark:opacity-15" />
            <div class="absolute -bottom-40 left-1/4 h-80 w-80 rounded-full bg-gradient-to-br from-indigo-400 to-blue-500 opacity-15 blur-3xl dark:opacity-10" />
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#8080800a_1px,transparent_1px),linear-gradient(to_bottom,#8080800a_1px,transparent_1px)] bg-[size:32px_32px]" />
        </div>

        <div class="relative z-10 w-full max-w-lg">
            <!-- Brand -->
            <div class="mb-6 flex flex-col items-center gap-2">
                <div class="h-12 w-12 overflow-hidden rounded-2xl shadow-lg shadow-blue-600/25 ring-1 ring-inset ring-white/25">
                    <img src="/images/brand/app-logo.png" alt="" class="h-full w-full object-cover" />
                </div>
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ appName }}</p>
            </div>

            <div class="rounded-2xl border border-black/5 bg-white/90 shadow-xl shadow-slate-900/[0.06] backdrop-blur-xl dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/20">
                <!-- Header -->
                <div class="border-b border-border/60 px-6 py-5 sm:px-8">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-sm">
                            <Video class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Schedule a call</p>
                            <h1 class="truncate text-lg font-semibold text-foreground">{{ hostName }}</h1>
                            <p v-if="prospectName && !booked" class="mt-0.5 text-sm text-muted-foreground">
                                Hi {{ prospectName }} — let's find a time that works.
                            </p>
                            <p v-if="durationMinutes && !booked" class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-muted/60 px-2.5 py-0.5 text-xs text-muted-foreground">
                                <Clock class="h-3 w-3" />
                                {{ durationMinutes }}-minute call
                            </p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-6 sm:px-8">
                    <!-- Success -->
                    <div v-if="flashSuccess || booked" class="text-center">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600">
                            <CheckCircle2 class="h-7 w-7" />
                        </div>
                        <h2 class="text-lg font-semibold text-foreground">{{ flashSuccess || "You're all set!" }}</h2>
                        <p v-if="scheduledAt" class="mt-2 text-sm font-medium text-foreground">
                            {{ formatBookedTime(scheduledAt) }}
                        </p>
                        <p class="mt-3 text-sm text-muted-foreground">
                            A calendar invite has been sent to your email.
                        </p>
                    </div>

                    <!-- Booking flow -->
                    <template v-else>
                        <!-- Step indicator -->
                        <div class="mb-6 flex items-center justify-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors"
                                :class="step === 1 ? 'bg-primary/10 text-primary' : 'text-muted-foreground'"
                            >
                                <span
                                    class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]"
                                    :class="step === 1 ? 'bg-primary text-primary-foreground' : step > 1 ? 'bg-emerald-500 text-white' : 'bg-muted'"
                                >
                                    <CheckCircle2 v-if="step > 1" class="h-3 w-3" />
                                    <span v-else>1</span>
                                </span>
                                Your email
                            </span>
                            <ChevronRight class="h-3.5 w-3.5 text-muted-foreground" />
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors"
                                :class="step === 2 ? 'bg-primary/10 text-primary' : 'text-muted-foreground'"
                            >
                                <span
                                    class="flex h-4 w-4 items-center justify-center rounded-full text-[10px]"
                                    :class="step === 2 ? 'bg-primary text-primary-foreground' : 'bg-muted'"
                                >
                                    2
                                </span>
                                Pick a time
                            </span>
                        </div>

                        <p v-if="flashError" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-300">
                            {{ flashError }}
                        </p>

                        <!-- Step 1: Email -->
                        <div v-if="step === 1" class="space-y-5">
                            <div class="rounded-xl border border-border/60 bg-muted/20 p-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600">
                                        <Mail class="h-4 w-4" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-foreground">Where should we send your invite?</p>
                                        <p class="mt-0.5 text-xs text-muted-foreground">
                                            We'll email you a calendar event for this call.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label for="prospect_email">Email address</Label>
                                <Input
                                    id="prospect_email"
                                    v-model="form.prospect_email"
                                    type="email"
                                    required
                                    autofocus
                                    autocomplete="email"
                                    placeholder="you@company.com"
                                    :aria-invalid="!!emailError"
                                    @keydown="onEmailKeydown"
                                />
                                <p v-if="emailError" class="text-xs text-red-600">{{ emailError }}</p>
                            </div>

                            <Button
                                type="button"
                                class="w-full bg-gradient-to-b from-blue-500 to-blue-600 shadow-sm"
                                :disabled="!form.prospect_email.trim()"
                                @click="continueToCalendar"
                            >
                                Continue
                                <ArrowRight class="ml-1 h-4 w-4" />
                            </Button>
                        </div>

                        <!-- Step 2: Calendar -->
                        <div v-else class="space-y-5">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground transition hover:text-foreground"
                                @click="backToEmail"
                            >
                                <ArrowLeft class="h-3.5 w-3.5" />
                                Change email
                                <span class="text-foreground/70">({{ form.prospect_email }})</span>
                            </button>

                            <div v-if="dayGroups.length === 0" class="rounded-xl border border-dashed border-border px-4 py-8 text-center">
                                <Calendar class="mx-auto mb-3 h-8 w-8 text-muted-foreground/50" />
                                <p class="text-sm font-medium text-foreground">No open slots right now</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Please reply in chat to arrange a time with {{ hostName }}.
                                </p>
                            </div>

                            <template v-else>
                                <!-- Day selector -->
                                <div class="rounded-xl border border-border/60 bg-muted/20 p-3">
                                    <div class="mb-2 flex items-center justify-between">
                                        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Select a day</p>
                                        <div v-if="hasMultipleDays" class="flex items-center gap-1">
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-border/60 bg-background text-muted-foreground transition hover:bg-muted disabled:opacity-40"
                                                :disabled="selectedDayIndex === 0"
                                                aria-label="Previous day"
                                                @click="prevDay"
                                            >
                                                <ChevronLeft class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-border/60 bg-background text-muted-foreground transition hover:bg-muted disabled:opacity-40"
                                                :disabled="selectedDayIndex >= dayGroups.length - 1"
                                                aria-label="Next day"
                                                @click="nextDay"
                                            >
                                                <ChevronRight class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <div class="flex gap-2 overflow-x-auto pb-1">
                                        <button
                                            v-for="(day, index) in dayGroups"
                                            :key="day.dateKey"
                                            type="button"
                                            class="flex min-w-[4.5rem] shrink-0 flex-col items-center rounded-lg border px-3 py-2 text-center transition"
                                            :class="
                                                index === selectedDayIndex
                                                    ? 'border-primary bg-primary/10 text-primary shadow-sm'
                                                    : 'border-border/60 bg-background text-muted-foreground hover:border-primary/40 hover:bg-primary/5'
                                            "
                                            @click="selectedDayIndex = index"
                                        >
                                            <span class="text-[10px] font-medium uppercase">{{ day.weekday }}</span>
                                            <span class="text-sm font-semibold">{{ day.monthDay }}</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Time slots -->
                                <div v-if="selectedDay">
                                    <div class="mb-3 flex items-center justify-between">
                                        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Available times
                                        </p>
                                        <div v-if="hasMultipleTimePages" class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-border/60 bg-background text-muted-foreground transition hover:bg-muted disabled:opacity-40"
                                                :disabled="timePage === 0"
                                                aria-label="Previous times"
                                                @click="prevTimePage"
                                            >
                                                <ChevronLeft class="h-4 w-4" />
                                            </button>
                                            <span class="text-xs tabular-nums text-muted-foreground">
                                                {{ timePage + 1 }} / {{ totalTimePages }}
                                            </span>
                                            <button
                                                type="button"
                                                class="flex h-7 w-7 items-center justify-center rounded-md border border-border/60 bg-background text-muted-foreground transition hover:bg-muted disabled:opacity-40"
                                                :disabled="timePage >= totalTimePages - 1"
                                                aria-label="Next times"
                                                @click="nextTimePage"
                                            >
                                                <ChevronRight class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        <button
                                            v-for="slot in paginatedTimes"
                                            :key="slot.start"
                                            type="button"
                                            class="relative flex items-center justify-center rounded-lg border px-3 py-3 text-sm font-medium transition disabled:opacity-50"
                                            :class="
                                                form.processing && form.slot_start === slot.start
                                                    ? 'border-primary bg-primary/10 text-primary'
                                                    : 'border-border/60 bg-background text-foreground hover:border-primary hover:bg-primary/5'
                                            "
                                            :disabled="form.processing"
                                            @click="bookSlot(slot.start)"
                                        >
                                            <Loader2
                                                v-if="form.processing && form.slot_start === slot.start"
                                                class="absolute right-2 h-4 w-4 animate-spin text-primary"
                                            />
                                            {{ slot.timeLabel }}
                                        </button>
                                    </div>

                                    <p v-if="selectedDay.slots.length > TIMES_PER_PAGE" class="mt-3 text-center text-xs text-muted-foreground">
                                        Showing {{ paginatedTimes.length }} of {{ selectedDay.slots.length }} slots this day
                                    </p>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                Secure scheduling powered by {{ appName }}
            </p>
        </div>
    </div>
</template>
