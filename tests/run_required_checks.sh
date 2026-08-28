#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo '== PHP syntax check =='
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

php tests/run_required_group.php all

echo 'ALL REQUIRED CHECKS PASSED'
