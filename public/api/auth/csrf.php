<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';
json_response(['csrf_token' => csrf_token()]);
