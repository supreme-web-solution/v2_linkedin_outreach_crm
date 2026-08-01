<script setup lang="ts">
import { Clock, ChevronLeft, GitBranch, Plus } from '@lucide/vue';
import { computed, inject, nextTick, onUnmounted, ref, watch, type ComputedRef } from 'vue';
import OutreachChannelIcon from '@/components/outreach/OutreachChannelIcon.vue';
import type { ConnectedChannel, OutreachChannel } from '@/components/outreach/types';

const props = defineProps<{
    menuId: string;
    afterKey: number;
    branch?: 'accepted' | 'not_accepted';
    conditionKey?: number;
    align?: 'center' | 'left' | 'right';
}>();

type AddContext = {
    connectedChannels: ComputedRef<ConnectedChannel[]>;
    channelRegistry: {
        channels: Record<string, { label: string; color: string }>;
        actions: Record<string, Array<{ key: string; label: string }>>;
        conditions?: Record<string, Array<{ key: string; label: string }>>;
    };
    addActionAfter: (afterKey: number, channel: OutreachChannel, actionKey: string, label: string) => void;
    addDelayAfter: (afterKey: number) => void;
    addConditionAfter: (afterKey: number, channel: OutreachChannel, conditionKey: string, label: string) => void;
    addIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted', channel: OutreachChannel, actionKey: string, label: string) => void;
    addDelayIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted') => void;
};

type MenuState = {
    openId: { value: string | null };
    toggle: (id: string) => void;
    close: () => void;
};

const ctx = inject<AddContext>('outreachAddContext')!;
const menuState = inject<MenuState>('outreachAddMenu')!;

const pickChannel = ref<OutreachChannel | null>(null);
const triggerRef = ref<HTMLElement | null>(null);
const menuAnchor = ref({ top: 0, left: 0, width: 0 });

const isOpen = computed(() => menuState.openId.value === props.menuId);

const connected = computed(() => ctx.connectedChannels.value);

const menuStyle = computed(() => {
    const { top, left, width } = menuAnchor.value;

    if (props.align === 'left') {
        return {
            top: `${top}px`,
            left: `${left}px`,
        };
    }

    if (props.align === 'right') {
        return {
            top: `${top}px`,
            left: `${left + width}px`,
            transform: 'translateX(-100%)',
        };
    }

    return {
        top: `${top}px`,
        left: `${left + width / 2}px`,
        transform: 'translateX(-50%)',
    };
});

function syncMenuPosition() {
    const el = triggerRef.value;
    if (!el) {
        return;
    }

    const rect = el.getBoundingClientRect();
    menuAnchor.value = {
        top: rect.bottom + 6,
        left: rect.left,
        width: rect.width,
    };
}

function open() {
    pickChannel.value = null;
    menuState.toggle(props.menuId);
}

function close() {
    pickChannel.value = null;
    menuState.close();
}

function pickAction(channel: OutreachChannel, actionKey: string, label: string) {
    if (props.branch && props.conditionKey !== undefined) {
        ctx.addIntoBranch(props.conditionKey, props.branch, channel, actionKey, label);
    } else {
        ctx.addActionAfter(props.afterKey, channel, actionKey, label);
    }
    close();
}

function pickDelay() {
    if (props.branch && props.conditionKey !== undefined) {
        ctx.addDelayIntoBranch(props.conditionKey, props.branch);
    } else {
        ctx.addDelayAfter(props.afterKey);
    }
    close();
}

function pickCondition(channel: OutreachChannel, conditionKey: string, label: string) {
    ctx.addConditionAfter(props.afterKey, channel, conditionKey, label);
    close();
}

watch(isOpen, async (open) => {
    if (open) {
        await nextTick();
        syncMenuPosition();
        window.addEventListener('resize', syncMenuPosition);
        window.addEventListener('scroll', syncMenuPosition, true);
    } else {
        window.removeEventListener('resize', syncMenuPosition);
        window.removeEventListener('scroll', syncMenuPosition, true);
    }
});

onUnmounted(() => {
    window.removeEventListener('resize', syncMenuPosition);
    window.removeEventListener('scroll', syncMenuPosition, true);
});
</script>

<template>
    <div
        ref="triggerRef"
        class="pointer-events-auto relative outreach-add-menu-trigger"
        :class="{ 'outreach-add-menu-open': isOpen }"
        @click.stop
    >
        <button
            type="button"
            class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-primary hover:bg-primary hover:text-white"
            title="Add step after"
            @click.stop="open"
        >
            <Plus class="h-3.5 w-3.5" />
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                class="outreach-add-menu-dropdown fixed z-[10050] w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-[0_16px_40px_rgba(15,23,42,0.18)]"
                :style="menuStyle"
                @click.stop
            >
                <p class="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ branch ? (branch === 'accepted' ? 'Add to Yes branch' : 'Add to No branch') : 'Add after this step' }}
                </p>

                <template v-if="!pickChannel">
                    <button
                        v-for="ch in connected"
                        :key="ch.channel"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs hover:bg-slate-50"
                        @click="pickChannel = ch.channel"
                    >
                        <OutreachChannelIcon :channel="ch.channel" class="h-4 w-4" />
                        {{ ctx.channelRegistry.channels[ch.channel]?.label ?? ch.label }}
                    </button>
                    <p v-if="connected.length === 0" class="px-2 py-2 text-xs text-amber-700">Connect platforms first.</p>
                    <div class="my-1 border-t border-slate-100" />
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs text-amber-800 hover:bg-amber-50"
                        @click="pickDelay"
                    >
                        <Clock class="h-3.5 w-3.5" /> Wait / Delay
                    </button>
                </template>

                <template v-else>
                    <button type="button" class="mb-1 flex items-center gap-1 px-2 text-[10px] text-slate-500 hover:underline" @click="pickChannel = null">
                        <ChevronLeft class="h-3 w-3" /> Platforms
                    </button>
                    <button
                        v-for="action in ctx.channelRegistry.actions[pickChannel] ?? []"
                        :key="action.key"
                        type="button"
                        class="flex w-full rounded-lg px-2 py-1.5 text-left text-xs hover:bg-slate-50"
                        @click="pickAction(pickChannel!, action.key, action.label)"
                    >
                        {{ action.label }}
                    </button>
                    <template v-if="!branch && (ctx.channelRegistry.conditions?.[pickChannel] ?? []).length">
                        <p class="px-2 pt-2 text-[10px] font-semibold uppercase text-slate-400">Conditions</p>
                        <button
                            v-for="cond in ctx.channelRegistry.conditions?.[pickChannel] ?? []"
                            :key="cond.key"
                            type="button"
                            class="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-left text-xs text-orange-700 hover:bg-orange-50"
                            @click="pickCondition(pickChannel!, cond.key, cond.label)"
                        >
                            <GitBranch class="h-3 w-3" /> {{ cond.label }}
                        </button>
                    </template>
                </template>
            </div>
        </Teleport>
    </div>
</template>
