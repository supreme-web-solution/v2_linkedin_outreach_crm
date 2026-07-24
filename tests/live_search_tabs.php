<?php

/**
 * Live integration test for Search tab endpoints (Filters, From URL, Profile).
 * Run: php tests/live_search_tabs.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\V2ExtensionToken;
use Illuminate\Support\Str;

$baseUrl = rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/');
$user = User::query()->find(1);
if (! $user) {
    fwrite(STDERR, "No user id=1 found.\n");
    exit(1);
}

$plainToken = 'v2ext_live_search_' . Str::random(40);
V2ExtensionToken::query()->create([
    'user_id' => $user->id,
    'name' => 'live-search-test',
    'token_hash' => hash('sha256', $plainToken),
    'expires_at' => now()->addHour(),
    'meta' => ['source' => 'live_search_tabs.php'],
]);

$orgId = (int) ($user->current_organization_id ?? 1);

function hitSearch(string $baseUrl, string $token, int $orgId, array $body, string $label): array
{
    $url = $baseUrl . '/api/v2/leads/search';
    $json = json_encode(array_merge(['persist_results' => true], $body));

    echo "\n=== {$label} ===\n";
    echo "POST {$url}\n";
    echo "Body: {$json}\n";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Organization-Id: ' . $orgId,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "cURL error: {$error}\n";
        return ['ok' => false, 'status' => 0, 'error' => $error];
    }

    $data = json_decode($response, true) ?? ['raw' => $response];
    echo "HTTP {$status}\n";

    if ($status >= 200 && $status < 300) {
        $items = $data['data']['items'] ?? [];
        $count = is_array($items) ? count($items) : 0;
        $stored = $data['stored_count'] ?? '?';
        $audience = $data['audience_name'] ?? ($data['meta']['audience_name'] ?? '?');
        echo "OK · items={$count} · stored={$stored} · audience=\"{$audience}\"\n";
        if ($count > 0) {
            $first = $items[0];
            $name = $first['full_name'] ?? $first['name'] ?? $first['first_name'] ?? '?';
            $pid = $first['provider_id'] ?? $first['id'] ?? $first['public_identifier'] ?? '?';
            echo "First lead: {$name} ({$pid})\n";
        }
        return ['ok' => true, 'status' => $status, 'data' => $data];
    }

    echo "FAIL: " . ($data['message'] ?? substr($response, 0, 300)) . "\n";
    return ['ok' => false, 'status' => $status, 'data' => $data];
}

$results = [];

// 1. Profile tab (matches screenshot: eleazarnzerem)
$results['profile'] = hitSearch($baseUrl, $plainToken, $orgId, [
    'profile_url' => 'https://www.linkedin.com/in/eleazarnzerem/',
    'audience_name' => 'eleazar',
], 'Profile tab — import by profile URL');

// 2. Filters tab
$results['filter'] = hitSearch($baseUrl, $plainToken, $orgId, [
    'keywords' => 'video editor',
    'limit' => 5,
    'audience_name' => 'filter-test-live',
], 'Filters tab — keyword search');

// 3. From URL tab (classic people search URL)
$results['url'] = hitSearch($baseUrl, $plainToken, $orgId, [
    'linkedin_url' => 'https://www.linkedin.com/search/results/people/?keywords=video%20editor',
    'limit' => 5,
    'audience_name' => 'url-test-live',
], 'From URL tab — LinkedIn search URL import');

echo "\n=== SUMMARY ===\n";
foreach ($results as $tab => $r) {
    $status = $r['ok'] ? 'PASS' : 'FAIL';
    echo strtoupper($tab) . ": {$status} (HTTP " . ($r['status'] ?? 0) . ")\n";
}

$failed = array_filter($results, fn ($r) => ! ($r['ok'] ?? false));
exit($failed ? 1 : 0);
