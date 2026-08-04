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
                <p>
                    SociFusion sends actions through
                    <strong class="font-medium">your connected account</strong>
                    {{ variant === 'campaigns' ? ' (LinkedIn)' : ' (LinkedIn, email, WhatsApp, and others)' }}.
                    Limits and temporary pauses come from
                    <strong class="font-medium">those providers</strong>
                    — not from a shared SociFusion pool.
                </p>

                <ul class="space-y-2 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[13px] leading-relaxed text-muted-foreground">
                    <li v-if="variant === 'campaigns'">
                        <span class="font-medium text-foreground">Invites &amp; messages</span>
                        — LinkedIn caps how many you can send (often weekly, and fewer when you add a note on free accounts). Sending too fast can also trigger a short “try again later” cool-down.
                    </li>
                    <li v-else>
                        <span class="font-medium text-foreground">Each channel has its own rules</span>
                        — LinkedIn invites/messages, email deliverability, and messaging apps all limit volume. Hitting one channel does not mean your whole workspace is blocked.
                    </li>
                    <li>
                        <span class="font-medium text-foreground">We pace on purpose</span>
                        — only a few leads run at once and the rest wait automatically. That protects
                        <em>your</em> account health.
                    </li>
                    <li>
                        <span class="font-medium text-foreground">If a step shows Error or “try again later”</span>
                        — check the lead’s Logs. The same action would usually fail in LinkedIn (or that channel’s app) too. We’ll retry temporary limits when it’s safe.
                    </li>
                </ul>

                <p class="text-[13px] text-muted-foreground">
                    Tip: keep personalized LinkedIn invite notes for high-value leads, space campaigns across the day, and clear old pending invites in LinkedIn if you hit a wall.
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
