<script setup lang="ts">
import LeadEnrichmentRing from '@/components/crm/LeadEnrichmentRing.vue';
import type { Component } from 'vue';

defineProps<{
    icon: Component;
    label: string;
    found: number;
    total: number;
    fillPercent: number;
    hitRate: number;
    sourceLabel?: string;
}>();
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center gap-4 rounded-xl border border-border bg-card px-5 py-4">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted/60">
                <component :is="icon" class="h-4 w-4 text-foreground/70" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-muted-foreground">{{ label }}</p>
                <p class="mt-0.5 text-2xl font-semibold tabular-nums tracking-tight text-foreground">
                    {{ found.toLocaleString() }}
                    <span class="text-lg font-normal text-muted-foreground">/ {{ total.toLocaleString() }}</span>
                </p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <LeadEnrichmentRing :percent="fillPercent" />
            <div class="text-right">
                <p class="text-sm font-semibold tabular-nums text-foreground">{{ hitRate }}%</p>
                <p class="text-[10px] text-muted-foreground">{{ sourceLabel ?? 'LinkedIn' }}</p>
            </div>
        </div>
    </div>
</template>
