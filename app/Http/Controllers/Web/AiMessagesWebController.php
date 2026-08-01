<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiContent;
use App\Services\ChatGPT;
use App\V2\Services\OpenAiUserError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AiMessagesWebController extends Controller
{
    public function index(Request $request): Response
    {
        $title = $request->query('title');

        $query = AiContent::where('user_id', Auth::id());
        if (! empty($title)) {
            $query->where('title', 'like', '%'.$title.'%');
        }

        $aicontents = $query->latest()->paginate(20)->appends($request->query());

        return Inertia::render('crm/AiMessages/Index', [
            'aicontents' => $aicontents,
            'filters' => ['title' => $title],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('crm/AiMessages/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required'],
            'aitype' => ['required'],
            'content' => ['required'],
        ]);

        AiContent::create([
            'title' => $request->title,
            'ai_type' => $request->aitype,
            'language' => $request->language ?? 'English',
            'idea' => $request->idea,
            'write_style' => $request->write_style,
            'connection_message_type' => $request->personalized_by,
            'connection_message_location' => $request->location,
            'connection_message_industry' => $request->industry,
            'connection_message_jobtitle' => $request->jobtitle,
            'contents' => $request->content,
            'word_counts' => (int) $request->words,
            'user_id' => Auth::id(),
        ]);

        return redirect()->route('aiwriter.index')->with('success', 'Message saved successfully.');
    }

    public function edit(string $id): Response
    {
        $aicontent = AiContent::where('user_id', Auth::id())->findOrFail($id);

        return Inertia::render('crm/AiMessages/Edit', [
            'aicontent' => $aicontent,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $aicontent = AiContent::where('user_id', Auth::id())->findOrFail($id);

        $request->validate([
            'title' => ['required'],
            'aitype' => ['required'],
            'content' => ['required'],
        ]);

        $aicontent->update([
            'title' => $request->title,
            'ai_type' => $request->aitype,
            'language' => $request->language ?? 'English',
            'idea' => $request->idea,
            'write_style' => $request->write_style,
            'connection_message_type' => $request->personalized_by,
            'connection_message_location' => $request->location,
            'connection_message_industry' => $request->industry,
            'connection_message_jobtitle' => $request->jobtitle,
            'contents' => $request->content,
            'word_counts' => (int) $request->words,
        ]);

        return redirect()->route('aiwriter.index')->with('success', 'Message updated successfully.');
    }

    public function destroy(string $id)
    {
        $aicontent = AiContent::where('user_id', Auth::id())->findOrFail($id);
        $aicontent->delete();

        return back()->with('success', 'Message deleted successfully.');
    }

    public function generate(Request $request)
    {
        $data = [
            'language' => $request->language,
            'aitype' => $request->aitype,
            'idea' => $request->idea,
            'write_style' => $request->write_style,
            'connection_message_type' => $request->personalized_by,
            'location' => $request->location,
            'industry' => $request->industry,
            'jobtitle' => $request->jobtitle,
        ];

        try {
            $gpt = new ChatGPT($data);
            $result = $gpt->generate();
            if (is_array($result) && isset($result['content'])) {
                $formatted = $gpt->formatAiwriterContent($result['content'], $request->aitype);
                $result['content'] = $formatted;
                $result['words'] = str_word_count($formatted);
            }

            return response()->json($result);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => OpenAiUserError::fromThrowable($th),
            ], 422);
        }
    }
}
