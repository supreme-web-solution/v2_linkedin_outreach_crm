<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\ConversionNextActionService;
use App\V2\Ai\Services\ConversionStageService;
use App\V2\Ai\Services\InboxClassificationService;
use App\V2\Ai\Services\ProspectMemoryService;
use App\V2\Ai\Services\WorkspaceContextService;
use PHPUnit\Framework\TestCase;

class ConversionIntelligenceMatrixTest extends TestCase
{
    public function test_fifty_plus_business_and_user_conversation_paths(): void
    {
        $brain = $this->brain();
        $classifier = new InboxClassificationService;
        $ran = 0;

        foreach ($this->scenarios() as $name => $row) {
            $classification = $classifier->classifyWithKeywords($row['inbound']);
            if (isset($row['expect_intent'])) {
                $this->assertSame(
                    $row['expect_intent'],
                    $classification['intent'],
                    $name.' — intent',
                );
            }

            $decision = $brain->decideFromState(
                $row['stage'],
                $classification,
                $row['assets'],
                $row['offered'] ?? [],
            );

            $this->assertSame($row['action'], $decision['action'], $name.' — action');

            if (array_key_exists('tool', $row)) {
                $this->assertSame($row['tool'], $decision['tool'], $name.' — tool');
            }
            if (array_key_exists('must_include_url', $row)) {
                $this->assertSame($row['must_include_url'], $decision['must_include_url'], $name.' — must include URL');
            }
            if (array_key_exists('forbid_links', $row)) {
                $this->assertSame($row['forbid_links'], $decision['forbid_links'], $name.' — forbid links');
            }
            if (! empty($row['asset_url'])) {
                $this->assertSame($row['asset_url'], $decision['asset_url'], $name.' — asset URL');
            }

            $ran++;
        }

        $this->assertGreaterThanOrEqual(50, $ran);
    }

