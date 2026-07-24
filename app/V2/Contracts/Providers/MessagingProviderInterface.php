<?php

namespace App\V2\Contracts\Providers;

interface MessagingProviderInterface
{
    public function listChats(array $filters = [], array $context = []): array;

    public function listMessages(string $chatId, array $filters = [], array $context = []): array;

    public function sendMessage(string $chatId, array $payload, array $context = []): array;

    public function startChat(array $payload, array $context = []): array;

    public function markChatReadState(string $chatId, bool $isRead, array $context = []): array;
}
