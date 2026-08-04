<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchAudienceEmailJob;
use App\Jobs\FetchCompetitorFollowersJob;
use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Services\EmailEnrichmentLimiter;
use App\V2\Outreach\OutreachLeadContactResolver;
use App\V2\Web\AudienceListLeadPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompetitorFollowersWebController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $search = trim((string) $request->query('search', ''));

        $query = Audience::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('source', 'linkedin_company_followers')
                    ->orWhere('tag', 'competitor_active_followers');
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('audience_name', 'like', '%'.$search.'%')
                    ->orWhere('source_meta', 'like', '%'.$search.'%');
            });
        }

        $audiences = $query
            ->latest()
            ->paginate(10)
            ->appends($request->query());

        $audiences->getCollection()->transform(function ($audience) {
            $audience->followers_count = AudienceList::where('audience_id', $audience->audience_id)->count();
            $meta = $audience->source_meta ? json_decode($audience->source_meta, true) : [];
            $audience->fetch_status = $meta['fetch_status'] ?? null;
            $audience->fetch_progress = $meta['fetch_progress'] ?? null;
            $audience->company_url = $meta['company_url'] ?? null;
            $audience->last_error = $meta['last_error'] ?? null;
            $audience->last_error_type = $meta['last_error_type'] ?? null;

            return $audience;
        });

        $hasLinkedInSession = (bool) V2IntegrationAccount::activeUnipileAccountId($user->id);

        return Inertia::render('crm/CompetitorFollowers/Index', [
            'audiences' => $audiences,
            'hasLinkedInSession' => $hasLinkedInSession,
            'filters' => [
                'search' => $search !== '' ? $search : null,
            ],
        ]);
    }

    public function fetch(Request $request)
    {
        $data = $request->validate([
            'company_url' => ['required', 'url'],
        ]);

        $user = Auth::user();

        if (! V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return back()->with('error', 'Connect LinkedIn via Integrations before harvesting.');
        }

        $companySlug = null;
        $companyName = 'Competitor Followers';
        $parsedUrl = parse_url($data['company_url']);
        if (isset($parsedUrl['path'])) {
            if (preg_match('/\/company\/([^\/\?]+)/', $parsedUrl['path'], $matches)) {
                $companySlug = $matches[1];
                $companyName = ucfirst($companySlug).' - Active Engagers';
            } elseif (! empty($parsedUrl['host'])) {
                $companyName = str_replace('www.', '', $parsedUrl['host']).' - Active Engagers';
            }
        }

        $normalizedUrl = rtrim(
            parse_url($data['company_url'], PHP_URL_SCHEME).'://'.
            parse_url($data['company_url'], PHP_URL_HOST).
            parse_url($data['company_url'], PHP_URL_PATH),
            '/'
        );

        $existingAudience = Audience::where('user_id', $user->id)
            ->where('source', 'linkedin_company_followers')
            ->where('tag', 'competitor_active_followers')
            ->get()
            ->first(function ($aud) use ($normalizedUrl) {
                $meta = $aud->source_meta ? json_decode($aud->source_meta, true) : null;
                if ($meta && isset($meta['company_url'])) {
                    $existingUrl = rtrim(
                        parse_url($meta['company_url'], PHP_URL_SCHEME).'://'.
                        parse_url($meta['company_url'], PHP_URL_HOST).
                        parse_url($meta['company_url'], PHP_URL_PATH),
                        '/'
                    );

                    return $existingUrl === $normalizedUrl;
                }

                return false;
            });

        if ($existingAudience) {
            if ($existingAudience->audience_name !== $companyName) {
                $existingAudience->audience_name = $companyName;
                $existingAudience->save();
            }
            $audience = $existingAudience;
        } else {
            $audience = Audience::create([
                'audience_name' => $companyName,
                'audience_id' => now()->timestamp.$user->id,
                'audience_type' => 'LI',
                'user_id' => $user->id,
                'tag' => 'competitor_active_followers',
                'source' => 'linkedin_company_followers',
                'source_meta' => json_encode([
                    'company_url' => $normalizedUrl,
                ]),
            ]);
        }

        $meta = json_decode($audience->source_meta, true) ?? [];
        $meta['company_url'] = $normalizedUrl;
        $meta['fetch_status'] = 'pending';
        $meta['fetch_started_at'] = now()->toIso8601String();
        $meta['fetch_progress'] = 'Queued and ready to go...';
        $audience->source_meta = json_encode($meta);
        $audience->save();

        FetchCompetitorFollowersJob::dispatch(
            $user->id,
            $audience->id,
            $data['company_url'],
            '',
            ''
        );

        return back()->with('success', 'Fetch started. This can take a few minutes — watch the status update live.');
    }

    public function show(Request $request, $audienceId): Response
    {
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('id', $audienceId)->firstOrFail();

        $emailFilter = $request->query('email_filter', 'all');
        $search = trim((string) $request->query('search', ''));

        $query = AudienceList::where('audience_id', $audience->audience_id);

        switch ($emailFilter) {
            case 'with_email':
                $query->whereNotNull('con_email')->where('con_email', '!=', '');
                break;
            case 'without_email':
                $query->where(function ($q) {
                    $q->whereNull('con_email')->orWhere('con_email', '=', '');
                })->where(function ($q) {
                    $q->where('email_fetch_status', 'completed')
                        ->orWhereNotNull('email_fetch_attempted_at');
                });
                break;
            case 'not_found':
                $query->where('email_fetch_status', 'completed')
                    ->where(function ($q) {
                        $q->whereNull('con_email')->orWhere('con_email', '=', '');
                    });
                break;
            case 'not_fetched':
                $query->whereNull('email_fetch_status')->whereNull('email_fetch_attempted_at');
                break;
            case 'pending':
                $query->whereIn('email_fetch_status', ['pending', 'processing']);
                break;
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('con_first_name', 'like', '%'.$search.'%')
                    ->orWhere('con_last_name', 'like', '%'.$search.'%')
                    ->orWhere('con_job_title', 'like', '%'.$search.'%')
                    ->orWhere('con_company_name', 'like', '%'.$search.'%')
                    ->orWhere('con_email', 'like', '%'.$search.'%')
                    ->orWhere('con_location', 'like', '%'.$search.'%');
            });
        }

        $presenter = app(AudienceListLeadPresenter::class);
        $overlays = app(OutreachLeadContactResolver::class)->overlaysForLists((int) $user->id, [
            ['list_hash' => $audience->audience_id, 'list_src' => 'aud'],
        ]);

        $followers = $query->latest()->paginate(20)->appends($request->query())
            ->through(fn (AudienceList $row) => $presenter->transformRow($row, $overlays));

        $pendingCount = app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id);
        $counts = $presenter->emailFilterCounts($audience->audience_id);
        $contactStats = $presenter->contactStatsForList($audience->audience_id, (int) $user->id, $pendingCount);

        $meta = json_decode($audience->source_meta, true) ?? [];

        return Inertia::render('crm/CompetitorFollowers/Show', [
            'audience' => [
                'id' => $audience->id,
                'audience_id' => $audience->audience_id,
                'audience_name' => $audience->audience_name,
                'company_url' => $meta['company_url'] ?? null,
                'followers_count' => AudienceList::where('audience_id', $audience->audience_id)->count(),
                'fetch_status' => $meta['fetch_status'] ?? null,
                'fetch_progress' => $meta['fetch_progress'] ?? null,
            ],
            'followers' => $followers,
            'emailFilter' => $emailFilter,
            'search' => $search,
            'counts' => $counts,
            'contactStats' => $contactStats,
            'pendingCount' => $pendingCount,
            'dailyLimit' => $this->dailyLimitPayload($user),
            'enrichBatchSize' => app(EmailEnrichmentLimiter::class)->batchSize(),
        ]);
    }

    private function getPendingEmailFetchCount($userId)
    {
        $userAudienceIds = Audience::where('user_id', $userId)->pluck('audience_id')->toArray();

        $stuckCutoff = now()->subMinutes(10);
        AudienceList::whereIn('audience_id', $userAudienceIds)
            ->whereIn('email_fetch_status', ['pending', 'processing'])
            ->where(function ($query) use ($stuckCutoff) {
                $query->where('email_fetch_attempted_at', '<', $stuckCutoff)
                    ->orWhereNull('email_fetch_attempted_at');
            })
            ->update([
                'email_fetch_status' => null,
                'email_fetch_attempted_at' => null,
            ]);

        return AudienceList::whereIn('audience_id', $userAudienceIds)
            ->whereIn('email_fetch_status', ['pending', 'processing'])
            ->where('email_fetch_attempted_at', '>=', $stuckCutoff)
            ->count();
    }

    public function getPendingCount()
    {
        return response()->json([
            'status' => 'success',
            'pending_count' => $this->getPendingEmailFetchCount(Auth::id()),
        ]);
    }

    private function dailyLimitPayload(User $user): array
    {
        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        $used = (int) ($user->daily_profile_email_scraping_count ?? 0);
        $remaining = max(0, $dailyLimit - $used);

        return [
            'daily_limit' => $dailyLimit,
            'used' => $used,
            'remaining' => $remaining,
            'can_scrape' => $remaining > 0,
            'reset_date' => $user->daily_profile_email_scraping_reset_at,
        ];
    }

    public function getDailyLimit()
    {
        return response()->json($this->dailyLimitPayload(Auth::user()));
    }

    public function exportCsv($audienceId): StreamedResponse
    {
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('id', $audienceId)->firstOrFail();

        $rows = AudienceList::where('audience_id', $audience->audience_id)->get([
            'con_first_name', 'con_last_name', 'con_job_title', 'con_company_name', 'con_location', 'con_profile_url', 'con_email',
        ]);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="competitor_followers_'.$audience->id.'.csv"',
        ];

        $callback = function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Name', 'Job Title', 'Company', 'Location', 'Profile URL', 'Email']);
            foreach ($rows as $r) {
                $name = trim(($r->con_first_name ?? '').' '.($r->con_last_name ?? ''));
                if ($name === '') {
                    $slug = trim((string) ($r->con_public_identifier ?? ''));
                    if ($slug !== '' && ! str_starts_with($slug, 'ACo') && ! str_starts_with($slug, 'ADo')) {
                        $slug = (string) preg_replace('/-[a-z0-9]{6,}$/i', '', $slug);
                        $parts = preg_split('/[-_]+/', $slug) ?: [];
                        $words = [];
                        foreach ($parts as $part) {
                            $part = trim($part);
                            if ($part === '' || ctype_digit($part)) {
                                continue;
                            }
                            $words[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
                        }
                        $name = implode(' ', $words);
                    }
                }
                fputcsv($handle, [
                    $name !== '' ? $name : 'Unknown',
                    $r->con_job_title,
                    $r->con_company_name,
                    $r->con_location,
                    $r->con_profile_url,
                    $r->con_email ?? '',
                ]);
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function fetchEmail(Request $request, $audienceId)
    {
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('id', $audienceId)->firstOrFail();

        $request->validate([
            'audience_list_id' => 'required|integer|exists:audience_lists,id',
        ]);

        $audienceListItem = AudienceList::where('id', $request->audience_list_id)
            ->where('audience_id', $audience->audience_id)
            ->firstOrFail();

        if (! empty($audienceListItem->con_email)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Email already exists',
                'email' => $audienceListItem->con_email,
            ], 200);
        }

        if (! empty($audienceListItem->email_fetch_attempted_at)) {
            if ($audienceListItem->email_fetch_status === 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email fetch is already in progress. Please wait or refresh the page.',
                    'already_pending' => true,
                ], 409);
            }
            if ($audienceListItem->email_fetch_status === 'completed' && empty($audienceListItem->con_email)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email fetch was already attempted. No email found for this profile.',
                    'already_completed' => true,
                ], 409);
            }
            // timed_out (and other non-completed statuses) may be retried.
        }

        $publicIdentifier = $audienceListItem->con_public_identifier;
        if (empty($publicIdentifier) && ! empty($audienceListItem->con_profile_url)) {
            if (preg_match('/\/in\/([^\/\?]+)/', $audienceListItem->con_profile_url, $matches)) {
                $publicIdentifier = $matches[1];
            }
        }

        if (empty($publicIdentifier) && ! empty($audienceListItem->con_id)) {
            $publicIdentifier = $audienceListItem->con_id;
        }

        if (empty($publicIdentifier)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profile identifier not found. Cannot fetch email.',
            ], 400);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = config('services.email_scraping.daily_limit_per_user', 100);
        if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
            return response()->json([
                'status' => 'error',
                'message' => "Daily email scraping limit reached ({$dailyLimit} profiles/day). Please try again tomorrow.",
            ], 429);
        }

        $pendingCount = app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id);
        $batchSize = app(EmailEnrichmentLimiter::class)->batchSize();
        if ($pendingCount >= $batchSize) {
            return response()->json([
                'status' => 'error',
                'message' => "You have {$pendingCount} email scraping jobs in progress. Please wait for the current batch to finish.",
                'concurrent_limit_reached' => true,
                'pending_count' => $pendingCount,
            ], 429);
        }

        $audienceListItem->update([
            'email_fetch_attempted_at' => now(),
            'email_fetch_status' => 'pending',
        ]);

        try {
            FetchAudienceEmailJob::dispatch($audienceListItem->id, $publicIdentifier);

            return response()->json([
                'status' => 'success',
                'message' => 'Enrichment job queued.',
                'pending' => true,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch email fetch job', [
                'audience_list_id' => $audienceListItem->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch email: '.$th->getMessage(),
            ], 500);
        }
    }

    public function checkEmail($audienceId, $audienceListId)
    {
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('id', $audienceId)->firstOrFail();

        $audienceListItem = AudienceList::where('id', $audienceListId)
            ->where('audience_id', $audience->audience_id)
            ->firstOrFail();

        $emailFetchCompleted = ! empty($audienceListItem->email_fetch_attempted_at) && empty($audienceListItem->con_email);

        return response()->json([
            'status' => 'success',
            'has_email' => ! empty($audienceListItem->con_email),
            'email' => $audienceListItem->con_email ?? null,
            'email_fetch_status' => $audienceListItem->email_fetch_status,
            'email_fetch_completed' => $emailFetchCompleted,
        ], 200);
    }

    public function fetchEmailBatch(Request $request, $audienceId)
    {
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('id', $audienceId)->firstOrFail();

        $readiness = app(\App\V2\Outreach\OutreachLeadReadinessService::class);

        $limiter = app(EmailEnrichmentLimiter::class);
        $batchSize = $limiter->batchSize();

        $request->validate([
            'auto_batch' => 'sometimes|boolean',
            'audience_list_ids' => 'required_unless:auto_batch,true|array|min:1|max:'.$batchSize,
            'audience_list_ids.*' => 'required|integer|exists:audience_lists,id',
        ]);

        $audienceListIds = $request->boolean('auto_batch')
            ? $readiness->nextAudienceListIdsForEmailFetch($audience->audience_id, $batchSize)
            : $request->input('audience_list_ids', []);

        if ($audienceListIds === []) {
            return response()->json([
                'status' => 'error',
                'message' => 'No contacts left to enrich in this list.',
            ], 400);
        }

        $audienceListItems = AudienceList::whereIn('id', $audienceListIds)
            ->where('audience_id', $audience->audience_id)
            ->get();

        if ($audienceListItems->count() !== count($audienceListIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Some selected items do not belong to this audience',
            ], 400);
        }

        $itemsNeedingEmail = $audienceListItems->filter(function ($item) {
            return empty($item->con_email) && empty($item->email_fetch_attempted_at);
        });

        if ($itemsNeedingEmail->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'All selected profiles already have emails or have been attempted',
            ], 400);
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();
        $profileCount = $itemsNeedingEmail->count();
        $capacity = $limiter->queueCapacity($user, $profileCount);

        if (! $capacity['allowed']) {
            return response()->json([
                'status' => 'error',
                'message' => $capacity['message'],
                'daily_limit_reached' => $capacity['remaining_daily'] <= 0,
                'remaining' => $capacity['remaining_daily'],
                'pending_count' => $capacity['pending_jobs'],
            ], $capacity['pending_jobs'] >= $batchSize ? 429 : 400);
        }

        $idsToQueue = $itemsNeedingEmail->pluck('id')->take($capacity['max_queue_now'])->values()->all();

        AudienceList::query()
            ->whereIn('id', $idsToQueue)
            ->update(['email_fetch_attempted_at' => now(), 'email_fetch_status' => 'pending']);

        try {
            FetchAudienceEmailBatchJob::dispatch($idsToQueue, $user->id);

            $queued = count($idsToQueue);
            $message = $queued < $profileCount
                ? "Queued {$queued} of {$profileCount} profile(s) for enrichment (batch/daily limit)."
                : "Queued enrichment for {$queued} profile(s).";

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'profile_count' => $queued,
                'skipped' => $profileCount - $queued,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch batch email fetch job', [
                'audience_list_ids' => $audienceListIds,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch emails: '.$th->getMessage(),
            ], 500);
        }
    }

    public function getFetchStatus(Request $request, $audienceId)
    {
        $user = Auth::user();
        $audience = Audience::where('user_id', $user->id)->where('id', $audienceId)->firstOrFail();

        $meta = json_decode($audience->source_meta, true) ?? [];
        $status = $meta['fetch_status'] ?? null;
        $storedCount = (int) ($meta['stored_count'] ?? 0);
        $fetchCompletedAt = $meta['fetch_completed_at'] ?? null;

        if ($status === 'failed' && $fetchCompletedAt && $storedCount > 0) {
            $status = 'completed';
        }

        return response()->json([
            'status' => $status,
            'progress' => $meta['fetch_progress'] ?? null,
            'fetch_started_at' => $meta['fetch_started_at'] ?? null,
            'fetch_completed_at' => $fetchCompletedAt,
            'fetch_failed_at' => $meta['fetch_failed_at'] ?? null,
            'stored_count' => $meta['stored_count'] ?? null,
            'total_fetched' => $meta['total_fetched'] ?? null,
            'followers_count' => AudienceList::where('audience_id', $audience->audience_id)->count(),
            'last_error' => $meta['last_error'] ?? null,
            'last_error_type' => $meta['last_error_type'] ?? null,
        ]);
    }

    public function delete(Request $request, $audienceId)
    {
        $user = Auth::user();

        $audience = Audience::where('id', $audienceId)->where('user_id', $user->id)->first();

        if (! $audience) {
            return response()->json([
                'status' => 'error',
                'message' => 'Audience not found or you do not have permission to delete it.',
            ], 404);
        }

        $deleteAudience = $request->input('delete_audience', 1) == 1;

        try {
            $actualAudienceId = $audience->audience_id;
            AudienceList::where('audience_id', $actualAudienceId)->delete();

            if ($deleteAudience) {
                $audience->delete();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Audience and all follower data have been deleted successfully.',
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Follower data deleted successfully. The audience record has been preserved.',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('CompetitorFollowersWebController: Failed to delete audience', [
                'user_id' => $user->id,
                'audience_id' => $audienceId,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete audience: '.$th->getMessage(),
            ], 500);
        }
    }

    private function checkAndResetDailyLimit(User $user): void
    {
        $today = now()->toDateString();
        $resetDate = $user->daily_profile_email_scraping_reset_at
            ? \Carbon\Carbon::parse($user->daily_profile_email_scraping_reset_at)->toDateString()
            : null;

        if ($resetDate !== $today) {
            $user->update([
                'daily_profile_email_scraping_count' => 0,
                'daily_profile_email_scraping_reset_at' => $today,
            ]);
        }
    }
}
