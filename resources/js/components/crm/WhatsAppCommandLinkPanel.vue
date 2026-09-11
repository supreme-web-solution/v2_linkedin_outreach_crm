<script setup lang="ts">
import { Check, ChevronDown, Copy, ExternalLink, Smartphone, Wifi } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';

export type WhatsAppCommandLink = {
    code: string;
    bot_number: string;
    deep_link: string | null;
    qr_svg?: string | null;
    instructions?: string;
    desktop_hint?: string;
    mobile_hint?: string;
};

const props = withDefaults(defineProps<{
    link: WhatsAppCommandLink;
    compact?: boolean;
    defaultOpen?: boolean;
}>(), {
    compact: false,
    defaultOpen: true,
});

const open = ref(props.defaultOpen);
const copied = ref<'number' | 'code' | null>(null);

const isMobile = computed(() =>
    /Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),
);

const hintText = computed(() =>
    isMobile.value
        ? (props.link.mobile_hint ?? 'Open WhatsApp on this phone and send the code below.')
        : (props.link.desktop_hint
            ?? 'Scan the QR or copy the number and code below from your phone\'s WhatsApp app.'),
);

async function copy(value: string, kind: 'number' | 'code'): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = kind;
        window.setTimeout(() => {
            if (copied.value === kind) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        // ignore clipboard failures
    }
}
</script>

<template>
    <Collapsible
        v-model:open="open"
        class="rounded-xl border border-emerald-200/80 bg-white shadow-sm dark:border-emerald-900/50 dark:bg-card"
    >
        <CollapsibleTrigger
            class="flex w-full items-center gap-2 px-3 py-2.5 text-left transition hover:bg-emerald-50/70 dark:hover:bg-emerald-950/20"
            :class="compact ? 'px-2.5 py-2' : 'px-3 py-2.5'"
        >
            <OutreachChannelIcon channel="whatsapp" :size="20" class="shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-emerald-950 dark:text-emerald-100">
                    Link from your phone
                </p>
                <p v-if="!open" class="text-muted-foreground truncate text-[11px]">
                    Tap to show QR code and steps
                </p>
            </div>
            <ChevronDown
                class="size-4 shrink-0 text-emerald-700 transition-transform duration-200 dark:text-emerald-300"
                :class="open ? 'rotate-180' : ''"
            />
        </CollapsibleTrigger>

        <CollapsibleContent class="overflow-visible border-t border-emerald-100 px-2 pb-2 pt-2 dark:border-emerald-900/40">
            <div class="mx-auto w-full max-w-[260px] pb-1">
                <div
                    class="rounded-[1.75rem] border-[3px] border-zinc-800 bg-zinc-900 p-1.5 pb-2 shadow-xl ring-1 ring-black/10 dark:border-zinc-700"
                >
                    <div class="relative mx-auto mb-1 h-3.5 w-16 rounded-full bg-zinc-800" />

                    <div class="rounded-[1.2rem] bg-[#ECE5DD] dark:bg-[#0B141A]">
                        <div class="flex items-center gap-2 rounded-t-[1.2rem] bg-[#075E54] px-2.5 py-1.5 text-white">
                            <div class="flex size-6 items-center justify-center rounded-full bg-white/15">
                                <OutreachChannelIcon channel="whatsapp" :size="14" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[11px] font-semibold">Soci</p>
                                <p class="truncate text-[9px] text-emerald-100/90">online</p>
                            </div>
                            <div class="flex items-center gap-1 text-emerald-100/80">
                                <Wifi class="size-2.5" />
                                <Smartphone class="size-2.5" />
                            </div>
                        </div>

                        <div class="space-y-2 px-2 py-2">
                            <div class="rounded-lg rounded-tl-none bg-white px-2.5 py-1.5 text-[10px] leading-snug text-zinc-800 shadow-sm dark:bg-[#1F2C34] dark:text-zinc-100">
                                {{ hintText }}
                            </div>

                            <div
                                v-if="!isMobile && link.qr_svg"
                                class="mx-auto flex max-w-[148px] flex-col items-center gap-1 rounded-lg bg-white p-2 shadow-sm dark:bg-[#1F2C34]"
                            >
                                <div
                                    class="[&>svg]:h-auto [&>svg]:max-w-[128px] [&>svg]:w-full"
                                    v-html="link.qr_svg"
                                />
                                <p class="flex items-center gap-1 text-[9px] font-medium text-[#075E54] dark:text-emerald-300">
                                    <Smartphone class="size-2.5" />
                                    Scan with your camera
                                </p>
                            </div>

                            <div class="space-y-1.5 rounded-lg bg-white/90 p-2 text-[10px] leading-snug text-zinc-800 shadow-sm dark:bg-[#1F2C34] dark:text-zinc-100">
                                <div class="flex gap-1.5">
                                    <span class="font-semibold text-[#075E54]">1.</span>
                                    <span>Open <strong>WhatsApp</strong> on your phone.</span>
                                </div>
                                <div class="flex gap-1.5">
                                    <span class="font-semibold text-[#075E54]">2.</span>
                                    <span class="min-w-0">
                                        Chat with
                                        <button
                                            type="button"
                                            class="ml-0.5 inline-flex max-w-full items-center gap-0.5 rounded bg-[#ECE5DD] px-1 py-0.5 font-mono text-[9px] font-semibold dark:bg-zinc-800"
                                            @click="copy(link.bot_number, 'number')"
                                        >
                                            <span class="truncate">{{ link.bot_number }}</span>
                                            <Check v-if="copied === 'number'" class="size-2.5 shrink-0 text-emerald-600" />
                                            <Copy v-else class="size-2.5 shrink-0 text-muted-foreground" />
                                        </button>
                                    </span>
                                </div>
                                <div class="flex gap-1.5">
                                    <span class="font-semibold text-[#075E54]">3.</span>
                                    <span class="min-w-0">
                                        Send
                                        <button
                                            type="button"
                                            class="ml-0.5 inline-flex items-center gap-0.5 rounded bg-[#ECE5DD] px-1 py-0.5 font-mono text-[9px] font-semibold dark:bg-zinc-800"
                                            @click="copy(link.code, 'code')"
                                        >
                                            {{ link.code }}
                                            <Check v-if="copied === 'code'" class="size-2.5 text-emerald-600" />
                                            <Copy v-else class="size-2.5 text-muted-foreground" />
                                        </button>
                                    </span>
                                </div>
                            </div>

                            <Button
                                v-if="isMobile && link.deep_link"
                                as="a"
                                :href="link.deep_link"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="h-8 w-full border-0 bg-[#25D366] text-[10px] text-white hover:bg-[#1da851]"
                                size="sm"
                            >
                                Open in WhatsApp
                                <ExternalLink class="ml-1 size-3" />
                            </Button>

                            <p
                                v-else-if="!isMobile && link.deep_link"
                                class="text-center text-[9px] leading-snug text-zinc-600 dark:text-zinc-400"
                            >
                                WhatsApp on this computer?
                                <a
                                    :href="link.deep_link"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium text-[#128C7E] underline"
                                >
                                    Open here
                                </a>
                            </p>
                        </div>

                        <div class="flex justify-center pb-1.5 pt-0.5">
                            <div class="h-1 w-10 rounded-full bg-zinc-400/70 dark:bg-zinc-600" />
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="link.instructions" class="text-muted-foreground mt-2 px-1 text-center text-[10px] leading-snug">
                {{ link.instructions }}
            </p>
        </CollapsibleContent>
    </Collapsible>
</template>
