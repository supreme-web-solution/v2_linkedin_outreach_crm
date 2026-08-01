import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type DailyEnrichmentQuota = {
    daily_limit: number;
    used: number;
    remaining: number;
    effective_remaining: number;
    in_flight: number;
    can_scrape: boolean;
    percent: number;
    reset_date: string | null;
};

const quota = ref<DailyEnrichmentQuota | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;
let pollSubscribers = 0;

export async function refreshDailyEnrichmentQuota() {
    try {
        const [dailyRes, pendingRes] = await Promise.all([
            fetch('/leads/daily-limit', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }),
            fetch('/leads/pending-count', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }),
        ]);

        if (!dailyRes.ok) {
            return;
        }

        const daily = await dailyRes.json();
        const pending = pendingRes.ok ? await pendingRes.json() : { pending_count: 0 };
        const inFlight = Number(pending.pending_count ?? 0);
        const used = Number(daily.used ?? 0);
        const limit = Number(daily.daily_limit ?? 0);
        const remaining = Number(daily.remaining ?? 0);
        const effectiveRemaining = limit <= 0 ? remaining : Math.max(0, remaining - inFlight);

        quota.value = {
            daily_limit: limit,
            used,
            remaining,
            effective_remaining: effectiveRemaining,
            in_flight: inFlight,
            can_scrape: Boolean(daily.can_scrape) && (limit <= 0 || effectiveRemaining > 0),
            percent: limit <= 0 ? 0 : Math.min(100, Math.round(((used + inFlight) / limit) * 100)),
            reset_date: daily.reset_date ?? null,
        };
    } catch {
        // ignore background poll failures
    }
}

function startPolling() {
    if (pollTimer) {
        return;
    }

    pollTimer = setInterval(() => {
        if (document.hidden) {
            return;
        }
        void refreshDailyEnrichmentQuota();
    }, 10000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

export function useDailyEnrichmentQuota() {
    const page = usePage();

    function syncFromPage() {
        const shared = page.props.dailyEnrichmentQuota as DailyEnrichmentQuota | null | undefined;
        if (shared) {
            quota.value = shared;
        }
    }

    watch(() => page.props.dailyEnrichmentQuota, syncFromPage);

    onMounted(() => {
        syncFromPage();
        pollSubscribers += 1;
        if (pollSubscribers === 1) {
            startPolling();
        }
    });

    onBeforeUnmount(() => {
        pollSubscribers = Math.max(0, pollSubscribers - 1);
        if (pollSubscribers === 0) {
            stopPolling();
        }
    });

    return {
        quota,
        refreshQuota: refreshDailyEnrichmentQuota,
    };
}
