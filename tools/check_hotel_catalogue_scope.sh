#!/usr/bin/env bash
set -euo pipefail

php tests/run_manager_ai_draft_context_regression.php --hotel-knowledge-read
php tests/run_manager_ai_draft_context_regression.php --hotel-knowledge-http
php tests/run_manager_ai_draft_service_regression.php
php tests/run_manager_preparation_mcp_regression.php
