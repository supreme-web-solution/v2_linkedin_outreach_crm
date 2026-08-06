<script setup lang="ts">
import { Clock, GitBranch, Plus } from '@lucide/vue';
import { computed, inject, nextTick, onUnmounted, ref, watch } from 'vue';
import CampaignActionIcon from '@/components/campaign/CampaignActionIcon.vue';
import { CAMPAIGN_ACTIONS, CAMPAIGN_CONDITIONS } from '@/components/campaign/types';

const props = defineProps<{
    menuId: string;
    afterKey: number;
    branch?: 'accepted' | 'not_accepted';
    conditionKey?: number;
    align?: 'center' | 'left' | 'right';
}>();

type AddContext = {
    addActionAfter: (afterKey: number, value: string) => void;
    addDelayAfter: (afterKey: number) => void;
    addConditionAfter: (afterKey: number, value: string, label: string) => void;
    addIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted', type: 'action' | 'delay', value?: string) => void;
    addDelayIntoBranch: (conditionKey: number, branch: 'accepted' | 'not_accepted') => void;
};

type MenuState = {
    openId: { value: string | null };
    toggle: (id: string) => void;
    close: () => void;
};

const ctx = inject<AddContext>('campaignAddContext')!;
const menuState = inject<MenuState>('campaignAddMenu')!;

const triggerRef = ref<HTMLElement | null>(null);
const menuAnchor = ref({ top: 0, left: 0, width: 0 });

const isOpen = computed(() => menuState.openId.value === props.menuId);

const menuStyle = computed(() => {
    const { top, left, width } = menuAnchor.value;

    if (props.align === 'left') {
        return { top: `${top}px`, left: `${left}px` };
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
    if (!el) return;

    const rect = el.getBoundingClientRect();
    menuAnchor.value = {
        top: rect.bottom + 6,
        left: rect.left,
        width: rect.width,
    };
}

function open() {
    menuState.toggle(props.menuId);
}

function close() {
    menuState.close();
}

function pickAction(value: string) {
    if (props.branch && props.conditionKey !== undefined) {
        ctx.addIntoBranch(props.conditionKey, props.branch, 'action', value);
    } else {
        ctx.addActionAfter(props.afterKey, value);
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

function pickCondition(value: string, label: string) {
    ctx.addConditionAfter(props.afterKey, value, label);
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
        class="pointer-events-auto relative campaign-add-menu-trigger"
        :class="{ 'campaign-add-menu-open': isOpen }"
        @click.stop
    >
        <button
            type="button"
            class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-primary hover:bg-primary hover:text-white"
            title="Add step"
            @click.stop="open"
        >
            <Plus class="h-3.5 w-3.5" />
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                class="campaign-add-menu-dropdown fixed z-[10050] w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-[0_16px_40px_rgba(15,23,42,0.18)]"
                :style="menuStyle"
                @click.stop
            >
                <p class="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ branch ? (branch === 'accepted' ? 'Add to Yes branch' : 'Add to No branch') : 'Add after this step' }}
                </p>

                <button
                    v-for="action in CAMPAIGN_ACTIONS"
                    :key="action.value"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs hover:bg-slate-50"
                    @click="pickAction(action.value)"
                >
                    <span
                        class="flex h-6 w-6 items-center justify-center rounded-md border"
                        :style="{ background: action.light, borderColor: action.border, color: action.accent }"
                    >
                        <CampaignActionIcon :value="action.value" :size="13" />
                    </span>
                    {{ action.label }}
                </button>

                <template v-if="!branch">
                    <div class="my-1 border-t border-slate-100" />
                    <p class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wide text-orange-500/90">
                        Conditions · Yes / No
                    </p>
                    <button
                        v-for="cond in CAMPAIGN_CONDITIONS"
                        :key="cond.value"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg border border-orange-200/70 bg-orange-50/50 px-2 py-2 text-left text-xs text-orange-900 hover:bg-orange-50"
                        @click="pickCondition(cond.value, cond.label)"
                    >
                        <GitBranch class="h-3.5 w-3.5 shrink-0 text-orange-500" />
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ cond.label }}</span>
                            <span class="block text-[10px] font-normal text-orange-800/80">Yes / No branches</span>
                        </span>
                    </button>
                </template>

                <div class="my-1 border-t border-slate-100" />
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs text-amber-800 hover:bg-amber-50"
                    @click="pickDelay"
                >
                    <Clock class="h-3.5 w-3.5" /> Wait / Delay
                </button>
            </div>
        </Teleport>
    </div>
</template>
