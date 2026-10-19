'use strict';
const fs = require('node:fs');
const crypto = require('node:crypto');

function seal(raw, task) {
  const batch = JSON.parse(raw.toString('utf8'));
  if (batch.ok !== true || batch.schema !== 'manager_draft_pilot_v1' ||
      batch.source.production_sha !== task.source_sha || !Array.isArray(batch.conversations) ||
      batch.conversations.length > 20 || batch.exported_count !== batch.conversations.length ||
      !Object.values(batch.effects).every(x => x === 0)) throw Error('invalid_capture');
  const aad = Buffer.from(JSON.stringify({schema:'manager_draft_pilot_encrypted_v1',
    task_token:task.task_token, source_sha:task.source_sha,
    recipient_sha256:crypto.createHash('sha256').update(task.recipient_pem).digest('hex'),
    plaintext_sha256:crypto.createHash('sha256').update(raw).digest('hex'),
    plaintext_bytes:raw.length, exported_count:batch.exported_count,
    captured_at_utc:batch.captured_at_utc}));
  const key = crypto.randomBytes(32), iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  cipher.setAAD(aad);
  const ciphertext = Buffer.concat([cipher.update(raw), cipher.final()]);
  const wrapped = crypto.publicEncrypt({key:task.recipient_pem,
    padding:crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash:'sha256'}, key);
  key.fill(0);
  return {schema:'manager_draft_pilot_encrypted_v1', aad_b64:aad.toString('base64'),
    wrapped_key_b64:wrapped.toString('base64'), iv_b64:iv.toString('base64'),
    tag_b64:cipher.getAuthTag().toString('base64'), ciphertext_b64:ciphertext.toString('base64')};
}

if (require.main === module) {
  try {
    const [rawPath, taskPath, outPath] = process.argv.slice(2);
    const raw = fs.readFileSync(rawPath);
    if (raw.length > 8_000_000) throw Error('capture_too_large');
    const result = seal(raw, JSON.parse(fs.readFileSync(taskPath, 'utf8')));
    fs.writeFileSync(outPath, JSON.stringify(result), {mode:0o600, flag:'wx'});
    raw.fill(0);
    process.stdout.write('pilot_ciphertext_ready\n');
  } catch (_) { process.stderr.write('pilot_encryption_failed\n'); process.exit(1); }
}
module.exports = {seal};
