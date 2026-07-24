<?php

namespace App\V2\Campaign;

class CampaignSequenceResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function flattenNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') !== 'end') {
                $result[] = $node;
            }

            $branches = $node['branches'] ?? null;
            if (!is_array($branches)) {
                continue;
            }

            foreach (['accepted', 'not_accepted'] as $branchKey) {
                if (!empty($branches[$branchKey]) && is_array($branches[$branchKey])) {
                    $result = array_merge($result, $this->flattenNodes($branches[$branchKey]));
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function findNodeByKey(array $nodes, int $key): ?array
    {
        foreach ($this->walkNodes($nodes) as $node) {
            if ((int) ($node['key'] ?? -1) === $key) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function walkNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $result[] = $node;

            $branches = $node['branches'] ?? null;
            if (!is_array($branches)) {
                continue;
            }

            foreach (['accepted', 'not_accepted'] as $branchKey) {
                if (!empty($branches[$branchKey]) && is_array($branches[$branchKey])) {
                    $result = array_merge($result, $this->walkNodes($branches[$branchKey]));
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function nodeLabel(array $node): string
    {
        $label = trim((string) ($node['label'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        $value = (string) ($node['value'] ?? $node['type'] ?? 'step');

        return ucwords(str_replace(['-', '_'], ' ', $value));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function stepType(array $node): string
    {
        $type = (string) ($node['type'] ?? 'action');
        $value = (string) ($node['value'] ?? '');

        if ($type === 'delay') {
            return 'wait';
        }

        if ($type === 'condition') {
            return 'condition';
        }

        if ($type === 'end') {
            return 'end-sequence';
        }

        return $value !== '' ? $value : 'action';
    }

    /**
     * Linear execution path for a lead (top-level + chosen condition branch).
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function executionPath(array $nodes, ?bool $acceptanceStatus = true): array
    {
        $path = [];
        $endNode = null;

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');

            if ($type === 'end') {
                $endNode = $node;

                continue;
            }

            if ($type === 'condition') {
                $path[] = $node;
                $branchKey = $acceptanceStatus === true ? 'accepted' : 'not_accepted';
                $branch = $node['branches'][$branchKey] ?? [];

                if (is_array($branch)) {
                    foreach ($branch as $branchNode) {
                        if (is_array($branchNode) && ($branchNode['type'] ?? '') !== 'end') {
                            $path[] = $branchNode;
                        }
                    }
                }

                continue;
            }

            $path[] = $node;
        }

        if ($endNode !== null) {
            $path[] = $endNode;
        }

        return $path;
    }

    /**
     * Resolve the next node key after completing the given node.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function resolveNextNodeKey(array $nodes, int $currentKey, ?bool $acceptanceStatus = null): ?int
    {
        $path = $this->executionPath($nodes, $acceptanceStatus ?? true);

        foreach ($path as $index => $node) {
            if ((int) ($node['key'] ?? -1) !== $currentKey) {
                continue;
            }

            $next = $path[$index + 1] ?? null;

            return $next !== null ? (int) ($next['key'] ?? null) : null;
        }

        return null;
    }

    /**
     * @deprecated Use executionPath + resolveNextNodeKey instead.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function resolveNextNodeKeyTopLevel(array $nodes, int $currentKey, ?bool $acceptanceStatus = null): ?int
    {
        $topLevel = $this->topLevelNodes($nodes);
        $currentIndex = null;

        foreach ($topLevel as $index => $node) {
            if ((int) ($node['key'] ?? -1) === $currentKey) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex === null) {
            return null;
        }

        $current = $topLevel[$currentIndex];
        $currentType = (string) ($current['type'] ?? '');

        if ($currentType === 'condition') {
            $branchKey = $acceptanceStatus === true ? 'accepted' : 'not_accepted';
            $branch = $current['branches'][$branchKey] ?? [];

            if (is_array($branch) && !empty($branch)) {
                return (int) ($branch[0]['key'] ?? null);
            }

            return null;
        }

        if (isset($topLevel[$currentIndex + 1])) {
            return (int) ($topLevel[$currentIndex + 1]['key'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public function topLevelNodes(array $nodes): array
    {
        return array_values(array_filter($nodes, fn ($node) => is_array($node) && ($node['type'] ?? '') !== 'end'));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function delaySeconds(array $node): int
    {
        $value = (int) ($node['value'] ?? 1);
        $time = (string) ($node['time'] ?? 'hours');

        if ($value <= 0) {
            return 3;
        }

        return match ($time) {
            'days' => max(60, $value * 86400),
            'minutes' => max(60, $value * 60),
            default => max(60, $value * 3600),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function messageText(array $node, ?string $firstName = null): string
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $message = trim((string) ($config['message'] ?? $node['message'] ?? $node['text'] ?? ''));

        if ($firstName !== null && $firstName !== '') {
            $message = str_replace(['{{firstName}}', '{{first_name}}'], $firstName, $message);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function firstNameFromLead(?string $fullName): string
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return 'there';
        }

        $parts = preg_split('/\s+/', $fullName);

        return $parts[0] ?? 'there';
    }
}
