#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo '== PHP syntax check =='
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

checks=(
  'php tests/run_regression.php'
  'php tests/run_ai_context_regression.php'
  'php tests/run_trip_state_regression.php'
  'php tests/run_v2_architecture_regression.php'
  'php tests/run_v2_action_layer_regression.php'
  'php tests/run_integration_contracts_regression.php'
  'php tests/run_messenger_adapters_regression.php'
  'php tests/run_website_transport_regression.php'
  'php tests/run_website_attribution_health_regression.php'
  'php tests/run_website_production_smoke_regression.php'
  'php tests/run_website_rollout_regression.php'
  'php tests/run_website_origin_policy_regression.php'
  'php tests/run_telegram_webhook_regression.php'
  'php tools/telegram_smoke_test.php'
  'php tests/run_dialogue_application_regression.php'
  'php tests/run_dialogue_controller_regression.php'
  'php tests/run_callback_controller_regression.php'
  'php tests/run_dialogue_view_regression.php'
  'php tests/run_calendar_view_model_regression.php'
  'php tests/run_tour_results_regression.php'
  'php tests/run_manager_request_regression.php'
  'php tests/run_manager_visibility_regression.php'
  'php tests/run_manager_delivery_failure_regression.php'
  'php tests/run_manager_delivery_snapshot_regression.php'
  'php tests/run_manager_delivery_panel_regression.php'
  'php tests/run_manager_response_health_regression.php'
  'php tests/run_manager_push_health_regression.php'
  'php tests/run_manager_priority_regression.php'
  'php tests/run_live_session_analyzer_regression.php'
  'php tests/run_live_session_snapshot_regression.php'
  'php tests/run_live_destination_context_regression.php'
  'php tests/run_live_star_answer_regression.php'
  'php tests/run_post_tour_regression.php'
  'php tests/run_messenger_neutral_handlers_regression.php'
  'php tests/run_state_free_text_regression.php'
  'php tests/run_missing_field_questions_regression.php'
  'php tests/run_platform_boundary_regression.php'
  'php tests/run_shadow_dialogue_regression.php'
  'php tests/run_shadow_comparison_regression.php'
  'php tests/run_v2_early_action_regression.php'
  'php tests/run_state_repository_regression.php'
  'php tests/run_claim_regression.php'
  'php tests/run_directory_regression.php'
  'php tests/run_conversation_regression.php'
  'php tests/run_conversation_catalog.php'
)

for check in "${checks[@]}"; do
  echo "== $check =="
  eval "$check"
done

echo 'ALL REQUIRED CHECKS PASSED'
