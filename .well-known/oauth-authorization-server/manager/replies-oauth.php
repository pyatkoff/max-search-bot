<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/services/ManagerPreparationOAuth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(ManagerPreparationOAuth::authorizationMetadata('replies'),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
