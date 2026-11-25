'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {requireCheckoutTimeout, requireRecoveredHttps} = require('../tools/partial_install_1721_forward');

const original = [
  "{\"ok\":true,\"check_only\":false,\"containment_ok\":true,\"write_checked\":true,\"write_ok\":true,\"outside_document_root\":true,\"legacy_fenced\":true,\"directory_private\":true,\"file_private\":true}",
  "production_sha=3073195685c8d57808256af6836182262f25fead",
  "curl: (28) Operation timed out after 15002 milliseconds with 0 bytes received",
  "public_runtime_log_fence path=structured_events.log attempt=1 curl_exit=28 http=000 dns_s=0.239123 connect_s=0.344050 tls_s=0.453882 first_byte_s=0.000000 total_s=15.002258",
  "curl: (28) Operation timed out after 15001 milliseconds with 0 bytes received",
  "public_runtime_log_fence path=structured_events.log attempt=2 curl_exit=28 http=000 dns_s=0.235023 connect_s=0.338763 tls_s=0.448038 first_byte_s=0.000000 total_s=15.001643",
  "##[error]Process completed with exit code 28.",
  "  SYNC_OUTCOME: failure"
];
const recovered = [
  "original_guard_current_http=403",
  "curl_exit=0 connect_s=0.504365 tls_s=0.637136 first_byte_s=0.766723"
];

test('the original checkout is present and both final public HEAD requests timed out', () => {
  assert.doesNotThrow(() => requireCheckoutTimeout(original));
  const cases = [
    original.filter(line => !line.startsWith('production_sha=')),
    [...original, original[1]],
    original.filter(line => !line.includes('attempt=2')),
    original.map(line => line.replace('SYNC_OUTCOME: failure','SYNC_OUTCOME: success')),
    original.map(line => line.replace('"legacy_fenced":true','"legacy_fenced":false')),
    original.map(line => line.replace('first_byte_s=0.000000','first_byte_s=0.500000'))
  ];
  for (const lines of cases) assert.throws(() => requireCheckoutTimeout(lines), /partial-install-1721/);
});

test('the independent proof requires a positive containment status and no transport error', () => {
  assert.equal(requireRecoveredHttps(recovered),403);
  assert.equal(requireRecoveredHttps([recovered[0].replace('403','404'),recovered[1]]),404);
  for (const status of ['000','200','500']) {
    assert.throws(() => requireRecoveredHttps(['original_guard_current_http='+status,recovered[1]]));
  }
  assert.throws(() => requireRecoveredHttps([recovered[0],recovered[1].replace('curl_exit=0','curl_exit=28')]));
  assert.throws(() => requireRecoveredHttps([...recovered,recovered[0]]));
});
