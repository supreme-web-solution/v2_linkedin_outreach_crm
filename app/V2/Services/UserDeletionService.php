<?php

namespace App\V2\Services;

use App\Models\AiContent;
use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\Integration;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2AutoResponse;
use App\Models\V2Call;
use App\Models\V2Campaign;
use App\Models\V2ContentPost;
use App\Models\V2Conversation;
use App\Models\V2EspDelivery;
use App\Models\V2EspIntegration;
use App\Models\V2ExtensionToken;
use App\Models\V2InspirationPost;
use App\Models\V2IntegrationAccount;
use App\Models\V2Lead;
use App\Models\V2OrganizationUser;
use App\Models\V2ProductTransaction;
use App\Models\V2TeamInvite;
use App\Models\V2UserActivity;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $userId = $user->id;

            $audiences = Audience::query()->where('user_id', $userId)->get();
            foreach ($audiences as $audience) {
                AudienceList::query()->where('audience_id', $audience->audience_id)->delete();
                $audience->delete();
            }

            $snLists = SnLeadList::query()->where('user_id', $userId)->get();
            foreach ($snLists as $list) {
                SnLead::query()->where('sn_list_id', $list->list_hash)->delete();
                $list->delete();
            }

            V2Campaign::query()->where('user_id', $userId)->delete();
            V2Call::query()->where('user_id', $userId)->delete();
            V2Conversation::query()->where('user_id', $userId)->delete();
            V2AutoResponse::query()->where('user_id', $userId)->delete();
            V2Lead::query()->where('user_id', $userId)->delete();
            V2ContentPost::query()->where('user_id', $userId)->delete();
            V2InspirationPost::query()->where('user_id', $userId)->delete();
            V2IntegrationAccount::query()->where('user_id', $userId)->delete();
            V2EspIntegration::query()->where('user_id', $userId)->delete();
            V2EspDelivery::query()->where('user_id', $userId)->delete();
            V2ExtensionToken::query()->where('user_id', $userId)->delete();
            V2TeamInvite::query()->where('inviter_user_id', $userId)->orWhere('invitee_user_id', $userId)->delete();
            V2UserActivity::query()->where('user_id', $userId)->delete();
            AiContent::query()->where('user_id', $userId)->delete();
            Integration::query()->where('user_id', $userId)->delete();

            V2OrganizationUser::query()->where('user_id', $userId)->delete();
            V2ProductTransaction::query()->where('user_id', $userId)->delete();

            User::query()->where('created_by', $userId)->update(['created_by' => null]);

            $user->delete();
        });
    }
}
