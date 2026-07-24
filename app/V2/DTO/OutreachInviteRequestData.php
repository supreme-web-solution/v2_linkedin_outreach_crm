<?php

namespace App\V2\DTO;

use Illuminate\Http\Request;

class OutreachInviteRequestData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return $request->validate([
            'recipient_id' => ['required', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
