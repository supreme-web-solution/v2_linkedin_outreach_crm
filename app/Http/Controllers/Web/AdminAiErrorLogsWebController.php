<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiErrorLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminAiErrorLogsWebController extends Controller
{
    public function index(Request $request): Response
    {
        $source = trim((string) $request->query('source', ''));
        $channel = trim((string) $request->query('channel', ''));
        $q = trim((string) $request->query('q', ''));

        $query = AiErrorLog::query()
            ->with(['user:id,name,email'])
            ->latest('id');

        if ($source !== '') {
            $query->where('source', $source);
        }
        if ($channel !== '') {
            $query->where('channel', $channel);
        }
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('message', 'like', '%'.$q.'%')
                    ->orWhere('user_message', 'like', '%'.$q.'%')
                    ->orWhere('exception_class', 'like', '%'.$q.'%');
            });
        }

        $logs = $query->paginate(30)->through(fn (AiErrorLog $log) => [
            'id' => $log->id,
            'source' => $log->source,
            'channel' => $log->channel,
            'exception_class' => $log->exception_class,
            'message' => $log->message,
            'user_message' => $log->user_message,
            'context' => $log->context,
            'trace' => $log->trace,
            'organization_id' => $log->organization_id,
            'conversation_id' => $log->conversation_id,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        return Inertia::render('admin/AiErrorLogs', [
            'logs' => $logs,
            'filters' => [
                'source' => $source,
                'channel' => $channel,
                'q' => $q,
            ],
        ]);
    }
}
