<?php

namespace App\V2\DTO;

use Illuminate\Http\Request;

class OutreachStartChatRequestData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return $request->validate([
            'attendee_ids' => ['required', 'array', 'min:1'],
            'attendee_ids.*' => ['string', 'max:191'],
            'text' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
