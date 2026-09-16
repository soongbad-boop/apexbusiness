<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';
start_secure_session($config);
json_response(['csrf_token' => csrf_token()]);
