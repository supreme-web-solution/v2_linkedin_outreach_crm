<?php

use App\Http\Controllers\V2\AccessCheckController;
use App\Http\Controllers\V2\ExtensionAuthController;
use App\Http\Controllers\V2\IntegrationAccountController;
use App\Http\Controllers\V2\LeadController;
use App\Http\Controllers\V2\OutreachController;
use App\Http\Controllers\V2\PostCommentController;
use App\Http\Controllers\V2\ProviderWebhookController;
use App\Http\Controllers\V2\ConversationController;
use App\Http\Controllers\V2\CampaignController;
use App\Http\Controllers\V2\AutoResponseController;
use App\Http\Controllers\V2\ActivityController;
use App\Http\Controllers\V2\CallController;
use App\Http\Controllers\V2\ReminderController;
use App\Http\Controllers\V2\CallCampaignController;
use App\Http\Controllers\V2\ContentCreatorController;
use App\Http\Controllers\V2\InspirationController;
use App\Http\Controllers\V2\EspController;
use App\Http\Controllers\V2\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'version' => 'v2',
    ]);
});

Route::post('/auth/extension-token', [ExtensionAuthController::class, 'issueToken'])
    ->middleware('throttle:v2-auth');

Route::middleware(['v2.extension.token', 'throttle:v2-extension'])->group(function () {
    Route::get('/access-check', AccessCheckController::class);
    Route::post('/team/invites/accept/{token}', [TeamController::class, 'acceptInvite']);
    Route::post('/team/switch/{organizationId}', [TeamController::class, 'switchOrganization']);
});

Route::prefix('provider-events')->group(function () {
    Route::post('/unipile', [ProviderWebhookController::class, 'unipile']);
});

