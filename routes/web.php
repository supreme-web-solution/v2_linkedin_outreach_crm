<?php

use App\Http\Controllers\Web\AiMessagesWebController;
use App\Http\Controllers\Web\AnalyticsWebController;
use App\Http\Controllers\Web\AutoResponsesWebController;
use App\Http\Controllers\Web\CallsWebController;
use App\Http\Controllers\Web\CampaignsWebController;
use App\Http\Controllers\Web\CompetitorFollowersWebController;
use App\Http\Controllers\Web\ContentWebController;
use App\Http\Controllers\Web\InspirationWebController;
use App\Http\Controllers\Web\ConversationsWebController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\IntegrationWebController;
use App\Http\Controllers\Web\LeadsWebController;
use App\Http\Controllers\Web\OutreachAiWebController;
use App\Http\Controllers\Web\OutreachEnrichmentWebController;
use App\Http\Controllers\Web\OutreachImportListWebController;
use App\Http\Controllers\Web\OutreachWebController;
use App\Http\Controllers\Web\TeamWebController;
use App\Http\Controllers\Web\UnifiedInboxWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(request()->user() ? 'dashboard' : 'login');
})->name('home');

require __DIR__.'/billing.php';

Route::get('team/accept/{token}', [TeamWebController::class, 'showAcceptInvite'])->name('team.accept.show');

Route::get('book/{token}', [\App\Http\Controllers\Web\CallBookingWebController::class, 'show'])->name('book.show');
Route::post('book/{token}', [\App\Http\Controllers\Web\CallBookingWebController::class, 'store'])->name('book.store');

