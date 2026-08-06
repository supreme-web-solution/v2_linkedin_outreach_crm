<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    /** Separate dismiss key per surface so each page can show once. */
    storageKey: string;
    variant: 'campaigns' | 'outreach';
}>();

const open = ref(false);

onMounted(() => {
    if (typeof window === 'undefined') return;
    try {
        if (localStorage.getItem(props.storageKey) === '1') return;
        open.value = true;
    } catch {
        // Private mode / blocked storage — show once this session only.
        open.value = true;
    }
});

function dismiss() {
    open.value = false;
    try {
        localStorage.setItem(props.storageKey, '1');
    } catch {
        // ignore
    }
}

function onOpenChange(next: boolean) {
    if (!next) {
        dismiss();
        return;
    }
    open.value = true;
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-base">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-500/10 text-sky-700">
                        <ShieldCheck class="h-4 w-4" />
                    </span>
                    {{ variant === 'campaigns' ? 'How LinkedIn campaigns stay safe' : 'How outreach respects channel limits' }}
                </DialogTitle>
                <DialogDescription class="text-left text-sm leading-relaxed text-muted-foreground">
                    A quick note before you launch — so errors make sense when they appear.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm text-foreground/90">
                <p v-if="variant === 'campaigns'">
                    SociFusion sends invites and messages through
                    <strong class="font-medium">your LinkedIn account</strong>.
                    Daily/weekly caps and temporary pauses come from
                    <strong class="font-medium">LinkedIn</strong>
                    — not from SociFusion.
                </p>
                <p v-else>
                    SociFusion sends through
                    <strong class="font-medium">your connected accounts</strong>
                    (LinkedIn, email, WhatsApp, Instagram, Telegram, and so on).
                    Caps and temporary pauses come from
                    <strong class="font-medium">those platforms</strong>
                    (e.g. LinkedIn, Gmail, WhatsApp) — not from SociFusion.
                </p>

                <ul class="space-y-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[13px] leading-relaxed text-muted-foreground">
                    <li v-if="variant === 'campaigns'">
                        <span class="font-medium text-foreground">Invites &amp; messages</span>
                        — SociFusion applies your daily invite/message caps from settings. Anyone past the cap stays in the sequence and continues the next day — not dropped. LinkedIn also limits noted invites to about 5/day.
                    </li>
                    <li v-else>
                        <span class="font-medium text-foreground">Each channel has its own limits</span>
                        — LinkedIn, email, WhatsApp, etc. Hitting one does not block the others. LinkedIn invite/message caps queue leftovers for later; nothing is lost.
                    </li>
                    <li>
                        <span class="font-medium text-foreground">We pace sends on purpose</span>
                        — only a few leads run at once; the rest wait automatically to protect your account.
                    </li>
                    <li>
                        <span class="font-medium text-foreground">If you see Error or “try again later”</span>
                        — check the lead’s Logs. The same step would usually fail in that app too. We’ll retry when it’s safe.
                    </li>
                </ul>

                <p class="text-[13px] text-muted-foreground">
                    Tip: use invite notes sparingly (LinkedIn ~5 noted invites/day), space campaigns through the day, and clear old pending LinkedIn invites if you hit a wall.
                </p>
            </div>

            <DialogFooter>
                <button
                    type="button"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-gradient-to-b from-blue-500 to-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-blue-950/20 ring-1 ring-inset ring-white/15 hover:from-blue-500 hover:to-blue-700 sm:w-auto"
                    @click="dismiss"
                >
                    Got it
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