Route::middleware(['v2.extension.token', 'v2.tenant', 'throttle:v2-extension'])->group(function () {
    Route::prefix('integration-accounts')->group(function () {
        Route::get('/', [IntegrationAccountController::class, 'index'])
            ->middleware('v2.capability:integration.read');
        Route::post('/verify', [IntegrationAccountController::class, 'verify'])
            ->middleware('v2.capability:integration.read');
        Route::get('/unipile-status', [IntegrationAccountController::class, 'unipileStatus'])
            ->middleware('v2.capability:integration.read');
        Route::post('/hosted-auth-link', [IntegrationAccountController::class, 'hostedAuthLink'])
            ->middleware('v2.capability:integration.write');
        Route::post('/sync', [IntegrationAccountController::class, 'sync'])
            ->middleware('v2.capability:integration.write');
        // Primary connection endpoints
        Route::post('/connect-cookie', [IntegrationAccountController::class, 'connectViaCookie'])
            ->middleware('v2.capability:integration.write');
        Route::post('/connect-credentials', [IntegrationAccountController::class, 'connectViaCredentials'])
            ->middleware('v2.capability:integration.write');
        Route::delete('/{id}/disconnect', [IntegrationAccountController::class, 'disconnect'])
            ->middleware('v2.capability:integration.write');
        // Legacy alias
        Route::post('/sync-session', [IntegrationAccountController::class, 'syncSession']);
    });

    Route::prefix('leads')->group(function () {
        Route::get('/', [LeadController::class, 'index'])
            ->middleware('v2.capability:leads.read');
        Route::post('/search', [LeadController::class, 'search'])
            ->middleware('v2.capability:leads.search');
        Route::get('/sn/sources', [LeadController::class, 'listSnSources'])
            ->middleware('v2.capability:leads.read');
        Route::get('/sn/imported', [LeadController::class, 'listSnImportedLeads'])
            ->middleware('v2.capability:leads.read');
        Route::post('/sn/import', [LeadController::class, 'importSnLeads'])
            ->middleware('v2.capability:leads.search');
    });

    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index'])
            ->middleware('v2.capability:conversations.read');
        Route::get('/{conversationId}/messages', [ConversationController::class, 'messages'])
            ->middleware('v2.capability:conversations.read');
    });

    Route::prefix('campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])
            ->middleware('v2.capability:campaigns.read');
        Route::post('/', [CampaignController::class, 'store'])
            ->middleware('v2.capability:campaigns.write');
        Route::get('/templates', [CampaignController::class, 'templates'])
            ->middleware('v2.capability:campaigns.read');
        Route::get('/{campaignId}', [CampaignController::class, 'show'])
            ->middleware('v2.capability:campaigns.read');
        Route::post('/{campaignId}/status', [CampaignController::class, 'updateStatus'])
            ->middleware('v2.capability:campaigns.write');
        Route::post('/{campaignId}/run', [CampaignController::class, 'run'])
            ->middleware('v2.capability:campaigns.write');
        // Sequence (node_model JSON for extension runner)
        Route::get('/{campaignId}/sequence', [CampaignController::class, 'sequence'])
            ->middleware('v2.capability:campaigns.read');
        Route::post('/{campaignId}/sequence/update-node', [CampaignController::class, 'updateNode'])
            ->middleware('v2.capability:campaigns.write');
        // Leads for this campaign
        Route::get('/{campaignId}/leads', [CampaignController::class, 'leads'])
            ->middleware('v2.capability:campaigns.read');
        Route::post('/{campaignId}/leads', [CampaignController::class, 'addLeads'])
            ->middleware('v2.capability:campaigns.write');
        // Per-lead execution progress
        Route::get('/{campaignId}/progress', [CampaignController::class, 'progress'])
            ->middleware('v2.capability:campaigns.read');
        Route::get('/{campaignId}/activity', [CampaignController::class, 'activity'])
            ->middleware('v2.capability:campaigns.read');
        Route::post('/{campaignId}/progress/{leadId}', [CampaignController::class, 'updateProgress'])
            ->middleware('v2.capability:campaigns.write');
    });

    Route::prefix('autoresponses')->group(function () {
        Route::get('/', [AutoResponseController::class, 'index'])
            ->middleware('v2.capability:autoresponses.read');
        Route::post('/', [AutoResponseController::class, 'store'])
            ->middleware('v2.capability:autoresponses.write');
        Route::get('/{id}', [AutoResponseController::class, 'show'])
            ->middleware('v2.capability:autoresponses.read');
        Route::put('/{id}', [AutoResponseController::class, 'update'])
            ->middleware('v2.capability:autoresponses.write');
        Route::delete('/{id}', [AutoResponseController::class, 'destroy'])
            ->middleware('v2.capability:autoresponses.write');
    });

    Route::prefix('stats')->group(function () {
        Route::post('/mini', [ActivityController::class, 'storeMiniStats'])
            ->middleware('v2.capability:stats.write');
        Route::post('/activities', [ActivityController::class, 'storeUserActivity'])
            ->middleware('v2.capability:stats.write');
        Route::post('/sync', [ActivityController::class, 'syncMiniStats'])
            ->middleware('v2.capability:stats.read');
        Route::get('/summary', [ActivityController::class, 'summary'])
            ->middleware('v2.capability:stats.read');
        Route::get('/content-analytics', [ActivityController::class, 'contentAnalytics'])
            ->middleware('v2.capability:stats.read');
        Route::get('/content-analytics/cohorts', [ActivityController::class, 'contentCohorts'])
            ->middleware('v2.capability:stats.read');
        Route::get('/content-analytics/attribution', [ActivityController::class, 'contentAttribution'])
            ->middleware('v2.capability:stats.read');
    });

    Route::prefix('calls')->group(function () {
        Route::post('/', [CallController::class, 'store'])
            ->middleware('v2.capability:calls.write');
        Route::post('/generate-message', [CallController::class, 'generateMessage'])
            ->middleware('v2.capability:calls.write');
        Route::post('/process-reply', [CallController::class, 'processReply'])
            ->middleware('v2.capability:calls.write');
        Route::get('/{id}/status', [CallController::class, 'status'])
            ->middleware('v2.capability:calls.read');
        Route::get('/{id}/scheduling', [CallController::class, 'schedulingInfo'])
            ->middleware('v2.capability:calls.read');
        Route::get('/search-by-connection/{connectionId}', [CallController::class, 'searchByConnection'])
            ->middleware('v2.capability:calls.read');
        Route::get('/{id}/conversation', [CallController::class, 'conversation'])
            ->middleware('v2.capability:calls.read');
        Route::post('/conversation/store', [CallController::class, 'storeConversationMessage'])
            ->middleware('v2.capability:calls.write');
        Route::post('/analyze-message', [CallController::class, 'analyzeMessage'])
            ->middleware('v2.capability:calls.write');
        Route::get('/ready-to-send', [CallController::class, 'readyToSend'])
            ->middleware('v2.capability:calls.read');
        Route::post('/{id}/update-status', [CallController::class, 'updateMessageStatus'])
            ->middleware('v2.capability:calls.write');
        Route::post('/{id}/pending-message', [CallController::class, 'updatePendingMessage'])
            ->middleware('v2.capability:calls.write');
    });

    Route::prefix('reminders')->group(function () {
        Route::get('/pending', [ReminderController::class, 'pending'])
            ->middleware('v2.capability:calls.read');
        Route::post('/update-status', [ReminderController::class, 'updateStatus'])
            ->middleware('v2.capability:calls.write');
    });

    // Legacy call-campaigns API — Call Manager is CRM-only; routes kept for backward compatibility.
    Route::prefix('call-campaigns')->group(function () {
        Route::get('/', [CallCampaignController::class, 'index'])
            ->middleware('v2.capability:calls.read');
        Route::post('/', [CallCampaignController::class, 'store'])
            ->middleware('v2.capability:calls.write');
        Route::post('/{campaignId}/messages', [CallCampaignController::class, 'addMessage'])
            ->middleware('v2.capability:calls.write');
        Route::get('/ready-to-send', [CallCampaignController::class, 'readyToSend'])
            ->middleware('v2.capability:calls.read');
        Route::post('/messages/{id}/status', [CallCampaignController::class, 'updateMessageStatus'])
            ->middleware('v2.capability:calls.write');
    });

    Route::prefix('content-creator')->group(function () {
        Route::get('/scheduled-posts', [ContentCreatorController::class, 'index'])
            ->middleware('v2.capability:content.read');
        Route::post('/posts', [ContentCreatorController::class, 'store'])
            ->middleware('v2.capability:content.write');
        Route::post('/posts/{id}/update-status', [ContentCreatorController::class, 'updateStatus'])
            ->middleware('v2.capability:content.write');
    });

    Route::prefix('inspiration')->group(function () {
        Route::get('/', [InspirationController::class, 'index'])
            ->middleware('v2.capability:content.read');
        Route::post('/save-viral-post', [InspirationController::class, 'store'])
            ->middleware('v2.capability:content.write');
    });

    Route::post('/posts/generate-comment', [PostCommentController::class, 'generate'])
        ->middleware('v2.capability:content.write');

    Route::prefix('esp')->group(function () {
        Route::get('/config', [EspController::class, 'config'])
            ->middleware('v2.capability:esp.read');
        Route::post('/config', [EspController::class, 'upsert'])
            ->middleware('v2.capability:esp.write');
        Route::get('/deliveries', [EspController::class, 'deliveries'])
            ->middleware('v2.capability:esp.read');
        Route::post('/push-leads', [EspController::class, 'pushLeads'])
            ->middleware('v2.capability:esp.write');
        Route::post('/delivery-feedback', [EspController::class, 'ingestDeliveryFeedback'])
            ->middleware('v2.capability:esp.write');
    });

    Route::prefix('leads')->group(function () {
        Route::post('/export', [EspController::class, 'exportLeads'])
            ->middleware('v2.capability:esp.read');
    });

    Route::prefix('team')->group(function () {
        Route::get('/capability-templates', [TeamController::class, 'capabilityTemplates'])
            ->middleware('v2.capability:team.read');
        Route::get('/role-matrix', [TeamController::class, 'roleMatrix'])
            ->middleware('v2.capability:team.read');
        Route::post('/preview-template', [TeamController::class, 'previewTemplate'])
            ->middleware('v2.capability:team.read');
        Route::post('/bulk-apply-template', [TeamController::class, 'bulkApplyTemplate'])
            ->middleware('v2.capability:team.write');
        Route::get('/members', [TeamController::class, 'members'])
            ->middleware('v2.capability:team.read');
        Route::patch('/members/{memberId}', [TeamController::class, 'updateMember'])
            ->middleware('v2.capability:team.write');
        Route::delete('/members/{memberId}', [TeamController::class, 'removeMember'])
            ->middleware('v2.capability:team.write');
        Route::get('/invites', [TeamController::class, 'invites'])
            ->middleware('v2.capability:team.read');
        Route::post('/invites', [TeamController::class, 'invite'])
            ->middleware('v2.capability:team.write');
        Route::delete('/invites/{inviteId}', [TeamController::class, 'revokeInvite'])
            ->middleware('v2.capability:team.write');
    });

    Route::prefix('outreach')->group(function () {
        Route::post('/invite', [OutreachController::class, 'sendInvitation'])
            ->middleware(['v2.capability:outreach.write', 'v2.idempotency:outreach-invite']);
        Route::post('/start-chat', [OutreachController::class, 'startChat'])
            ->middleware(['v2.capability:outreach.write', 'v2.idempotency:outreach-start-chat']);
        Route::post('/message', [OutreachController::class, 'sendMessage'])
            ->middleware(['v2.capability:outreach.write', 'v2.idempotency:outreach-message']);
        Route::get('/invitations', [OutreachController::class, 'listInvitations'])
            ->middleware('v2.capability:outreach.read');
        Route::get('/invitations/sent', [OutreachController::class, 'listSentInvitations'])
            ->middleware('v2.capability:outreach.read');
        Route::get('/relations', [OutreachController::class, 'listRelations'])
            ->middleware('v2.capability:outreach.read');
        Route::post('/accept-invite', [OutreachController::class, 'acceptInvitation'])
            ->middleware('v2.capability:outreach.write');
        Route::post('/reject-invite', [OutreachController::class, 'rejectInvitation'])
            ->middleware('v2.capability:outreach.write');
        Route::post('/withdraw-invite', [OutreachController::class, 'withdrawInvitation'])
            ->middleware('v2.capability:outreach.write');
        Route::post('/profile-action', [OutreachController::class, 'profileAction'])
            ->middleware('v2.capability:outreach.write');
        Route::post('/resolve-attendee', [OutreachController::class, 'resolveAttendee'])
            ->middleware('v2.capability:outreach.read');
    });
});
