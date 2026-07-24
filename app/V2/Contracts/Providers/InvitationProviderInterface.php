<?php

namespace App\V2\Contracts\Providers;

interface InvitationProviderInterface
{
    public function sendInvitation(array $payload, array $context = []): array;

    public function listSentInvitations(array $filters = [], array $context = []): array;

    public function listReceivedInvitations(array $filters = [], array $context = []): array;

    public function handleReceivedInvitation(string $invitationId, string $action, array $context = []): array;

    public function cancelInvitation(string $invitationId, array $context = []): array;
}
