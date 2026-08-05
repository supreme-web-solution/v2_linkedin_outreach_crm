<?php

namespace App\V2\Outreach;

class InboxAttachmentSupport
{
    /**
     * Platforms where Unipile supports sending message attachments.
     *
     * @return list<string>
     */
    public static function platformsWithAttachments(): array
    {
        return ['linkedin', 'whatsapp', 'instagram', 'telegram', 'email'];
    }

    public static function supportsAttachments(string $platform): bool
    {
        return in_array($platform, self::platformsWithAttachments(), true);
    }
}
