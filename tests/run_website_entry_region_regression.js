'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
for (const relative of ['web-consultant/widget.js', 'website_consultant_widget.js']) {
  const source = fs.readFileSync(path.join(__dirname, '..', relative), 'utf8');
  const start = source.indexOf('  function advertisingEntryRegion(href){');
  const end = source.indexOf('\n  }', start) + 4;
  assert(start > 0 && end > start, relative + ' exposes the entry transport parser');
  const context = vm.createContext({URL, Set});
  vm.runInContext(source.slice(start, end) + '\nthis.parse = advertisingEntryRegion;', context);
  const parse = query => JSON.parse(JSON.stringify(context.parse('https://example.test/tours/' + query)));
  assert.deepEqual(parse('?region_id=00022'), {region_id:'22'});
  for (const key of ['start', 'startapp', 'utm_term', 'utm_content']) {
    assert.deepEqual(parse('?' + key + '=123456789_region_22_campaign_42'), {region_id:'22'});
  }
  for (const query of ['?region=22', '?yclid=123456789&region=22', '?region_id=0',
    '?region_id=-2', '?region_id=1.5', '?region_id[]=22', '?region_id={region_id}',
    '?region_id=abc', '?region_id=' + '1'.repeat(21), '?utm_term=holiday_region_22_campaign_42',
    '?region_id=22&region_id=2', '?region_id=22&start=123_region_2_campaign_42']) {
    assert.deepEqual(parse(query), {}, relative + ': ' + query);
  }
  assert.deepEqual(parse('?region=77&region_id=22'), {region_id:'22'});
  assert(source.includes("action:'start',attribution:advertisingEntryRegion(location.href)"), 'The real first-start request sends only the parsed geography');
  assert(!source.includes('entry_url:'), 'No whole browsing URL is transmitted for geography');
}
console.log('Website advertising origin: both first-start clients passed');
