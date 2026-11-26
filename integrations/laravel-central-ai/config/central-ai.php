<?php

return [
    // PRD §09: AI_BASE_URL / AI_API_KEY. The key is a server-side secret only.
    'url' => env('AI_BASE_URL', 'https://ai.kemmytech.com'),
    'key' => env('AI_API_KEY'),
    'connect_timeout' => 5,
    'timeout' => 15,
    // Minimum seconds between gateway polls for the same request (protects quota/poll rate).
    'min_poll_interval' => 3,
    // Give up on a job this many seconds after submission (bounded deadline).
    'client_deadline' => 300,
];
