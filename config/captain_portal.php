<?php

return [
    // Explicit opt-in after owner approval of same-origin session integration; no token issuance.
    'session_api_enabled' => (bool) env('CAPTAIN_SESSION_API_ENABLED', false),
];
