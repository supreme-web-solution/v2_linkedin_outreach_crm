<script setup lang="ts">
import LicenseSignupForm from './LicenseSignupForm.vue';
</script>

<template>
    <LicenseSignupForm
        title="Activate FE Access"
        description="Create or update your account after purchasing front-end access."
        action="/auth/fe"
    />
</template>