    private function brain(): ConversionNextActionService
    {
        return new ConversionNextActionService(
            new InboxClassificationService,
            $this->createMock(ConversionStageService::class),
            $this->createMock(WorkspaceContextService::class),
            $this->createMock(AiEmployeeSettingsService::class),
            $this->createMock(ProspectMemoryService::class),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scenarios(): array
    {
        $sales = 'https://socifusion.com/sales';
        $webinar = 'https://socifusion.com/webinar';
        $meeting = 'https://cal.com/socifusion/demo';
        $full = [
            'sales_page_url' => $sales,
            'webinar_url' => $webinar,
            'meeting_link' => $meeting,
        ];
        $salesOnly = ['sales_page_url' => $sales, 'webinar_url' => '', 'meeting_link' => $meeting];
        $webinarOnly = ['sales_page_url' => '', 'webinar_url' => $webinar, 'meeting_link' => $meeting];
        $meetingOnly = ['sales_page_url' => '', 'webinar_url' => '', 'meeting_link' => $meeting];
        $noMeeting = ['sales_page_url' => $sales, 'webinar_url' => $webinar, 'meeting_link' => null];
        $none = ['sales_page_url' => '', 'webinar_url' => '', 'meeting_link' => null];

        $q = ConversionStageService::STAGE_QUALIFYING;
        $open = ConversionStageService::STAGE_OPENING;
        $asset = ConversionStageService::STAGE_OFFERED_ASSET;
        $meet = ConversionStageService::STAGE_OFFERED_MEETING;
        $won = ConversionStageService::STAGE_WON;

        return [
            'agency_owner_referrals_first_reply' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Mostly referrals right now.',
                'expect_intent' => 'qualifying_answer',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'tool' => 'draft_reply', 'forbid_links' => true,
            ],
            'saas_founder_yes_we_do_outbound' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Yes, we do outbound — mostly cold email.',
                'expect_intent' => 'qualifying_answer',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'forbid_links' => true,
            ],
            'coach_wants_predictable_leads' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => "Yeah, I'd definitely like more predictable leads.",
                'expect_intent' => 'interested',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'tool' => 'draft_reply', 'must_include_url' => true, 'asset_url' => $sales,
            ],
            'consultant_tell_me_more' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Tell me more about how this works for agencies.',
                'expect_intent' => 'wants_info',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'must_include_url' => true, 'asset_url' => $sales,
            ],
            'fitness_coach_wants_webinar' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Can I watch a walkthrough of how it works?',
                'expect_intent' => 'wants_watch',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
                'tool' => 'draft_reply', 'must_include_url' => true, 'asset_url' => $webinar,
            ],
            'realtor_asks_to_book' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => "Let's hop on a call next week.",
                'expect_intent' => 'meeting_request',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'tool' => 'book_meeting', 'asset_url' => $meeting,
            ],
            'dentist_pricing' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'How much does this cost for a small clinic?',
                'expect_intent' => 'wants_info',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'asset_url' => $sales,
            ],
            'ecommerce_brand_send_the_page' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Can you send me the page?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'asset_url' => $sales,
            ],
            'recruiter_opt_out' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Please unsubscribe me',
                'expect_intent' => 'opt_out',
                'action' => ConversionNextActionService::ACTION_OPT_OUT,
            ],
            'accountant_next_quarter' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Maybe later — next quarter is crazy.',
                'expect_intent' => 'timing',
                'action' => ConversionNextActionService::ACTION_NURTURE,
                'tool' => 'draft_reply',
            ],
            'lawyer_not_interested' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Not interested, wrong person.',
                'expect_intent' => 'objection',
                'action' => ConversionNextActionService::ACTION_NURTURE,
            ],
            'agency_after_sales_page_still_unsure' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'I looked at it — still not sure this is for us.',
                'expect_intent' => 'not_convinced',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'tool' => 'book_meeting',
            ],
            'saas_after_webinar_wants_to_talk' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['webinar'],
                'inbound' => 'Watched it. Can we schedule a demo?',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'coach_after_sales_page_asks_webinar' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'Got a webinar I can watch?',
                'expect_intent' => 'wants_watch',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
                'asset_url' => $webinar,
            ],
            'consultant_after_webinar_asks_for_page' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['webinar'],
                'inbound' => 'Can you send me the sales page too?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'asset_url' => $sales,
            ],
            'meeting_already_sent_confirm_only' => [
                'stage' => $meet, 'assets' => $full,
                'inbound' => 'What timezone is that calendar?',
                'action' => ConversionNextActionService::ACTION_CONFIRM_MEETING,
                'tool' => 'draft_reply',
            ],
            'won_customer_no_sell' => [
                'stage' => $won, 'assets' => $full,
                'inbound' => 'Tell me more about add-ons.',
                'action' => ConversionNextActionService::ACTION_NURTURE,
            ],
            'soft_yes_does_not_pitch' => [
                'stage' => $open, 'assets' => $full,
                'inbound' => 'Yes',
                'expect_intent' => 'interested',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'forbid_links' => true,
            ],
            'no_sales_page_falls_to_webinar' => [
                'stage' => $q, 'assets' => $webinarOnly,
                'inbound' => 'Tell me more',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
                'asset_url' => $webinar,
            ],
            'no_assets_hard_interest_books_meeting' => [
                'stage' => $q, 'assets' => $meetingOnly,
                'inbound' => "I'd like more predictable leads.",
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'wants_watch_no_webinar_uses_sales' => [
                'stage' => $q, 'assets' => $salesOnly,
                'inbound' => 'Can I see a demo of how it works?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'asset_url' => $sales,
            ],
            'meeting_ask_without_link_proposes_times' => [
                'stage' => $q, 'assets' => $noMeeting,
                'inbound' => "Let's book a time this week.",
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'tool' => 'draft_reply',
            ],
            'no_assets_at_all_stays_qualify' => [
                'stage' => $q, 'assets' => $none,
                'inbound' => 'Tell me more',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
            ],
            'instagram_dm_style_referrals' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'haha mostly content and word of mouth tbh',
                'expect_intent' => 'qualifying_answer',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'forbid_links' => true,
            ],
            'linkedin_professional_pricing' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Could you share pricing for a 12-person sales team?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'whatsapp_casual_book' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'yo can we hop on zoom tomorrow',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'real_estate_team_inbound_mix' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Most new clients come inbound from Zillow.',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
            ],
            'b2b_saas_plg_sounds_useful' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'That would be useful for our AE team.',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'course_creator_wants_video' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Do you have a video I can watch?',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
            ],
            'clinic_owner_schedule_demo' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Can we schedule a demo next Tuesday?',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'msp_already_using_competitor' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'We already use something similar.',
                'action' => ConversionNextActionService::ACTION_NURTURE,
            ],
            'agency_after_asset_pricing_question' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'How much is it after I looked at the page?',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'startup_founder_how_can_you_help' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'How can you help a 4-person founding team?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'hr_lead_not_really_doing_outbound' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => "We haven't found a good way to do outbound consistently.",
                'action' => ConversionNextActionService::ACTION_QUALIFY,
            ],
            'photographer_ig_interested_soft' => [
                'stage' => $open, 'assets' => $full,
                'inbound' => 'yeah sure',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'forbid_links' => true,
            ],
            'fractional_cmo_yes_please' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Yes please — send it over.',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'construction_firm_book_calendar' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Send me your calendar and we can pick a time.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'nonprofit_director_busy_right_now' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Busy right now, circle back.',
                'action' => ConversionNextActionService::ACTION_NURTURE,
            ],
            'fintech_after_webinar_still_questions' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['webinar'],
                'inbound' => 'I have a question about compliance after watching.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'local_gym_wants_info_no_sales_uses_webinar' => [
                'stage' => $q, 'assets' => $webinarOnly,
                'inbound' => 'Can you tell me more?',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
            ],
            'insurance_broker_opening_neutral' => [
                'stage' => $open, 'assets' => $full,
                'inbound' => 'Got your note.',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'forbid_links' => true,
            ],
            'dev_shop_sounds_good_soft' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Sounds good',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
                'forbid_links' => true,
            ],
            'media_buyer_interested_in_outbound' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Interested in seeing how you do outbound for agencies.',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'app_booking_meeting_link' => [
                'stage' => $q, 'assets' => [
                    'sales_page_url' => $sales,
                    'webinar_url' => $webinar,
                    'meeting_link' => 'app_booking',
                ],
                'inbound' => 'Can we book a meeting Friday?',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'tool' => 'book_meeting',
            ],
            'offered_asset_lukewarm_ok_books_meeting' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'Ok.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'tool' => 'book_meeting',
                'asset_url' => $meeting,
            ],
            'pricing_with_webinar_only_uses_webinar' => [
                'stage' => $q, 'assets' => $webinarOnly,
                'inbound' => 'How much does this cost?',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
                'asset_url' => $webinar,
            ],
            'watch_ask_with_sales_only_uses_sales' => [
                'stage' => $q, 'assets' => $salesOnly,
                'inbound' => 'Do you have a webinar I can watch?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'asset_url' => $sales,
            ],
            'will_look_before_asset_sends_page' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => "I'll take a look",
                'expect_intent' => 'will_review',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
                'asset_url' => $sales,
            ],
            'will_look_after_asset_is_cold_feet_meeting' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => "Thanks, I'll take a look.",
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'asset_url' => $meeting,
            ],
            'think_about_it_after_webinar_books_meeting' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['webinar'],
                'inbound' => 'Let me think about it.',
                'expect_intent' => 'not_convinced',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'asset_url' => $meeting,
            ],
            'maybe_later_after_sales_page_books_meeting' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'Maybe later — next quarter is crazy.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'hard_no_after_asset_stays_nurture' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'Not interested, wrong person.',
                'action' => ConversionNextActionService::ACTION_NURTURE,
            ],
            'tell_me_more_after_sales_without_other_ask_books_meeting' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'Tell me more about pricing after I looked at it.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
                'asset_url' => $meeting,
            ],
            'wedding_planner_word_of_mouth' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Almost all of it is word of mouth.',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
            ],
            'seo_agency_pipeline_question' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Our pipeline is lumpy — mostly inbound.',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
            ],
            'saas_cs_lead_would_love_a_look' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Would love a look at what you built.',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'coach_lets_do_it' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => "Let's do it",
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'boutique_hotel_watch_webinar_after_page' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'Is there a webinar I can watch tonight?',
                'action' => ConversionNextActionService::ACTION_SHARE_WEBINAR,
            ],
            'solar_installer_pick_a_time' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Happy to pick a time this week.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'copywriter_not_a_fit' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Not a fit for us.',
                'action' => ConversionNextActionService::ACTION_NURTURE,
            ],
            'logistics_ops_send_info' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => 'Can you share more info?',
                'action' => ConversionNextActionService::ACTION_SHARE_SALES_PAGE,
            ],
            'clinic_after_asset_interested' => [
                'stage' => $asset, 'assets' => $full, 'offered' => ['sales_page'],
                'inbound' => 'I looked at the page — still digesting.',
                'action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ],
            'empty_message_stays_qualify' => [
                'stage' => $q, 'assets' => $full,
                'inbound' => '   ',
                'action' => ConversionNextActionService::ACTION_QUALIFY,
            ],
        ];
    }
}
