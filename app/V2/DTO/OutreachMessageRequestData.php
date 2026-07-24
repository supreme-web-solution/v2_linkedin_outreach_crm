<?php

namespace App\V2\DTO;

use Illuminate\Http\Request;

class OutreachMessageRequestData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return $request->validate([
            'chat_id' => ['required', 'string', 'max:191'],
            'text' => ['required', 'string', 'max:2000'],
        ]);
    }
}
