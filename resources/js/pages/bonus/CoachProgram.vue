<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, GraduationCap } from '@lucide/vue';
import BonusPageShell from '@/components/bonus/BonusPageShell.vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Coaching Program' }] },
});

const perks = [
    '8-week guided coaching program',
    'Access unlocked on this account',
    'Use outreach, inbox, and call tools alongside your coaching plan',
];
</script>

<template>
    <Head title="Coaching Program" />

    <BonusPageShell
        title="8 Week Coaching Program"
        subtitle="Thanks for picking up this upsell — your coaching program access is active on this workspace."
        badge="Bonus unlocked"
        :icon="GraduationCap"
    >
        <div class="space-y-5">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm text-emerald-900">
                Your coaching program enrollment is confirmed.
            </div>

            <ul class="space-y-2.5">
                <li
                    v-for="perk in perks"
                    :key="perk"
                    class="flex items-start gap-2.5 text-sm text-muted-foreground"
                >
                    <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                    <span>{{ perk }}</span>
                </li>
            </ul>
        </div>
    </BonusPageShell>
</template>
