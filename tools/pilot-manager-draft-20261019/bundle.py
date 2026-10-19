"""Build PHP stdin payload without copying files into the production checkout."""
import pathlib
import sys

root = pathlib.Path(__file__).resolve().parents[2]
collector = (root / 'services/ManagerDraftPilotCollector.php').read_text()
needle = "require_once __DIR__.'/ManagerAiDraftContextService.php';"
if collector.count(needle) != 1 or not collector.startswith('<?php'):
    raise SystemExit('unexpected_collector_layout')
collector = collector.replace(needle, '')
tail = pathlib.Path(__file__).with_name('capture-tail.php').read_text()
if not tail.startswith('<?php'):
    raise SystemExit('unexpected_capture_layout')
sys.stdout.write(collector + '\n' + tail.removeprefix('<?php'))
