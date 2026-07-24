<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\V2\Outreach\OutreachImportListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutreachImportListWebController extends Controller
{
    public function template(Request $request, OutreachImportListService $service): StreamedResponse
    {
        if ($request->query('format') === 'xlsx') {
            return $service->xlsxTemplateResponse();
        }

        $content = $service->csvTemplate();

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'outreach-contacts-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(Request $request, OutreachImportListService $service): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls,ods'],
        ]);

        try {
            $result = $service->createFromUploadedFile($user, $data['name'], $request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'list' => $result['list'],
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'message' => sprintf(
                'Imported %d contact(s) into "%s".%s',
                $result['imported'],
                $result['list']['list_name'],
                $result['skipped'] > 0 ? " {$result['skipped']} row(s) skipped." : '',
            ),
        ]);
    }

    public function index(OutreachImportListService $service): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'lists' => $service->listsForUser($user->id),
        ]);
    }
}
