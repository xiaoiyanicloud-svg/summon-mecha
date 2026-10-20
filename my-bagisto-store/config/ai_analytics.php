<?php

return [
    'max_batch'      => 20,
    'retention_days' => 180,

    'allowed_events' => [
        'ai_page_view',
        'ai_prompt_submit',
        'ai_first_token',
        'ai_reply_done',
        'ai_reply_error',
        'ai_stop',
        'ai_retry',
        'ai_recommendation_click',
        'ai_custom_plan_click',
        'ai_copy_answer',
        'ai_history_open',
        'ai_history_search',
        'ai_voice_result',
    ],
];
