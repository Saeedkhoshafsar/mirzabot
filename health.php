<?php
// Lightweight health endpoint for container/Coolify healthchecks.
// Returns HTTP 200 + "ok" without touching the database or Telegram.
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
