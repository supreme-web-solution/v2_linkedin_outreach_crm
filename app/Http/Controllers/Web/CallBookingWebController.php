<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\V2\Services\CallCalendarService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CallBookingWebController extends Controller
{
    public function __construct(private readonly CallCalendarService $calendar)
    {
    }

    public function show(string $token): Response|RedirectResponse
    {
        $call = $this->calendar->findCallByBookingToken($token);
        if (!$call) {
            abort(404, 'This booking link is invalid or has expired.');
        }

        $user = User::query()->find($call->user_id);
        if (!$user || !$this->calendar->isAvailable($user->id)) {
            abort(404, 'Scheduling is not available for this link.');
        }

        if ($call->scheduled_call_at && $call->status === 'booked') {
            return Inertia::render('public/BookCall', [
                'booked' => true,
                'hostName' => $user->name,
                'prospectName' => $call->prospect_name,
                'scheduledAt' => $call->scheduled_call_at->toIso8601String(),
                'slots' => [],
            ]);
        }

        return Inertia::render('public/BookCall', [
            'booked' => false,
            'token' => $token,
            'hostName' => $user->name,
            'prospectName' => $call->prospect_name,
            'durationMinutes' => max(15, (int) (app(\App\V2\Services\CallOrchestrationService::class)->settingsFor($user)['call_duration_minutes'] ?? 30)),
            'slots' => $this->calendar->availableSlotsForUser($user),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $call = $this->calendar->findCallByBookingToken($token);
        if (!$call) {
            return back()->with('error', 'This booking link is invalid.');
        }

        $user = User::query()->find($call->user_id);
        if (!$user) {
            return back()->with('error', 'Scheduling is not available.');
        }

        $data = $request->validate([
            'slot_start' => ['required', 'date'],
            'prospect_email' => ['required', 'email', 'max:191'],
        ]);

        try {
            $start = Carbon::parse($data['slot_start']);
            $this->calendar->bookCallAt(
                $call,
                $user,
                $start,
                'public_booking',
                trim((string) $data['prospect_email']),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not complete booking. Please try another time.');
        }

        return redirect()->route('book.show', ['token' => $token])
            ->with('success', 'Your call is booked. Check your email for the calendar invite.');
    }
}
