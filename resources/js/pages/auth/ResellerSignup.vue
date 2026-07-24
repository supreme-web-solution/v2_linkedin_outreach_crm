<script setup lang="ts">
import LicenseSignupForm from './LicenseSignupForm.vue';
</script>

<template>
    <LicenseSignupForm
        title="Reseller Access"
        description="Activate your reseller account to create and manage sub-users."
        action="/auth/reseller-access"
    />
</template>
