<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\V2\Services\AppCalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarWebController extends Controller
{
    public function index(Request $request, AppCalendarService $calendar): Response
    {
        $user = auth()->user();
        $orgId = $user->current_organization_id;

        $month = (string) $request->input('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $anchor = Carbon::parse($month.'-01');
        $from = $anchor->copy()->startOfMonth()->subDays(7);
        $to = $anchor->copy()->endOfMonth()->addDays(7);

        $events = $orgId
            ? $calendar->eventsForOrganization($orgId, $user->id, $from, $to)
            : [];

        return Inertia::render('crm/Calendar/Index', [
            'events' => $events,
            'month' => $month,
            'hasOrg' => (bool) $orgId,
        ]);
    }

    public function reschedule(Request $request, string $type, int $id, AppCalendarService $calendar): JsonResponse
    {
        $user = auth()->user();
        $orgId = $user->current_organization_id;

        if (! $orgId) {
            return response()->json(['message' => 'Link your workspace first.'], 422);
        }

        $data = $request->validate([
            'start' => ['required', 'date'],
        ]);

        try {
            $result = $calendar->reschedule(
                $orgId,
                $user,
                $type,
                $id,
                Carbon::parse($data['start'])
            );

            return response()->json([
                'message' => 'Event rescheduled.',
                'event' => $result['event'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
