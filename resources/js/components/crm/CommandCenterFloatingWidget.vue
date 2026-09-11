<script setup lang="ts">
import { ExternalLink, GripVertical, Loader2, X } from '@lucide/vue';
import AlexAvatar from '@/components/crm/AlexAvatar.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CommandCenterChatPanel from '@/components/crm/CommandCenterChatPanel.vue';
import { Button } from '@/components/ui/button';
import { useCommandCenterChat } from '@/composables/useCommandCenterChat';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useDraggableWidget } from '@/composables/useDraggableWidget';

const OPEN_KEY = 'command-center-widget-open';

const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();
const chat = useCommandCenterChat();
const { position, dragging, onLauncherPointerDown: startDrag, didDrag, resetDragFlag } =
    useDraggableWidget(56);

const open = ref(loadOpenState());

const showWidget = computed(() => {
    const user = page.props.auth?.user as { current_organization_id?: number | null } | null | undefined;

    return Boolean(user?.current_organization_id) && !isCurrentOrParentUrl('/ai-employee');
});

function loadOpenState(): boolean {
    if (typeof window === 'undefined') return false;

    try {
        return localStorage.getItem(OPEN_KEY) === '1';
    } catch {
        return false;
    }
}

function persistOpenState(value: boolean) {
    try {
        localStorage.setItem(OPEN_KEY, value ? '1' : '0');
    } catch {
        // ignore
    }
}

watch(open, (value) => {
    persistOpenState(value);
    if (value) {
        chat.startPendingSync();
    } else {
        chat.stopPendingSync();
    }
});

async function toggleOpen() {
    if (didDrag()) {
        resetDragFlag();
        return;
    }

    open.value = !open.value;

    if (open.value) {
        await chat.bootstrap();
        await chat.scrollBottom();
    }
}

function closePanel() {
    open.value = false;
}

function handleLauncherPointerDown(event: PointerEvent) {
    startDrag(event);
}

watch(showWidget, (visible) => {
    if (!visible) {
        open.value = false;
        chat.stopPendingSync();
    } else if (open.value) {
        void chat.bootstrap(true);
        chat.startPendingSync();
    }
});

if (open.value && showWidget.value) {
    void chat.bootstrap();
    chat.startPendingSync();
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="showWidget"
            class="pointer-events-none fixed z-[120] flex flex-col items-end"
            :style="{
                right: `${position.right}px`,
                bottom: `${position.bottom}px`,
            }"
        >
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-2 opacity-0"
            >
                <div
                    v-if="open"
                    class="pointer-events-auto mb-3 flex max-h-[min(calc(100dvh-6rem),560px)] w-[min(100vw-2rem,380px)] flex-col overflow-hidden rounded-2xl border bg-background shadow-2xl ring-1 ring-black/5 dark:ring-white/10"
                >
                    <div class="flex items-center gap-2 border-b px-3 py-2.5">
                        <AlexAvatar size="md" online />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold">
                                {{ chat.settings.employee_name }}
                            </div>
                            <div class="text-muted-foreground truncate text-[11px]">
                                Command Center · same thread as WhatsApp
                            </div>
                        </div>
                        <Button size="icon" variant="ghost" class="size-8 shrink-0" as-child>
                            <Link href="/ai-employee" title="Open full Command Center">
                                <ExternalLink class="size-4" />
                            </Link>
                        </Button>
                        <Button size="icon" variant="ghost" class="size-8 shrink-0" @click="closePanel">
                            <X class="size-4" />
                        </Button>
                    </div>

                    <CommandCenterChatPanel :chat="chat" compact />
                </div>
            </Transition>

            <button
                type="button"
                class="cc-launcher pointer-events-auto group relative touch-none overflow-visible rounded-full ring-2 ring-white/80 transition hover:scale-[1.02] active:scale-[0.98] dark:ring-white/20"
                :class="[
                    dragging ? 'cursor-grabbing scale-[1.02]' : 'cursor-grab',
                    open ? 'cc-launcher--open' : 'cc-launcher--beep',
                ]"
                :aria-expanded="open"
                aria-label="Open Command Center chat"
                @pointerdown="handleLauncherPointerDown"
                @click="toggleOpen"
            >
                <span
                    v-if="!open"
                    class="cc-launcher-ring cc-launcher-ring--a pointer-events-none"
                    aria-hidden="true"
                />
                <span
                    v-if="!open"
                    class="cc-launcher-ring cc-launcher-ring--b pointer-events-none"
                    aria-hidden="true"
                />

                <span
                    class="absolute -right-1 top-1/2 z-10 flex -translate-y-1/2 items-center rounded-r-md bg-black/45 px-0.5 py-2 opacity-70 transition group-hover:opacity-100"
                    aria-hidden="true"
                >
                    <GripVertical class="size-3.5 text-white" />
                </span>

                <AlexAvatar size="launcher" :alt="chat.settings.employee_name" online />

                <span
                    v-if="open"
                    class="absolute inset-0 z-[1] flex items-center justify-center rounded-full bg-black/45 text-white"
                >
                    <X class="size-6" />
                </span>

                <span
                    v-if="(chat.awaitingReply || chat.sending) && !open"
                    class="absolute -left-0.5 -top-0.5 z-10 flex size-5 items-center justify-center rounded-full bg-sky-500 text-white ring-2 ring-background"
                    title="Soci is thinking…"
                >
                    <Loader2 class="size-3 animate-spin" />
                </span>

                <span
                    v-if="chat.pendingApprovalsCount > 0 && !open"
                    class="absolute -right-0.5 -top-0.5 z-10 flex size-5 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white ring-2 ring-background"
                >
                    {{ chat.pendingApprovalsCount > 9 ? '9+' : chat.pendingApprovalsCount }}
                </span>
            </button>
        </div>
    </Teleport>
</template>

<style scoped>
.cc-launcher {
    box-shadow:
        0 4px 14px rgb(15 23 42 / 0.18),
        0 10px 28px rgb(15 23 42 / 0.12);
}

.cc-launcher--open {
    box-shadow:
        0 6px 18px rgb(15 23 42 / 0.22),
        0 12px 32px rgb(15 23 42 / 0.14);
}

.cc-launcher-ring {
    position: absolute;
    inset: -2px;
    border-radius: 9999px;
    border: 2px solid rgb(14 165 233 / 0.45);
    opacity: 0;
}

.cc-launcher--beep .cc-launcher-ring--a {
    animation: cc-launcher-beep 2.6s ease-out infinite;
}

.cc-launcher--beep .cc-launcher-ring--b {
    animation: cc-launcher-beep 2.6s ease-out infinite 1.3s;
}

@keyframes cc-launcher-beep {
    0% {
        transform: scale(1);
        opacity: 0.55;
    }
    70% {
        transform: scale(1.55);
        opacity: 0;
    }
    100% {
        transform: scale(1.55);
        opacity: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .cc-launcher--beep .cc-launcher-ring--a,
    .cc-launcher--beep .cc-launcher-ring--b {
        animation: none;
        opacity: 0.35;
        transform: scale(1.12);
    }
}
</style>
