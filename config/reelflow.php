<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    | All timestamps are stored in UTC (config/app.php timezone must stay UTC).
    | This timezone is used for display and as the default for posting rules.
    */
    'display_timezone' => env('REELFLOW_TIMEZONE', 'Asia/Kolkata'),

    /*
    | horizon: Redis + Laravel Horizon workers kept alive by Supervisor (VPS).
    | cron:    no persistent processes (shared hosting): the every-minute cron
    |          starts queue workers that exit when the queue is empty.
    */
    'queue_mode' => env('REELFLOW_QUEUE_MODE', 'horizon'),

    'admin_email' => env('REELFLOW_ADMIN_EMAIL'),

    // Shown on the public privacy/terms pages required by Meta and Google.
    'operator_name' => env('REELFLOW_OPERATOR_NAME', env('APP_NAME', 'ReelFlow')),

    /*
    |--------------------------------------------------------------------------
    | Video storage
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('VIDEO_STORAGE_DISK', 'videos'),
        'thumbnail_disk' => env('THUMBNAIL_STORAGE_DISK', env('VIDEO_STORAGE_DISK', 'videos')),
        'chunk_disk' => 'local',
        'signed_url_ttl_minutes' => (int) env('SIGNED_URL_TTL_MINUTES', 120),
        'cleanup_published_after_days' => is_numeric(env('VIDEO_CLEANUP_AFTER_DAYS')) ? (int) env('VIDEO_CLEANUP_AFTER_DAYS') : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync agent uploads
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'allowed_extensions' => array_filter(explode(',', env('UPLOAD_ALLOWED_EXTENSIONS', 'mp4,mov,webm,m4v'))),
        'allowed_mime_types' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'],
        'max_file_size_mb' => (int) env('UPLOAD_MAX_FILE_SIZE_MB', 1024),
        'chunk_size_mb' => (int) env('UPLOAD_CHUNK_SIZE_MB', 8),
        'session_ttl_hours' => (int) env('UPLOAD_SESSION_TTL_HOURS', 48),
        'repeated_failure_threshold' => (int) env('UPLOAD_FAILURE_ALERT_THRESHOLD', 3),
    ],

    // Defaults for newly invited users (0 = unlimited); editable per user.
    'workspaces' => [
        'storage_limit_mb' => is_numeric(env('WORKSPACE_STORAGE_MB')) ? (int) env('WORKSPACE_STORAGE_MB') : 2048,
        'max_accounts' => is_numeric(env('WORKSPACE_MAX_ACCOUNTS')) ? (int) env('WORKSPACE_MAX_ACCOUNTS') : 5,
        'daily_ai_limit' => is_numeric(env('WORKSPACE_DAILY_AI_LIMIT')) ? (int) env('WORKSPACE_DAILY_AI_LIMIT') : 50,
    ],

    // Browser uploads (Upload page): a ZIP of videos or single video files.
    'web_uploads' => [
        'max_mb' => is_numeric(env('WEB_UPLOAD_MAX_MB')) ? (int) env('WEB_UPLOAD_MAX_MB') : 200,
        'max_videos' => is_numeric(env('WEB_UPLOAD_MAX_VIDEOS')) ? (int) env('WEB_UPLOAD_MAX_VIDEOS') : 20,
        'chunk_mb' => is_numeric(env('WEB_UPLOAD_CHUNK_MB')) ? (int) env('WEB_UPLOAD_CHUNK_MB') : 4,
        'open_per_workspace' => 10,
        'expire_hours' => 24,
        // Zip-bomb guards: total unpacked size and per-file compression ratio.
        'max_unpacked_mb' => 400,
        'max_compression_ratio' => 20,
    ],

    'devices' => [
        'offline_after_minutes' => (int) env('SYNC_DEVICE_OFFLINE_MINUTES', 30),
        'token_prefix' => 'rfd_',
    ],

    /*
    | Google Drive import: the server downloads videos from Drive folders shared
    | as "Anyone with the link" — no computer or sign-in needed. With a free
    | Google Cloud API key (Drive API enabled) the official Drive API is used
    | for listing and downloads instead of the public folder page.
    */
    'drive' => [
        'api_key' => env('GOOGLE_DRIVE_API_KEY') ?: null,
        'batch' => is_numeric(env('DRIVE_IMPORT_BATCH')) ? (int) env('DRIVE_IMPORT_BATCH') : 40,
        'max_seconds' => is_numeric(env('DRIVE_IMPORT_MAX_SECONDS')) ? (int) env('DRIVE_IMPORT_MAX_SECONDS') : 240,
        'list_every_minutes' => is_numeric(env('DRIVE_LIST_EVERY_MINUTES')) ? (int) env('DRIVE_LIST_EVERY_MINUTES') : 30,
        'max_attempts' => 3,
        'timeout' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media tooling
    |--------------------------------------------------------------------------
    */
    'media' => [
        // server: FFmpeg runs on this server (VPS).
        // agent:  the sync agent runs FFmpeg on your computer and uploads metadata,
        //         thumbnail and AI frames — for shared hosting without FFmpeg.
        'processing' => env('MEDIA_PROCESSING', 'server'),
        'agent_thumbnail_width' => 480,
        'agent_max_upload_kb' => 2048,
        'ffmpeg_path' => env('FFMPEG_PATH') ?: 'ffmpeg',
        'ffprobe_path' => env('FFPROBE_PATH') ?: 'ffprobe',
        'threads' => is_numeric(env('FFMPEG_THREADS')) ? (int) env('FFMPEG_THREADS') : null, // 1 on shared hosting
        'timeout_seconds' => (int) env('FFMPEG_TIMEOUT', 300),
        'min_duration_seconds' => (float) env('VIDEO_MIN_DURATION', 3),
        'max_duration_seconds' => (float) env('VIDEO_MAX_DURATION', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI caption generation
    |--------------------------------------------------------------------------
    */
    'ai' => [
        // Free:  gemini (Google AI Studio free tier) | groq (free tier) | ollama (self-hosted, no key)
        //        | compatible (any other OpenAI-compatible endpoint, e.g. OpenRouter free models)
        // Paid:  openai | anthropic
        // Off:   none (write captions manually)
        'provider' => env('AI_PROVIDER', 'none'),
        'model' => env('AI_MODEL'),
        // The first key; AI_API_KEY may hold a comma-separated list (see api_keys).
        'api_key' => trim(explode(',', (string) env('AI_API_KEY'))[0]) ?: null,
        // Extra keys used only when the key in use is out of quota (HTTP 429).
        // Put several in AI_API_KEY separated by commas, and/or set AI_API_KEY_2,
        // AI_API_KEY_3 ... Keys are tried in order within a single request.
        'api_keys' => array_values(array_filter(array_map('trim', array_merge(
            explode(',', (string) env('AI_API_KEY')),
            [(string) env('AI_API_KEY_2'), (string) env('AI_API_KEY_3'), (string) env('AI_API_KEY_4')],
        )), 'strlen')),
        'base_url' => env('AI_BASE_URL'),
        'timeout_seconds' => (int) env('AI_TIMEOUT', 120),
        // json_schema | json_object | none — how strictly the endpoint is asked for JSON.
        // Empty uses the preset; json_schema automatically falls back to json_object
        // if the endpoint rejects it. Output is always validated server-side.
        'json_mode' => env('AI_JSON_MODE'),
        // OpenAI-compatible "reasoning_effort" (e.g. none|low|medium). "none" makes
        // Gemini's free tier answer much faster; empty = not sent.
        'reasoning_effort' => env('AI_REASONING_EFFORT') ?: null,

        /*
        | OpenAI-compatible presets. Model names and free-tier limits change
        | often: confirm them in the provider's console and override AI_MODEL.
        */
        'presets' => [
            'gemini' => [
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'model' => 'gemini-flash-latest',
                'requires_key' => true,
                'json_mode' => 'json_schema',
                'max_tokens_field' => 'max_tokens',
                'free' => true,
            ],
            'groq' => [
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => null, // pick a current vision model from console.groq.com
                'requires_key' => true,
                'json_mode' => 'json_object',
                'max_tokens_field' => 'max_completion_tokens',
                'free' => true,
            ],
            'ollama' => [
                'base_url' => 'http://127.0.0.1:11434/v1',
                'model' => 'qwen3-vl:8b',
                'requires_key' => false,
                'json_mode' => 'json_object',
                'max_tokens_field' => 'max_tokens',
                'free' => true,
            ],
            'compatible' => [
                'base_url' => null,
                'model' => null,
                'requires_key' => false,
                'json_mode' => 'json_object',
                'max_tokens_field' => 'max_tokens',
                'free' => null, // depends on the service
            ],
            'openai' => [
                'base_url' => 'https://api.openai.com/v1',
                'model' => null,
                'requires_key' => true,
                'json_mode' => 'json_schema',
                'max_tokens_field' => 'max_completion_tokens',
                'free' => false,
            ],
        ],
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 3000),
        // Extra calls allowed per generation to correct an unusable answer.
        'repair_attempts' => (int) env('AI_REPAIR_ATTEMPTS', 1),
        'max_frames' => (int) env('AI_MAX_FRAMES', 6),
        'frame_width' => (int) env('AI_FRAME_WIDTH', 512),
        'max_video_size_mb' => (int) env('AI_MAX_VIDEO_SIZE_MB', 1024),
        'daily_budget_usd' => is_numeric(env('AI_DAILY_BUDGET_USD')) ? (float) env('AI_DAILY_BUDGET_USD') : null,
        'input_cost_per_mtok' => is_numeric(env('AI_INPUT_COST_PER_MTOK')) ? (float) env('AI_INPUT_COST_PER_MTOK') : null,
        'output_cost_per_mtok' => is_numeric(env('AI_OUTPUT_COST_PER_MTOK')) ? (float) env('AI_OUTPUT_COST_PER_MTOK') : null,
        'low_confidence_threshold' => (float) env('AI_LOW_CONFIDENCE_THRESHOLD', 0.6),
        'prompt_version' => env('AI_PROMPT_VERSION', 'v2'),

        'transcription' => [
            // none | groq (free tier Whisper) | compatible (any OpenAI-style
            // /audio/transcriptions, e.g. a self-hosted Whisper server) | openai (paid)
            'provider' => env('TRANSCRIPTION_PROVIDER', 'none'),
            'model' => env('TRANSCRIPTION_MODEL'),
            'api_key' => env('TRANSCRIPTION_API_KEY'),
            'base_url' => env('TRANSCRIPTION_BASE_URL'),
            'max_audio_seconds' => (int) env('TRANSCRIPTION_MAX_SECONDS', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    */
    'publishing' => [
        'max_attempts' => (int) env('PUBLISH_MAX_ATTEMPTS', 5),
        'retry_base_seconds' => (int) env('PUBLISH_RETRY_BASE_SECONDS', 60),
        'retry_max_seconds' => (int) env('PUBLISH_RETRY_MAX_SECONDS', 3600),
        'processing_poll_seconds' => (int) env('PUBLISH_PROCESSING_POLL_SECONDS', 60),
        'processing_timeout_minutes' => (int) env('PUBLISH_PROCESSING_TIMEOUT_MINUTES', 60),
        'claim_timeout_minutes' => (int) env('PUBLISH_CLAIM_TIMEOUT_MINUTES', 70),
        'dispatch_batch_size' => (int) env('PUBLISH_DISPATCH_BATCH', 25),
        'upload_chunk_mb' => (int) env('PUBLISH_UPLOAD_CHUNK_MB', 8),
        'http_timeout_seconds' => (int) env('PUBLISH_HTTP_TIMEOUT', 600),
    ],

    'scheduling' => [
        'planning_horizon_days' => (int) env('SCHEDULE_PLANNING_DAYS', 2),
        'default_slots' => array_filter(explode(',', env('SCHEDULE_DEFAULT_SLOTS', '08:30,11:00,13:30,16:30,20:00'))),
        'default_daily_limit' => (int) env('SCHEDULE_DEFAULT_DAILY_LIMIT', 5),
        'default_min_interval_minutes' => (int) env('SCHEDULE_DEFAULT_MIN_INTERVAL', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform credentials (OAuth apps)
    |--------------------------------------------------------------------------
    */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
        // Facebook Login for Business: the Configuration ID from the app's
        // Login for Business → Configurations page. When set it replaces "scope".
        'config_id' => env('META_CONFIG_ID') ?: null,
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'rupload_url' => env('META_RUPLOAD_URL', 'https://rupload.facebook.com'),
        'dialog_url' => env('META_DIALOG_URL', 'https://www.facebook.com'),
        'scopes' => [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'business_management',
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_insights',
            'read_insights',
        ],
        'instagram_upload_mode' => env('INSTAGRAM_UPLOAD_MODE', 'resumable'), // resumable | video_url
        'instagram_max_hashtags' => (int) env('INSTAGRAM_MAX_HASHTAGS', 5),
        'instagram_max_caption' => 2200,
    ],

    // YouTube is off until the Google Cloud project's upload quota allows
    // more than a handful of uploads a day (see README → YouTube quota).
    'youtube_enabled' => (bool) env('YOUTUBE_ENABLED', false),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'revoke_url' => 'https://oauth2.googleapis.com/revoke',
        'api_url' => env('YOUTUBE_API_URL', 'https://www.googleapis.com/youtube/v3'),
        'upload_url' => env('YOUTUBE_UPLOAD_URL', 'https://www.googleapis.com/upload/youtube/v3'),
        'scopes' => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
        ],
        'default_category_id' => env('YOUTUBE_CATEGORY_ID', '22'),
        'shorts_max_seconds' => (int) env('YOUTUBE_SHORTS_MAX_SECONDS', 180),
        'require_shorts_format' => (bool) env('YOUTUBE_REQUIRE_SHORTS_FORMAT', true),
        'quota_reset_timezone' => 'America/Los_Angeles',
    ],
];
