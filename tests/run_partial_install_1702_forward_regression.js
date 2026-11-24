'use strict';
const assert = require('node:assert/strict');
const {test} = require('node:test');
const {provePartialInstall1702Forward: prove} = require('../tools/partial_install_1702_forward');

test('an untrusted installation cannot read proof data or advance the publisher', async () => {
  let count = 0;
  const api = Object.assign(async () => { count++; throw Error('unexpected API read'); },
    {jobLog: async () => { count++; throw Error('unexpected native log read'); }});
  const options = {api, pr:1702, head:'9b0a8a5a4dc252b977f95bee2b890287cdbbec1f',
    phase:'installation', source:'32155b9eb2c8606b61554951bfdc988d70bbcb39', runId:45416010115,
    event:{ref:'refs/heads/main', forced:true}};
  await assert.rejects(prove(options));
  assert.equal(count, 0);
});
