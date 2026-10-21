<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
define('OWNER_SELECTION_EMPHASIS',true);
require_once __DIR__.'/owner_tour_selection_preview.php';