Route::middleware(['auth', 'verified', 'entitlement:FE'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::inertia('tutorials', 'Tutorials')->name('tutorials');

    // Competitor Active Followers (Unipile migration in progress)
    Route::get('competitor-followers', [CompetitorFollowersWebController::class, 'index'])->name('competitor-followers.index');
    Route::post('competitor-followers/fetch', [CompetitorFollowersWebController::class, 'fetch'])->name('competitor-followers.fetch');
    Route::get('competitor-followers/daily-limit', [CompetitorFollowersWebController::class, 'getDailyLimit'])->name('competitor-followers.daily-limit');
    Route::get('competitor-followers/pending-count', [CompetitorFollowersWebController::class, 'getPendingCount'])->name('competitor-followers.pending-count');
    Route::get('competitor-followers/{audienceId}', [CompetitorFollowersWebController::class, 'show'])->whereNumber('audienceId')->name('competitor-followers.show');
    Route::get('competitor-followers/{audienceId}/export', [CompetitorFollowersWebController::class, 'exportCsv'])->whereNumber('audienceId')->name('competitor-followers.export');
    Route::post('competitor-followers/{audienceId}/fetch-email', [CompetitorFollowersWebController::class, 'fetchEmail'])->whereNumber('audienceId')->name('competitor-followers.fetch-email');
    Route::post('competitor-followers/{audienceId}/fetch-email-batch', [CompetitorFollowersWebController::class, 'fetchEmailBatch'])->whereNumber('audienceId')->name('competitor-followers.fetch-email-batch');
    Route::get('competitor-followers/{audienceId}/check-email/{audienceListId}', [CompetitorFollowersWebController::class, 'checkEmail'])->whereNumber('audienceId')->name('competitor-followers.check-email');
    Route::get('competitor-followers/{audienceId}/status', [CompetitorFollowersWebController::class, 'getFetchStatus'])->whereNumber('audienceId')->name('competitor-followers.status');
    Route::delete('competitor-followers/{audienceId}/delete', [CompetitorFollowersWebController::class, 'delete'])->whereNumber('audienceId')->name('competitor-followers.delete');

    // AI Messages (aiwriter library + OpenAI)
    Route::get('ai-messages', [AiMessagesWebController::class, 'index'])->name('aiwriter.index');
    Route::get('ai-messages/new', [AiMessagesWebController::class, 'create'])->name('aiwriter.create');
    Route::post('ai-messages', [AiMessagesWebController::class, 'store'])->name('aiwriter.store');
    Route::post('ai-messages/generate', [AiMessagesWebController::class, 'generate'])->name('aiwriter.generate');
    Route::get('ai-messages/{id}/edit', [AiMessagesWebController::class, 'edit'])->whereNumber('id')->name('aiwriter.edit');
    Route::put('ai-messages/{id}', [AiMessagesWebController::class, 'update'])->whereNumber('id')->name('aiwriter.update');
    Route::delete('ai-messages/{id}', [AiMessagesWebController::class, 'destroy'])->whereNumber('id')->name('aiwriter.delete');

    // Inspiration (viral-post discovery library via RapidAPI)
    Route::get('inspiration', [InspirationWebController::class, 'index'])->name('inspiration.index');
    Route::post('inspiration/fetch', [InspirationWebController::class, 'fetch'])->name('inspiration.fetch');
    Route::post('inspiration/{id}/favorite', [InspirationWebController::class, 'toggleFavorite'])->whereNumber('id')->name('inspiration.favorite');
    Route::post('inspiration/{id}/remix', [InspirationWebController::class, 'remix'])->whereNumber('id')->name('inspiration.remix');
    Route::delete('inspiration/{id}', [InspirationWebController::class, 'destroy'])->whereNumber('id')->name('inspiration.delete');

    // Social Accounts moved to settings — see routes/settings.php

    // Leads (Audience + Sales Navigator lists, email enrichment, export)
    // listId is list_hash (e.g. search-1-eleazar) for SN, or audience_id string for Audience imports.
    $listHashPattern = '[a-zA-Z0-9\-_]+';

    Route::get('leads', [LeadsWebController::class, 'index'])->name('leads');
    Route::get('leads/daily-limit', [LeadsWebController::class, 'getDailyLimit'])->name('leads.daily-limit');
    Route::get('leads/pending-count', [LeadsWebController::class, 'getPendingCount'])->name('leads.pending-count');
    Route::put('leads/lists/{id}', [LeadsWebController::class, 'updateList'])->whereNumber('id')->name('leads.lists.update');
    Route::delete('leads/lists/{listId}', [LeadsWebController::class, 'removeList'])->where('listId', $listHashPattern)->name('leads.lists.remove');
    Route::delete('leads/bulk', [LeadsWebController::class, 'removeLeadBulk'])->name('leads.bulk-remove');
    Route::delete('leads/lead/{leadId}', [LeadsWebController::class, 'removeLead'])->whereNumber('leadId')->name('leads.lead.remove');
    Route::patch('leads/lead/{leadId}/status', [LeadsWebController::class, 'updateLeadStatus'])->whereNumber('leadId')->name('leads.lead.status');
    Route::get('leads/{listId}', [LeadsWebController::class, 'show'])->where('listId', $listHashPattern)->name('leads.show');
    Route::get('leads/{listId}/export', [LeadsWebController::class, 'export'])->where('listId', $listHashPattern)->name('leads.export');
    Route::post('leads/{listId}/fetch-email', [LeadsWebController::class, 'fetchEmail'])->where('listId', $listHashPattern)->name('leads.fetch-email');
    Route::post('leads/{listId}/fetch-email-batch', [LeadsWebController::class, 'fetchEmailBatch'])->where('listId', $listHashPattern)->name('leads.fetch-email-batch');
    Route::get('leads/{listId}/check-email/{audienceListId}', [LeadsWebController::class, 'checkEmail'])->where('listId', $listHashPattern)->whereNumber('audienceListId')->name('leads.check-email');
    Route::get('campaigns', [CampaignsWebController::class, 'index'])->name('campaigns');
    Route::get('campaigns/status-updates', [CampaignsWebController::class, 'statusUpdates'])->name('campaigns.status-updates');
    Route::get('campaigns/{id}/activity', [CampaignsWebController::class, 'activity'])->whereNumber('id')->name('campaigns.activity');
    Route::get('campaigns/create', [CampaignsWebController::class, 'create'])->name('campaigns.create');
    Route::post('campaigns', [CampaignsWebController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{id}', [CampaignsWebController::class, 'show'])->whereNumber('id')->name('campaigns.show');
    Route::get('campaigns/{id}/edit', [CampaignsWebController::class, 'edit'])->whereNumber('id')->name('campaigns.edit');
    Route::put('campaigns/{id}', [CampaignsWebController::class, 'update'])->whereNumber('id')->name('campaigns.update');
    Route::post('campaigns/{id}/activate', [CampaignsWebController::class, 'activate'])->whereNumber('id')->name('campaigns.activate');
    Route::post('campaigns/{id}/lists', [CampaignsWebController::class, 'attachList'])->whereNumber('id')->name('campaigns.lists.attach');
    Route::delete('campaigns/{id}/lists/{listId}', [CampaignsWebController::class, 'detachList'])->whereNumber('id')->whereNumber('listId')->name('campaigns.lists.detach');
    Route::delete('campaigns/{id}', [CampaignsWebController::class, 'destroy'])->whereNumber('id')->name('campaigns.destroy');

    Route::post('outreach/readiness-preview', [OutreachWebController::class, 'readinessPreview'])->name('outreach.readiness-preview');
    Route::post('outreach/ai/content', [OutreachAiWebController::class, 'generateContent'])->name('outreach.ai.content');
    Route::post('outreach/enrich/fetch-phones', [OutreachEnrichmentWebController::class, 'fetchPhones'])->name('outreach.enrich.fetch-phones');
    Route::post('outreach/enrich/verify-whatsapp', [OutreachEnrichmentWebController::class, 'verifyWhatsApp'])->name('outreach.enrich.verify-whatsapp');
    Route::post('outreach/enrich/resolve-handles', [OutreachEnrichmentWebController::class, 'resolveHandles'])->name('outreach.enrich.resolve-handles');
    Route::get('outreach/import-lists/template', [OutreachImportListWebController::class, 'template'])->name('outreach.import-lists.template');
    Route::post('outreach/import-lists', [OutreachImportListWebController::class, 'store'])->name('outreach.import-lists.store');
    Route::get('outreach/import-lists', [OutreachImportListWebController::class, 'index'])->name('outreach.import-lists.index');
    Route::get('outreach', [OutreachWebController::class, 'index'])->name('outreach');
    Route::get('outreach/create', [OutreachWebController::class, 'create'])->name('outreach.create');
    Route::post('outreach', [OutreachWebController::class, 'store'])->name('outreach.store');
    Route::get('outreach/{id}', [OutreachWebController::class, 'show'])->whereNumber('id')->name('outreach.show');
    Route::get('outreach/{id}/edit', [OutreachWebController::class, 'edit'])->whereNumber('id')->name('outreach.edit');
    Route::put('outreach/{id}', [OutreachWebController::class, 'update'])->whereNumber('id')->name('outreach.update');
    Route::post('outreach/{id}/activate', [OutreachWebController::class, 'activate'])->whereNumber('id')->name('outreach.activate');
    Route::get('outreach/{id}/activity', [OutreachWebController::class, 'activity'])->whereNumber('id')->name('outreach.activity');
    Route::put('outreach/{id}/channel-inbox/{channel}', [OutreachWebController::class, 'updateChannelInbox'])->whereNumber('id')->name('outreach.channel-inbox');
    Route::delete('outreach/{id}', [OutreachWebController::class, 'destroy'])->whereNumber('id')->name('outreach.destroy');

    Route::get('inbox', [UnifiedInboxWebController::class, 'index'])->name('inbox');
    Route::get('inbox/{platform}', [UnifiedInboxWebController::class, 'platform'])->where('platform', 'linkedin|whatsapp|instagram|telegram|twitter|email')->name('inbox.platform');
    Route::get('inbox/{platform}/{id}', [UnifiedInboxWebController::class, 'show'])->where('platform', 'linkedin|whatsapp|instagram|telegram|twitter|email')->whereNumber('id')->name('inbox.show');
    Route::get('inbox/{platform}/{id}/poll', [UnifiedInboxWebController::class, 'poll'])->where('platform', 'linkedin|whatsapp|instagram|telegram|twitter|email')->whereNumber('id')->name('inbox.poll');
    Route::post('inbox/{platform}/{id}/send', [UnifiedInboxWebController::class, 'send'])->where('platform', 'linkedin|whatsapp|instagram|telegram|twitter|email')->whereNumber('id')->name('inbox.send');
    Route::get('inbox/{platform}/{id}/messages/{messageId}/attachments/{attachmentId}', [UnifiedInboxWebController::class, 'attachment'])->where('platform', 'linkedin|whatsapp|instagram|telegram|twitter|email')->whereNumber('id')->name('inbox.attachment');

    Route::get('conversations', [ConversationsWebController::class, 'index'])->name('conversations');
    Route::get('conversations/flows/{flowKey}', [ConversationsWebController::class, 'flow'])->name('conversations.flow');
    Route::post('conversations/sync', fn () => redirect()
        ->route('conversations')
        ->with('success', 'Inbox sync is disabled. Launch outreach from Call Manager to start a conversation.'))
        ->name('conversations.sync');
    Route::delete('conversations/bulk', [ConversationsWebController::class, 'bulkDestroy'])->name('conversations.bulk-destroy');
    Route::delete('conversations/flows/{flowKey}', [ConversationsWebController::class, 'destroyFlow'])->name('conversations.flow.destroy');
    Route::delete('conversations/prospects/{callId}', [ConversationsWebController::class, 'destroyProspect'])->whereNumber('callId')->name('conversations.prospect.destroy');
    Route::get('conversations/{id}', [ConversationsWebController::class, 'show'])->name('conversations.show');
    Route::delete('conversations/{id}', [ConversationsWebController::class, 'destroy'])->whereNumber('id')->name('conversations.destroy');
    Route::post('conversations/{id}/send', [ConversationsWebController::class, 'send'])->whereNumber('id')->name('conversations.send');
    Route::post('conversations/{id}/track-call', [ConversationsWebController::class, 'trackCall'])->whereNumber('id')->name('conversations.track-call');
    Route::get('calls/upcoming', [CallsWebController::class, 'upcomingBooked'])->name('calls.upcoming');
    Route::get('calls', [CallsWebController::class, 'index'])->name('calls');
    Route::post('calls', [CallsWebController::class, 'store'])->name('calls.store');
    Route::post('calls/from-leads', [CallsWebController::class, 'storeFromLeads'])->name('calls.from-leads');
    Route::get('calls/lead-lists/{listId}/leads', [CallsWebController::class, 'listLeads'])->name('calls.list-leads');
    Route::post('calls/settings', [CallsWebController::class, 'updateSettings'])->name('calls.settings');
    Route::get('calls/{id}', [CallsWebController::class, 'show'])->whereNumber('id')->name('calls.show');
    Route::put('calls/{id}', [CallsWebController::class, 'update'])->whereNumber('id')->name('calls.update');
    Route::post('calls/{id}/link-conversation', [CallsWebController::class, 'linkConversation'])->whereNumber('id')->name('calls.link-conversation');
    Route::post('calls/{id}/launch-chat', [CallsWebController::class, 'launchChat'])->whereNumber('id')->name('calls.launch-chat');
    Route::post('calls/flows/{flowKey}/launch-chats', [CallsWebController::class, 'launchFlowChats'])->name('calls.launch-flow-chats');
    Route::post('calls/{id}/send', [CallsWebController::class, 'send'])->whereNumber('id')->name('calls.send');
    Route::post('calls/{id}/analyze', [CallsWebController::class, 'analyze'])->whereNumber('id')->name('calls.analyze');
    Route::post('calls/{id}/dismiss', [CallsWebController::class, 'dismiss'])->whereNumber('id')->name('calls.dismiss');
    Route::get('auto-responses', [AutoResponsesWebController::class, 'index'])->name('auto-responses');
    Route::post('auto-responses', [AutoResponsesWebController::class, 'store'])->name('auto-responses.store');
    Route::put('auto-responses/{id}', [AutoResponsesWebController::class, 'update'])->whereNumber('id')->name('auto-responses.update');
    Route::delete('auto-responses/{id}', [AutoResponsesWebController::class, 'destroy'])->whereNumber('id')->name('auto-responses.destroy');
    Route::post('auto-responses/{id}/toggle', [AutoResponsesWebController::class, 'toggle'])->whereNumber('id')->name('auto-responses.toggle');
    Route::get('content', [ContentWebController::class, 'index'])->name('content');
    Route::post('content', [ContentWebController::class, 'store'])->name('content.store');
    // Static sub-routes before {id} wildcard
    Route::post('content/ai/generate', [ContentWebController::class, 'generateAi'])->name('content.ai.generate');
    Route::post('content/ai/improve', [ContentWebController::class, 'improveAi'])->name('content.ai.improve');
    Route::post('content/ai/rewrite', [ContentWebController::class, 'rewriteAi'])->name('content.ai.rewrite');
    Route::post('content/ai/generate-image', [ContentWebController::class, 'generateImageAi'])->name('content.ai.generate-image');
    Route::put('content/{id}', [ContentWebController::class, 'update'])->name('content.update');
    Route::delete('content/{id}', [ContentWebController::class, 'destroy'])->name('content.destroy');
    Route::post('content/{id}/publish', [ContentWebController::class, 'publish'])->name('content.publish');
    Route::post('content/{id}/schedule', [ContentWebController::class, 'schedule'])->name('content.schedule');
    Route::get('team', [TeamWebController::class, 'index'])->name('team');
    Route::post('team/invite', [TeamWebController::class, 'invite'])->name('team.invite');
    Route::put('team/members/{id}', [TeamWebController::class, 'updateMember'])->whereNumber('id')->name('team.members.update');
    Route::delete('team/members/{id}', [TeamWebController::class, 'removeMember'])->whereNumber('id')->name('team.members.remove');
    Route::delete('team/invites/{id}', [TeamWebController::class, 'revokeInvite'])->whereNumber('id')->name('team.invites.revoke');
    Route::post('team/invites/{id}/resend', [TeamWebController::class, 'resendInvite'])->whereNumber('id')->name('team.invites.resend');
    Route::post('team/switch/{orgId}', [TeamWebController::class, 'switchOrganization'])->whereNumber('orgId')->name('team.switch');
    Route::post('team/accept/{token}', [TeamWebController::class, 'acceptInvite'])->name('team.accept');
    Route::get('analytics', [AnalyticsWebController::class, 'index'])->name('analytics');
    Route::get('integrations', [IntegrationWebController::class, 'index'])->name('integrations');
    Route::post('integrations/unipile/hosted-auth', [IntegrationWebController::class, 'startUnipileHostedAuth'])->name('integrations.unipile.hosted-auth');
    Route::post('integrations/channels/{channel}/connect', [IntegrationWebController::class, 'startChannelHostedAuth'])->name('integrations.channels.connect');
    Route::delete('integrations/channels/{channel}/disconnect', [IntegrationWebController::class, 'disconnectChannel'])->name('integrations.channels.disconnect');
    Route::post('integrations/unipile/cookie', [IntegrationWebController::class, 'connectUnipileCookie'])->name('integrations.unipile.cookie');
    Route::post('integrations/unipile/verify', [IntegrationWebController::class, 'verifyUnipile'])->name('integrations.unipile.verify');
    Route::delete('integrations/unipile/{id}', [IntegrationWebController::class, 'disconnectUnipile'])->whereNumber('id')->name('integrations.unipile.disconnect');
    Route::post('integrations/esp', [IntegrationWebController::class, 'storeEsp'])->name('integrations.esp.store');
    Route::post('integrations/esp/{id}/toggle', [IntegrationWebController::class, 'toggleEsp'])->whereNumber('id')->name('integrations.esp.toggle');
    Route::delete('integrations/esp/{id}', [IntegrationWebController::class, 'destroyEsp'])->whereNumber('id')->name('integrations.esp.destroy');
});

require __DIR__.'/admin.php';

require __DIR__.'/settings.php';
