<?php

namespace App\V2\Contracts\Providers;

interface PostProviderInterface
{
    public function listPosts(array $filters = [], array $context = []): array;

    public function listUserPosts(string $identifier, array $filters = [], array $context = []): array;

    public function listPostComments(string $postId, array $filters = [], array $context = []): array;

    public function listPostReactions(string $postId, array $filters = [], array $context = []): array;
}
