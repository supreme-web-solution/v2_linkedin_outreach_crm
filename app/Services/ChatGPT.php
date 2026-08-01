<?php

namespace App\Services;

use App\V2\Services\OpenAiUserError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatGPT
{
    protected $params;
    protected $token;
    protected $temperature;
    protected $max_token;
    
    protected $ai_types = [
        'first_cold_email' => [
            'prompt' => 'Write a cold email to a prospect about: %s and please make it %s and comprehensive'
        ],
        'linkedin_connection_message' => [
            'prompt' => 'Write a linkedin connection message to someone: %s'
        ],
        'personalized_ice_breaker' => [
            'prompt' => 'write a personilized ice-breaker message for a business prospect'
        ],
        'linkedin_post' => [
            'prompt' => 'Write a linkedin post about: %s '
        ],
        'book_call_message' => [
            'prompt' => 'Write a professional LinkedIn message to book a call with %s from %s in the %s industry. Make it personalized, professional, and include a clear call-to-action for scheduling a meeting. Keep it under 200 words.'
        ]
    ];

    public function __construct($params = null)
    {
        $this->params = $params;
        $this->token = config('services.chatgpt.key');
        $this->temperature = 0.3; // Lower temperature for more consistent analysis
        $this->max_token = 2000; // Increased for longer LinkedIn posts
    }

    public function generate()
    {
        $idea = $this->params['idea'] ?? '';
        $prompt = 'Write answer in ' . ($this->params['language'] ?? 'English') . '. ';

        if($this->params['aitype'] == 'first_cold_email'){
            $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], $idea, $this->params['write_style']);
        
        }elseif($this->params['aitype'] == 'linkedin_connection_message') {

            switch ($this->params['connection_message_type']) {
                case 'location':
                    $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], 'from '. $this->params['location']);
                    break;
                
                case 'industry':
                    $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], 'who is in '. $this->params['industry'] . 'industry');
                    break;

                case 'jobtitle':
                    $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], 'who is into '. $this->params['jobtitle']);
                    break;

                case 'random':
                    $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], $idea);
                    break;

                case 'mutual_connection':
                    $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], 'we share same mutual connections');
                    break;

                default:
                    $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], 'we share same mutual interest');
                    break;
            }

        }elseif($this->params['aitype'] == 'linkedin_post') {
            $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], $idea);
        }elseif($this->params['aitype'] == 'book_call_message') {
            $prompt .= sprintf($this->ai_types[$this->params['aitype']]['prompt'], 
                $this->params['recipient_name'] ?? 'a prospect',
                $this->params['company'] ?? 'their company',
                $this->params['industry'] ?? 'their industry'
            );
        }else{
            $prompt .= $this->ai_types[$this->params['aitype']]['prompt'];
        }

        // Check moderation
        $this->checkModeration($prompt);

        // Generate content
        return $this->generateContent($prompt);
    }

    public function checkModeration($prompt)
    {
        $moderation = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json'
        ])
            ->post('https://api.openai.com/v1/moderations', [
                'input' => $prompt,
            ])
            ->throw()
            ->json();

        if($moderation['results'][0]['flagged'] == true) {
            $categories = $moderation['results'][0]['categories'];
            $flagged = '';

            foreach($categories as $category) {
                if ($categories[$category] == true){
                    $flagged .= $category + ' ';
                }
            }

            throw new \Exception("Your idea was flagged as {$flagged}. kindly adjust it and regenerate.", 1);
        }
    }

    public function generateContent($prompt)
    {
        Log::info('🤖 ChatGPT generateContent called', [
            'prompt' => $prompt,
            'model' => 'gpt-4o-mini',
            'max_tokens' => $this->max_token,
            'temperature' => $this->temperature
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an expert LinkedIn conversation analyst specializing in lead qualification and call scheduling. You understand human communication patterns, context, and subtle cues. Your primary goal is to help schedule meetings with prospects. Analyze messages intelligently by understanding the underlying intent, sentiment, and context rather than relying on keyword matching. Always look for opportunities to suggest or schedule meetings. Provide natural, human-like responses without placeholders or brackets. Focus on moving conversations toward scheduling calls or meetings.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $this->max_token,
                    'temperature' => $this->temperature,
                    'n' => 1,
                ])
                ->throw()
                ->json();
                
            Log::info('✅ ChatGPT API response successful', [
                'response' => $response
            ]);
        } catch (\Throwable $th) {
            Log::error('❌ ChatGPT API call failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            throw new \RuntimeException(OpenAiUserError::fromThrowable($th), (int) $th->getCode(), $th);
        }

        $words = 0;

        if($response['choices']) {
            $content = '';
            foreach ($response['choices'] as $key => $value) {
                // Chat Completions API returns content in message.content instead of text
                $text = $value['message']['content'] ?? '';
                $content .= trim($text) . "\r\n\r\n";
                $words += count(explode(" ", trim($text)));
            }
        }else {
            $text = $response['choices'][0]['message']['content'] ?? '';
            $content = trim($text);
            $words = count(explode(" ", $content));
        }

        // Clean up the content to ensure it's valid JSON
        $content = $this->cleanJsonResponse($content);

        return [
            'content' => $content,
            'words' => $words
        ];
    }

    /**
     * Clean and validate JSON response from AI
     */
    private function cleanJsonResponse($content)
    {
        // Remove any markdown formatting
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        
        // Remove any leading/trailing whitespace
        $content = trim($content);
        
        // Try to find JSON object in the response
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $content = $matches[0];
        }
        
        // Validate JSON
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('⚠️ AI returned invalid JSON, attempting to fix', [
                'original_content' => $content,
                'json_error' => json_last_error_msg()
            ]);
            
            // Try to fix common JSON issues
            $content = str_replace(["\n", "\r"], '', $content);
            $content = preg_replace('/,\s*}/', '}', $content);
            $content = preg_replace('/,\s*]/', ']', $content);
        }
        
        return $content;
    }

    /**
     * Generate AI-powered call booking message
     */
    public function generateCallMessage($recipientName, $company = null, $industry = null)
    {
        $data = [
            'aitype' => 'book_call_message',
            'recipient_name' => $recipientName,
            'company' => $company,
            'industry' => $industry,
            'language' => 'English'
        ];

        Log::info('🤖 ChatGPT generateCallMessage called', [
            'data' => $data,
            'api_key_exists' => !empty($this->token),
            'api_key_length' => strlen($this->token ?? '')
        ]);

        $this->params = $data;
        $result = $this->generate();
        
        Log::info('🤖 ChatGPT generateCallMessage result', [
            'result' => $result
        ]);
        
        return $result;
    }

    /**
     * Analyze full conversation thread for enhanced context understanding
     */
    public function analyzeConversationThread($conversationThread, $originalMessage, $lastReply, $leadName)
    {
        Log::info('🤖 ChatGPT analyzeConversationThread called', [
            'conversation_count' => count($conversationThread),
            'lead_name' => $leadName,
            'has_original_message' => !empty($originalMessage),
            'has_last_reply' => !empty($lastReply)
        ]);

        try {
            // Build conversation summary
            $conversationSummary = $this->buildConversationSummary($conversationThread);
            
            $prompt = <<<EOD
You are an expert LinkedIn conversation analyst with deep understanding of human communication patterns, context, and conversation flow. Your PRIMARY GOAL is to help schedule meetings with prospects. Analyze this ENTIRE conversation thread to provide comprehensive insights.

IMPORTANT: Pay special attention to the conversation flow and how the lead's responses have evolved. Look for changes in their interest level, sentiment, and intent throughout the conversation.

CONVERSATION THREAD ANALYSIS:
{$conversationSummary}

ORIGINAL CALL MESSAGE: {$originalMessage}
LATEST REPLY: {$lastReply}
LEAD NAME: {$leadName}

CONVERSATION FLOW ANALYSIS:
- How has the lead's interest level changed from the first message to the latest?
- What was their initial response and how has it evolved?
- Are they showing more or less interest now compared to earlier messages?
- What specific words or phrases indicate their current state of mind?
- CRITICAL: Check if a calendar link has already been sent in this conversation
- CRITICAL: If a calendar link was already sent, do NOT send another one
- CRITICAL: If they said "thank you" after receiving a calendar link, they may have booked or are acknowledging receipt

ANALYSIS INSTRUCTIONS:
Analyze the FULL conversation context, not just the last message. Consider:

1. **Conversation Flow & Progression**:
   - How has the conversation evolved from the original message?
   - What patterns do you see in the lead's responses?
   - Are there repeated themes or concerns?
   - How has the lead's engagement level changed over time?
   - CRITICAL: If they initially said "not interested" but then said "tell me more", this shows a significant change in interest level

2. **Lead Behavior Analysis**:
   - What does the conversation reveal about the lead's communication style?
   - Are they being consistent in their responses or showing mixed signals?
   - What are their underlying concerns or interests?
   - How do they respond to different types of messages?
   - CRITICAL: Look for signs of changing interest - from decline to curiosity

3. **Context Understanding**:
   - What is the lead really thinking based on ALL their responses?
   - Are there subtle cues that only become clear when viewing the full conversation?
   - What information have they shared that might be relevant?
   - How has their sentiment evolved throughout the conversation?
   - CRITICAL: A lead who says "not interested" then "tell me more" is showing renewed interest

4. **Intent & Sentiment Evolution**:
   - How has their intent changed from message to message?
   - What is their current true sentiment considering the full context?
   - Are they showing genuine interest or just being polite?
   - What does their response pattern suggest about their decision-making process?
   - CRITICAL: If they went from "not interested" to "tell me more", their intent has clearly shifted to interested

5. **Strategic Insights for Meeting Scheduling**:
   - What approach would work best to get them to agree to a meeting?
   - What information do they need to make a decision about scheduling?
   - How can we address their underlying concerns to move toward a meeting?
   - What would be the most appropriate next step to schedule a call?
   - CRITICAL: If they said "tell me more", they want information before deciding - provide value and then suggest a meeting

6. **Meeting Scheduling Focus**:
   - Always look for opportunities to suggest or schedule meetings
   - If they show any interest, immediately suggest a meeting
   - If they have concerns, address them and then suggest a meeting
   - If they're hesitant, offer a brief meeting to discuss their concerns
   - If they're busy, suggest a quick 15-minute call
   - If they're interested, suggest a longer meeting to discuss details
   - CRITICAL: If they went from "not interested" to "tell me more", they're now interested - capitalize on this change
   - CRITICAL: If a calendar link was already sent, do NOT send another one - instead acknowledge their response and wait for them to book
   - CRITICAL: If they said "thank you" after receiving a calendar link, acknowledge their thanks and let them know you're looking forward to the call

REQUIRED OUTPUT (JSON format only - NO OTHER TEXT):
{
  "conversation_summary": "Brief summary of the conversation flow and key points",
  "lead_communication_style": "Description of how the lead communicates (direct, cautious, enthusiastic, etc.)",
  "engagement_pattern": "Analysis of how the lead's engagement has changed over time",
  "underlying_concerns": "Any concerns or hesitations the lead has expressed or implied",
  "current_intent": "available|interested|not_interested|needs_more_info|reschedule_request|busy|greeting|scheduling_request|hesitant|mixed_signals",
  "sentiment_evolution": "How sentiment has changed throughout the conversation",
  "context_insights": "Key insights that only become clear from full conversation context",
  "recommended_approach": "What approach would work best to get them to agree to a meeting",
  "next_action": "schedule_call|send_calendar|send_info|follow_up_later|end_conversation|ask_availability|address_concerns|acknowledge_thanks|wait_for_booking",
  "suggested_response": "Natural, human-like response that directly addresses their latest message. If a calendar link was already sent, acknowledge their response and wait for them to book. If no calendar link was sent and they're interested, suggest a meeting. Reference their specific words and show you understand the conversation flow. No placeholders, brackets, or generic text. Be specific and personal.",
  "lead_score": 1-10,
  "is_positive": true|false,
  "confidence_level": "high|medium|low",
  "reasoning": "Detailed explanation of your analysis considering the full conversation"
}

CRITICAL: Return ONLY valid JSON. No explanations, no markdown, no additional text. The response must be parseable JSON.

Focus on understanding the human behind ALL the messages and always look for opportunities to schedule meetings.
EOD;

            $aiAnalysis = $this->generateContent($prompt);
            $analysis = json_decode($aiAnalysis['content'], true);
            
            Log::info('🤖 Conversation Thread Analysis Result', [
                'raw_content' => $aiAnalysis['content'],
                'json_decode_result' => $analysis,
                'json_last_error' => json_last_error_msg()
            ]);
            
            // Ensure we have the required fields with fallbacks
            if (!$analysis || json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('⚠️ Conversation Thread Analysis failed, using fallback', [
                    'raw_content' => $aiAnalysis['content'],
                    'json_error' => json_last_error_msg()
                ]);
                
                $analysis = [
                    'conversation_summary' => 'Unable to analyze full conversation',
                    'lead_communication_style' => 'Unknown',
                    'engagement_pattern' => 'Unknown',
                    'underlying_concerns' => 'None identified',
                    'current_intent' => 'unknown',
                    'sentiment_evolution' => 'Unknown',
                    'context_insights' => 'Limited analysis available',
                    'recommended_approach' => 'Standard follow-up',
                    'next_action' => 'follow_up_later',
                    'suggested_response' => 'Thank you for your response. I\'ll follow up with you soon.',
                    'lead_score' => 5,
                    'is_positive' => false,
                    'confidence_level' => 'low',
                    'reasoning' => 'Analysis failed - using fallback'
                ];
            } else {
                // Validate and merge with defaults
                $analysis = array_merge([
                    'conversation_summary' => 'Conversation analyzed',
                    'lead_communication_style' => 'Professional',
                    'engagement_pattern' => 'Consistent',
                    'underlying_concerns' => 'None identified',
                    'current_intent' => 'unknown',
                    'sentiment_evolution' => 'Stable',
                    'context_insights' => 'Standard conversation flow',
                    'recommended_approach' => 'Standard follow-up',
                    'next_action' => 'follow_up_later',
                    'suggested_response' => 'Thank you for your response. I\'ll follow up with you soon.',
                    'lead_score' => 5,
                    'is_positive' => false,
                    'confidence_level' => 'medium',
                    'reasoning' => 'Analysis completed'
                ], $analysis);
                
                Log::info('✅ Conversation Thread Analysis successful', [
                    'current_intent' => $analysis['current_intent'],
                    'lead_score' => $analysis['lead_score'],
                    'is_positive' => $analysis['is_positive'],
                    'confidence_level' => $analysis['confidence_level']
                ]);
            }

            return $analysis;

        } catch (\Throwable $th) {
            Log::error('❌ Conversation Thread Analysis failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            
            // Return fallback analysis
            return [
                'conversation_summary' => 'Analysis failed',
                'lead_communication_style' => 'Unknown',
                'engagement_pattern' => 'Unknown',
                'underlying_concerns' => 'None identified',
                'current_intent' => 'unknown',
                'sentiment_evolution' => 'Unknown',
                'context_insights' => 'Analysis unavailable',
                'recommended_approach' => 'Standard follow-up',
                'next_action' => 'follow_up_later',
                'suggested_response' => 'Thank you for your response. I\'ll follow up with you soon.',
                'lead_score' => 5,
                'is_positive' => false,
                'confidence_level' => 'low',
                'reasoning' => 'Analysis failed: ' . $th->getMessage()
            ];
        }
    }

    /**
     * Build a structured summary of the conversation thread
     */
    private function buildConversationSummary($conversationThread)
    {
        if (empty($conversationThread)) {
            return "No conversation history available.";
        }

        Log::info('🔍 Building conversation summary', [
            'thread_count' => count($conversationThread),
            'thread_data' => $conversationThread
        ]);

        $summary = "CONVERSATION THREAD:\n\n";
        
        foreach ($conversationThread as $index => $message) {
            $messageType = $message['type'] ?? $message['sender'] ?? 'unknown';
            $messageText = $message['message'] ?? '';
            $timestamp = $message['timestamp'] ?? '';
            $messageTypeLabel = ucfirst($messageType);
            
            $summary .= "Message " . ($index + 1) . " ({$messageTypeLabel}):\n";
            $summary .= "Time: {$timestamp}\n";
            $summary .= "Content: {$messageText}\n";
            
            // Check if this message contains a calendar link
            if (strpos($messageText, 'schedule-call') !== false || strpos($messageText, 'calendar') !== false) {
                $summary .= "CALENDAR LINK SENT: YES\n";
            }
            
            // Add any AI analysis if present
            if (isset($message['ai_analysis']) && is_array($message['ai_analysis'])) {
                $aiAnalysis = $message['ai_analysis'];
                $summary .= "AI Analysis: Intent={$aiAnalysis['intent']}, Sentiment={$aiAnalysis['sentiment']}, Score={$aiAnalysis['lead_score']}\n";
            }
            
            $summary .= "\n---\n\n";
        }
        
        Log::info('📝 Conversation summary built', [
            'summary_length' => strlen($summary),
            'summary_preview' => substr($summary, 0, 500)
        ]);
        
        return $summary;
    }

    /**
     * Generate LinkedIn post content
     */
    public function generateLinkedInPost()
    {
        $topic = $this->params['topic'] ?? '';
        $style = $this->params['style'] ?? 'professional';
        $length = $this->params['length'] ?? 'medium';
        $templateId = $this->params['template_id'] ?? null;

        // Get template if provided
        if ($templateId) {
            $template = \App\Models\PostTemplate::find($templateId);
            if ($template) {
                $content = $template->replaceVariables(['topic' => $topic]);
                return [
                    'content' => $content,
                    'hashtags' => $this->extractHashtags($content),
                    'word_count' => str_word_count($content)
                ];
            }
        }

        // Build prompt based on style and length
        $prompt = $this->buildLinkedInPostPrompt($topic, $style, $length);

        // Note: Removed moderation check to reduce API calls - OpenAI chat models have built-in safety filters

        // Generate content with higher token limit for LinkedIn posts
        $result = $this->generateLinkedInContent($prompt);
        $content = $this->formatLinkedInPost($result['content']);
        
        // Extract hashtags
        $hashtags = $this->extractHashtags($content);
        
        return [
            'content' => $content,
            'hashtags' => $hashtags,
            'word_count' => str_word_count($content)
        ];
    }

    /**
     * Rewrite existing post content
     */
    public function rewritePost()
    {
        $content = $this->params['content'] ?? '';
        $tone = $this->params['tone'] ?? 'professional';
        $mode = $this->params['mode'] ?? null; // 'shorten' | 'expand' | null

        $instructions = "Rewrite this LinkedIn post content in a {$tone} tone while maintaining the core message and making it more engaging.";
        if ($mode === 'shorten') {
            $instructions .= " Make it significantly shorter (about 40-60% of the original length), concise, and punchy.";
        } elseif ($mode === 'expand') {
            $instructions .= " Expand it with more detail (about 140-170% of the original length), add examples or specifics where helpful.";
        }

        $prompt = $instructions . "\n\nOriginal Content:\n" . $content . "\n\nReturn only the improved post text, ready for LinkedIn.";

        // Note: Removed moderation check to reduce API calls - OpenAI chat models have built-in safety filters

        // Generate content
        $result = $this->generateContent($prompt);
        $formatted = $this->formatLinkedInPost($result['content']);

        return [
            'content' => $formatted,
            'word_count' => str_word_count($formatted)
        ];
    }

    /**
     * Expose LinkedIn formatting for non-AI content (e.g., inspiration posts).
     */
    public function formatPost(string $content): string
    {
        return $this->formatLinkedInPost($content);
    }

    /**
     * Format AI-writer content for readability (emails/messages).
     */
    public function formatAiwriterContent(string $content, ?string $aiType = null): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = str_replace('**', '', $content);

        // Break out subject line if present
        $content = preg_replace('/^\s*Subject:\s*/i', 'Subject: ', $content);
        $content = preg_replace('/(Subject:[^\n]+)\s*(Dear\b)/i', "$1\n\n$2", $content);

        // Add a clear break after greeting
        $content = preg_replace('/(Dear[^,\n]*,)\s*/i', "$1\n\n", $content);

        // Add a break before common sign-offs
        $content = preg_replace('/\s*(Warm regards|Best regards|Kind regards|Sincerely|Regards),/i', "\n\n$1,", $content);

        // Normalize spacing/newlines
        $content = preg_replace("/[ \t]+/", ' ', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

    /**
     * Build LinkedIn post prompt based on parameters
     */
    private function buildLinkedInPostPrompt($topic, $style, $length)
    {
        $lengthInstructions = [
            'short' => 'Keep it under 100 words - make it punchy and direct',
            'medium' => 'Write 150-250 words - provide value with some detail',
            'long' => 'Write 300-500 words - comprehensive and detailed'
        ];

        $styleInstructions = [
            'professional' => 'Use a professional, business-focused tone',
            'casual' => 'Use a casual, friendly, conversational tone',
            'motivational' => 'Use an inspiring, motivational tone that energizes readers',
            'educational' => 'Use an informative, teaching tone that provides clear value',
            'storytelling' => 'Use a narrative, story-driven approach with personal elements'
        ];

        $prompt = "Write a LinkedIn post about: {$topic}\n\n";
        $prompt .= "Style: {$styleInstructions[$style]}\n";
        $prompt .= "Length: {$lengthInstructions[$length]}\n\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Start with a compelling hook\n";
        $prompt .= "- Include relevant hashtags (3-5 hashtags)\n";
        $prompt .= "- End with a clear call-to-action\n";
        $prompt .= "- Make it engaging and shareable\n";
        $prompt .= "- Use line breaks for readability\n";
        $prompt .= "- Include emojis sparingly but effectively\n\n";
        $prompt .= "Write the post now:";

        return $prompt;
    }

    /**
     * Extract hashtags from content
     */
    private function extractHashtags($content)
    {
        preg_match_all('/#\w+/', $content, $matches);
        return implode(' ', array_unique($matches[0]));
    }

    /**
     * Generate post from template
     */
    public function generateFromTemplate($templateId, $variables = [])
    {
        $template = \App\Models\PostTemplate::find($templateId);
        
        if (!$template) {
            throw new \Exception('Template not found');
        }

        $content = $template->replaceVariables($variables);
        
        return [
            'content' => $content,
            'hashtags' => $this->extractHashtags($content),
            'word_count' => str_word_count($content)
        ];
    }

    /**
     * Generate LinkedIn content with higher token limit
     */
    public function generateLinkedInContent($prompt)
    {
        Log::info('🤖 ChatGPT generateLinkedInContent called', [
            'prompt' => $prompt,
            'model' => 'gpt-4o-mini',
            'max_tokens' => 3000, // Higher limit for LinkedIn posts
            'temperature' => $this->temperature
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an expert LinkedIn content creator. Create engaging, professional posts that drive engagement and provide value to the audience.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 3000, // Higher limit for LinkedIn posts
                    'temperature' => $this->temperature,
                    'n' => 1,
                ])
                ->throw()
                ->json();
                
            Log::info('✅ ChatGPT LinkedIn API response successful', [
                'response' => $response
            ]);
        } catch (\Throwable $th) {
            Log::error('❌ ChatGPT LinkedIn API call failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            throw new \RuntimeException(OpenAiUserError::fromThrowable($th), (int) $th->getCode(), $th);
        }

        $words = 0;

        if($response['choices']) {
            $content = '';
            foreach ($response['choices'] as $key => $value) {
                // Chat Completions API returns content in message.content instead of text
                $text = $value['message']['content'] ?? '';
                $content .= trim($text) . "\r\n\r\n";
                $words += count(explode(" ", trim($text)));
            }
        }else {
            $text = $response['choices'][0]['message']['content'] ?? '';
            $content = trim($text);
            $words = count(explode(" ", $content));
        }

        // Clean up the content to ensure it's valid
        $content = $this->cleanJsonResponse($content);

        return [
            'content' => $content,
            'words' => $words
        ];
    }

    /**
     * Generate multiple LinkedIn post drafts
     */
    public function generateMultipleDrafts()
    {
        $topic = $this->params['topic'] ?? '';
        $style = $this->params['style'] ?? 'professional';
        $length = $this->params['length'] ?? 'medium';
        $templateId = $this->params['template_id'] ?? null;

        $drafts = [];

        // Get template if provided (same for all drafts)
        $baseContent = null;
        if ($templateId) {
            $template = \App\Models\PostTemplate::find($templateId);
            if ($template) {
                $baseContent = $template->replaceVariables(['topic' => $topic]);
            }
        }

        // If using template, return it as the first draft
        if ($baseContent) {
            $drafts[] = [
                'content' => $baseContent,
                'hashtags' => $this->extractHashtags($baseContent),
                'word_count' => str_word_count($baseContent)
            ];
        }

        // Generate 2 drafts in ONE API call using n=2 parameter
            $prompt = $this->buildLinkedInPostPrompt($topic, $style, $length);
            
        Log::info('🤖 ChatGPT generateMultipleDrafts - Single API call for 2 drafts', [
            'topic' => $topic,
            'style' => $style,
            'length' => $length,
            'has_template' => !empty($baseContent)
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an expert LinkedIn content creator. Create engaging, professional posts that drive engagement and provide value to the audience.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 3000,
                    'temperature' => 0.8, // Higher temperature for more variation between drafts
                    'n' => 2, // Generate 2 completions in ONE API call
                ])
                ->throw()
                ->json();
                
            Log::info('✅ ChatGPT Multiple Drafts API response successful', [
                'choices_count' => count($response['choices'] ?? [])
            ]);
        } catch (\Throwable $th) {
            $errorMessage = $th->getMessage();
            
            // Check if it's a rate limit error
            $isRateLimit = str_contains(strtolower($errorMessage), 'rate limit') 
                        || str_contains(strtolower($errorMessage), 'rate_limit_exceeded')
                        || str_contains($errorMessage, '429')
                        || str_contains(strtolower($errorMessage), 'too many requests');
            
            if ($isRateLimit) {
                Log::warning('⚠️ ChatGPT Rate Limit Hit - Single API call attempted', [
                    'topic' => $topic,
                    'error' => substr($errorMessage, 0, 200)
                ]);
            } else {
            Log::error('❌ ChatGPT Multiple Drafts API call failed', [
                    'error' => $errorMessage,
                'trace' => $th->getTraceAsString()
            ]);
            }
            
            throw new \RuntimeException(OpenAiUserError::fromThrowable($th), (int) $th->getCode(), $th);
        }

        // Process the 2 completions from the single API response
        if (isset($response['choices']) && is_array($response['choices'])) {
            foreach ($response['choices'] as $choice) {
                $text = $choice['message']['content'] ?? '';
                if (!empty($text)) {
                    $content = trim($text);
                    $content = $this->cleanJsonResponse($content);
                    $content = $this->formatLinkedInPost($content);
            
            $drafts[] = [
                        'content' => $content,
                        'hashtags' => $this->extractHashtags($content),
                        'word_count' => str_word_count($content)
                    ];
                }
            }
        }

        // Ensure we have at least 2 drafts from API (if no template, we should have 2)
        // If we have template + 2 from API, we'll have 3 total
        if (empty($baseContent) && count($drafts) < 2) {
            Log::warning('⚠️ Only got ' . count($drafts) . ' draft(s) from API, expected 2', [
                'drafts_count' => count($drafts)
            ]);
        }

        Log::info('✅ Generated multiple drafts', [
            'total_drafts' => count($drafts),
            'from_template' => !empty($baseContent),
            'from_api' => count($drafts) - (!empty($baseContent) ? 1 : 0)
        ]);

        return $drafts;

        return $drafts;
    }

    /**
     * Improve existing post with specific action
     */
    public function improvePost($action, $content)
    {
        $prompts = [
            'add_hook' => "Add a compelling, attention-grabbing hook (first 1-2 sentences) to the beginning of this LinkedIn post. Make it irresistible to keep reading:\n\n{$content}\n\nReturn the FULL post with the new hook at the beginning.",
            
            'add_cta' => "Add a strong, specific call-to-action at the end of this LinkedIn post. Make it engaging and tell readers exactly what to do next:\n\n{$content}\n\nReturn the FULL post with the CTA at the end.",
            
            'expand' => "Expand this LinkedIn post by adding relevant examples, case studies, statistics, or specific details. Make it 40-60% longer while keeping it engaging and valuable:\n\n{$content}\n\nReturn the expanded post.",
            
            'make_viral' => "Rewrite this LinkedIn post to make it more viral and shareable. Use proven engagement tactics like:\n- Bold, contrarian statements\n- Curiosity gaps\n- Surprising insights\n- Emotional triggers\n- Pattern interrupts\n\nOriginal post:\n{$content}\n\nReturn the viral-optimized version.",
            
            'add_data' => "Enhance this LinkedIn post by adding relevant statistics, data points, research findings, or numbers that support the message:\n\n{$content}\n\nReturn the FULL post with data added.",
            
            'bullet_points' => "Convert the main points of this LinkedIn post into a clear, scannable format using bullet points or numbered lists:\n\n{$content}\n\nReturn the reformatted post.",
            
            'add_story' => "Add a brief personal story, anecdote, or real-life example to make this LinkedIn post more relatable and engaging:\n\n{$content}\n\nReturn the FULL post with the story woven in.",
            
            'controversial' => "Rewrite this LinkedIn post to include a thought-provoking, slightly controversial, or debate-worthy angle that sparks discussion. Make people want to comment with their opinions:\n\n{$content}\n\nReturn the controversy-enhanced version.",
            
            'add_emoji' => "Enhance this LinkedIn post by adding relevant emojis strategically to improve readability and engagement:\n\n{$content}\n\nReturn the FULL post with emojis added.",
            
            'make_concise' => "Make this LinkedIn post more concise and punchy while keeping the core message. Remove fluff and make every word count:\n\n{$content}\n\nReturn the concise version.",
            
            'repurpose' => "Repurpose this LinkedIn post content for different formats and audiences. Transform it into a fresh, engaging version that maintains the core message but presents it in a new way. Consider:\n- Different angles or perspectives\n- Alternative formats (story, list, question-based, etc.)\n- Different tone or style\n- New hooks or openings\n- Restructured flow\n\nOriginal post:\n{$content}\n\nReturn the repurposed version that feels fresh and original while keeping the valuable core message."
        ];

        $prompt = $prompts[$action] ?? $prompts['add_hook'];
        
        // Note: Removed moderation check to reduce API calls - OpenAI chat models have built-in safety filters
        
        // Generate improved content
        $result = $this->generateContent($prompt);
        $formatted = $this->formatLinkedInPost($result['content']);
        
        return [
            'content' => $formatted,
            'word_count' => str_word_count($formatted)
        ];
    }

    /**
     * Normalize AI output for clean LinkedIn formatting.
     */
    private function formatLinkedInPost(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = str_replace('**', '', $content);
        $content = str_replace('—', ' - ', $content);

        // Ensure space after emojis when followed by text
        $content = preg_replace('/([\x{1F300}-\x{1FAFF}])([A-Za-z0-9])/u', '$1 $2', $content);

        // Add a clean break before common list intros when jammed into prior sentence
        $content = preg_replace('/([.!?])\s*(Here (are|is|\'s)\b)/', "$1\n\n$2", $content);

        // Add line breaks before numbered lists or bullets if jammed
        $content = preg_replace('/([^\n])(\d+\.)\s+/', "$1\n$2 ", $content);
        $content = preg_replace('/([^\n])\s*([•\-])\s+/', "$1\n$2 ", $content);
        $content = preg_replace('/:\s*(\d+\.)\s+/', "\n\n$1 ", $content);
        $content = preg_replace('/\n(\d+\.)/', "\n\n$1", $content);
        $content = preg_replace('/\n([•\-])\s+/', "\n\n$1 ", $content);

        // Extract hashtags and move them to the end on their own line
        $hashtags = $this->extractHashtags($content);
        if (!empty($hashtags)) {
            $content = preg_replace('/\s*#\w+/', '', $content);
            $content = trim($content);
            $content .= "\n\n" . $hashtags;
        }

        // Normalize spacing/newlines
        $content = preg_replace("/[ \t]+/", ' ', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }
}