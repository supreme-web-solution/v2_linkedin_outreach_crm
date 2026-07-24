<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core';
import { Play } from '@lucide/vue';
</script>

<template>
    <div class="flex cursor-grab items-center gap-2.5 rounded-full border border-blue-200 bg-gradient-to-r from-blue-600 to-blue-500 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_8px_20px_rgba(37,99,235,0.28)] active:cursor-grabbing">
        <div class="flex h-6 w-6 items-center justify-center rounded-full bg-white/15">
            <Play class="h-3.5 w-3.5 fill-white text-white" />
        </div>
        START
    </div>
    <Handle type="source" :position="Position.Bottom" class="!border-blue-400 !bg-white" />
</template>
