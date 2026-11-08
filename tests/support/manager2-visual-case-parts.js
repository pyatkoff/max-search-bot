'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {caseParts} = require('../../tools/manager2_visual_shards.js');
const {SELECTION_SPEC, MIN_CASES, selection, run} = caseParts;
let passed = 0;
function test(name, action) { action(); passed++; console.log('PASS ' + name); }
const row = (title, spec = SELECTION_SPEC, browser = 'chromium') => {
  const titles = Array.isArray(title) ? title : [title];
  return {key: JSON.stringify([browser, browser, spec, titles]), spec, title: titles, project: browser};
};
const otherSpecs = [0, 1, 2].map(part => 'tests/visual/other-' + part + '.spec.js');
const allSpecs = [...otherSpecs, SELECTION_SPEC];
function source(count = 375, browser = 'chromium') {
  return [...Array.from({length: count}, (_, i) => row('selection case ' + i, SELECTION_SPEC, browser)),
    ...otherSpecs.map((spec, i) => row('other case ' + i, spec, browser))];
}
function choose(expected, part, options = {}) {
  return selection({browser: 'chromium', part, partCount: 4, selectedSpecs: allSpecs,
    wholeSpecs: part === 3 ? [SELECTION_SPEC] : [otherSpecs[part]], expected, ...options});
}
const keys = rows => rows.map(item => item.key).sort();
function exactCases(actual, expected) {
  assert.equal(new Set(actual.map(item => item.key)).size, actual.length);
  assert.deepEqual(keys(actual), keys(expected));
}
const componentSource = (count = 401, browser = 'chromium') => source(count, browser).filter(item => item.spec === SELECTION_SPEC);
function chooseComponent(expected, part, options = {}) {
  return selection({browser: 'chromium', part, partCount: 2, profile: 'tour-selection',
    selectedSpecs: [SELECTION_SPEC], wholeSpecs: [], expected, ...options});
}

test('odd and even component inventories execute every case once on each engine', () => {
  for (const browser of ['chromium', 'webkit']) for (const count of [401, 402]) {
    const expected = componentSource(count, browser);
    const parts = [0, 1].map(part => chooseComponent(expected, part, {browser}));
    exactCases(parts.flatMap(part => part.cases), expected);
    assert.deepEqual(parts.map(part => part.cases.length), [Math.ceil(count / 2), Math.floor(count / 2)]);
    assert(parts.every(part => part.split && part.profile === 'tour-selection'));
  }
});
test('component ownership depends on exact keys rather than listing order or a fixed case count', () => {
  for (const count of [2, 3, 127, 128, 401, 402]) {
    const expected = componentSource(count);
    const parts = [0, 1].map(part => chooseComponent(expected, part));
    exactCases(parts.flatMap(part => part.cases), expected);
    for (let part = 0; part < 2; part++) {
      assert.deepEqual(keys(parts[part].cases), keys(chooseComponent([...expected].reverse(), part).cases));
      assert.equal(parts[part].cases.length, part === 0 ? Math.ceil(count / 2) : Math.floor(count / 2));
    }
  }
});
test('component literal filters preserve nested titles, punctuation and Unicode', () => {
  const expected = [row(['suite.+(x)', 'Цена [250 000 ₽] (2+1)? $ / \\']), row('price'), row('price extended')];
  for (const part of [0, 1]) {
    const plan = chooseComponent(expected, part), regex = new RegExp(plan.grep);
    for (const item of expected) for (const label of [path.posix.basename(item.spec), item.spec])
      assert.equal(regex.test('chromium ' + label + ' ' + item.title.join(' ')), plan.cases.some(owned => owned.key === item.key));
  }
  assert.throws(() => chooseComponent([row(['one two', 'three']), row(['one', 'two three'])], 0), /Ambiguous/);
});
test('component inventory rejects duplicates, foreign specs, engines and malformed identities', () => {
  for (const change of [
    rows => rows.push(rows[0]),
    rows => rows.push(row('foreign', otherSpecs[0])),
    rows => { rows[0] = row('foreign engine', SELECTION_SPEC, 'webkit'); },
    rows => { rows[0].key = 'forged'; },
    rows => { rows[0].title = []; },
  ]) {
    const expected = componentSource(); change(expected);
    assert.throws(() => chooseComponent(expected, 0));
  }
});
test('component profile cannot change the four-part full contract or add whole-spec work', () => {
  for (const options of [{profile: 'unknown'}, {partCount: 4}, {part: 2}, {part: -1}, {part: 0.5},
    {selectedSpecs: allSpecs}, {wholeSpecs: [SELECTION_SPEC]}, {browser: 'firefox'}])
    assert.throws(() => chooseComponent(componentSource(), 0, options));
  for (const count of [0, 1]) assert.throws(() => chooseComponent(componentSource(count), 0));
  assert.throws(() => choose(source(), 0, {partCount: 2}));
  assert.deepEqual(choose(source(), 0, {profile: 'full'}), choose(source(), 0));
});

test('all 375 constructor cases execute once across four existing parts', () => {
  const expected = source(), parts = [0, 1, 2, 3].map(part => choose(expected, part));
  exactCases(parts.flatMap(part => part.cases), expected);
  assert.deepEqual(parts.map(part => part.cases.filter(item => item.spec === SELECTION_SPEC).length), [75, 0, 38, 262]);
  for (let part = 0; part < 3; part++) assert(parts[part].cases.some(item => item.spec === otherSpecs[part]));
  assert(!parts[1].specs.includes(SELECTION_SPEC), 'the busiest whole-spec part receives no extra selection work');
});
test('WebKit preserves the same complete selection, not a sampled second engine', () => {
  const expected = source(375, 'webkit');
  const parts = [0, 1, 2, 3].map(part => choose(expected, part, {browser: 'webkit'}));
  exactCases(parts.flatMap(part => part.cases), expected);
});
test('listing order does not change case ownership', () => {
  const expected = source();
  for (let part = 0; part < 4; part++) assert.deepEqual(keys(choose(expected, part).cases), keys(choose([...expected].reverse(), part).cases));
});
test('small constructor specs retain original whole-file ownership', () => {
  for (const count of [0, 1, MIN_CASES - 1]) {
    const expected = source(count), parts = [0, 1, 2, 3].map(part => choose(expected, part));
    assert(parts.every(part => !part.split && part.grep === null));
    exactCases(parts.flatMap(part => part.cases), expected);
    assert.equal(parts[3].cases.filter(item => item.spec === SELECTION_SPEC).length, count);
  }
});
test('an affected leaf plan does not add constructor or other unselected cases', () => {
  const expected = source();
  const parts = [0, 1, 2, 3].map(part => choose(expected, part, {selectedSpecs: [otherSpecs[1]]}));
  assert(parts.every(part => !part.split));
  exactCases(parts.flatMap(part => part.cases), expected.filter(item => item.spec === otherSpecs[1]));
  assert.equal(parts[0].specs.length, 0);
});
test('the cache spec remains outside main case partitioning', () => {
  const expected = [...source(), row('daily cache', 'tests/visual/manager-avatar-cache.spec.js')];
  const fresh = [0, 1, 2, 3].flatMap(part => choose(expected, part).cases);
  exactCases(fresh, source());
});
test('literal punctuation, Unicode, suites and prefix titles do not cross-match', () => {
  const expected = source(128);
  expected[0] = row(['suite.+(x)', 'Цена [250 000 ₽] (2+1)? $ / \\']);
  expected[1] = row('price'); expected[2] = row('price extended');
  for (let part = 0; part < 4; part++) {
    const plan = choose(expected, part), regex = new RegExp(plan.grep);
    for (const item of expected) for (const label of [path.posix.basename(item.spec), item.spec]) {
      assert.equal(regex.test('chromium ' + label + ' ' + item.title.join(' ')), plan.cases.some(owned => owned.key === item.key));
    }
  }
});
test('different suite paths with the same flattened title are refused', () => {
  const expected = source(128); expected[0] = row(['one two', 'three']); expected[1] = row(['one', 'two three']);
  assert.throws(() => choose(expected, 0), /Ambiguous full test title/);
});
test('duplicate native cases are refused rather than silently deduplicated', () => {
  const expected = source(); expected.push(expected[0]);
  assert.throws(() => choose(expected, 0), /Invalid or duplicate case/);
});
test('foreign engine and altered case identity are rejected', () => {
  for (const change of [item => {item.project = 'firefox';}, item => {item.key = 'forged';}, item => {item.title = [];}]) {
    const expected = source(); change(expected[0]); assert.throws(() => choose(expected, 0), /Invalid or duplicate case/);
  }
});
test('unsupported runner count, index and browser are rejected', () => {
  for (const options of [{partCount: 5}, {part: -1}, {part: 4}, {part: 1.5}, {browser: 'firefox'}])
    assert.throws(() => choose(source(), 0, options));
});
test('oversized and multiline title filters stop instead of truncating coverage', () => {
  assert.throws(() => choose(Array.from({length: 128}, (_, i) => row('x'.repeat(3000) + i)), 0), /oversized/);
  const expected = source(128); expected[0] = row('invalid\ntitle');
  assert.throws(() => choose(expected, 0), /Ambiguous/);
});

function exercise({listingChange, listingReportChange, execFailure, component = false, runOptions = {}, prepare} = {}) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'm2-case-parts-'));
  const plan = component ? chooseComponent(componentSource(), 0) : choose(source(), 0), calls = [];
  const reportFile = path.join(directory, 'tour-selection-browser.json');
  const filteredFile = path.join(directory, 'tour-selection-filtered.json');
  let originalListing;
  const exec = (program, args, options) => {
    calls.push({program, args: [...args], options: {...options, env: {...options.env}}});
    if (calls.length === 1) {
      const cases = plan.cases.slice(); if (listingChange) listingChange(cases);
      const listed = component ? {config: {forbidOnly: true}, cases} : {cases};
      if (listingReportChange) listingReportChange(listed);
      originalListing = JSON.stringify(listed, null, 1) + '\n\n';
      return originalListing;
    }
    if (execFailure) throw new Error('native test failed');
    return undefined;
  };
  try {
    if (prepare) prepare({reportFile, filteredFile});
    run({root: directory, browser: 'chromium', selection: plan, collectCases: report => report.cases, exactCases, exec,
      ...(component ? {forbidOnly: true, reportFile, filteredFile} : {}), ...runOptions});
    return {calls, directory, originalListing, filtered: fs.existsSync(filteredFile) ? fs.readFileSync(filteredFile, 'utf8') : null};
  } catch (error) {
    error.calls = calls; error.filteredExists = fs.existsSync(filteredFile);
    error.savedEvidence = [reportFile, filteredFile].filter(file => fs.existsSync(file)).map(file => fs.readFileSync(file, 'utf8'));
    throw error;
  }
  finally { fs.rmSync(directory, {recursive: true, force: true}); }
}
test('native filtered listing precedes one single-worker browser execution', () => {
  const {calls} = exercise(); assert.equal(calls.length, 2);
  for (const call of calls) {
    assert.equal(call.program, 'npx'); assert(call.args.includes('--workers=1'));
    assert(call.args.includes('--browser=chromium')); assert(call.args.some(value => value.startsWith('--grep=')));
    assert(!call.args.includes('--pass-with-no-tests')); assert(!call.args.some(value => value.startsWith('--retries')));
  }
  assert(calls[0].args.includes('--list')); assert(!calls[1].args.includes('--list'));
  assert.equal(calls[1].options.stdio, 'inherit');
  assert(calls[1].options.env.PLAYWRIGHT_JSON_OUTPUT_NAME.endsWith('/visual-reports/main.json'));
});
test('a missing native-listed case prevents browser execution', () => {
  assert.throws(() => exercise({listingChange: cases => cases.pop()}), error => error.calls.length === 1);
});
test('an additional native-listed case prevents browser execution', () => {
  assert.throws(() => exercise({listingChange: cases => cases.push(row('unexpected'))}), error => error.calls.length === 1);
});
test('duplicate native selection prevents browser execution', () => {
  assert.throws(() => exercise({listingChange: cases => cases.push(cases[0])}), error => error.calls.length === 1);
});
test('a real browser failure propagates without retry or replacement success', () => {
  assert.throws(() => exercise({execFailure: true}), error => error.message === 'native test failed' && error.calls.length === 2);
});
test('JSON report environment overrides cannot replace the intended current report', () => {
  const names = ['PLAYWRIGHT_JSON_OUTPUT_NAME', 'PLAYWRIGHT_JSON_OUTPUT_FILE', 'PLAYWRIGHT_JSON_OUTPUT_DIR'];
  const saved = names.map(name => process.env[name]);
  try {
    for (const name of names) process.env[name] = 'wrong';
    const {calls} = exercise();
    for (const name of names) assert.equal(calls[0].options.env[name], undefined);
    assert.equal(calls[1].options.env.PLAYWRIGHT_JSON_OUTPUT_FILE, undefined);
    assert.equal(calls[1].options.env.PLAYWRIGHT_JSON_OUTPUT_DIR, undefined);
    assert.notEqual(calls[1].options.env.PLAYWRIGHT_JSON_OUTPUT_NAME, 'wrong');
  } finally { names.forEach((name, i) => {if (saved[i] === undefined) delete process.env[name]; else process.env[name] = saved[i];}); }
});

test('component native listing and execution retain forbid-only and single-worker isolation', () => {
  const {calls, originalListing, filtered} = exercise({component: true});
  assert.equal(calls.length, 2);
  for (const call of calls) {
    assert(call.args.includes('--forbid-only')); assert(call.args.includes('--workers=1'));
    assert(call.args.includes('--browser=chromium')); assert(call.args.includes(SELECTION_SPEC));
    assert(!call.args.includes('--pass-with-no-tests')); assert(!call.args.some(value => value.startsWith('--retries')));
  }
  assert(calls[0].args.includes('--list')); assert(!calls[1].args.includes('--list'));
  assert(calls[1].options.env.PLAYWRIGHT_JSON_OUTPUT_NAME.endsWith('/tour-selection-browser.json'));
  assert.equal(filtered, originalListing, 'retain original native JSON bytes, not a reconstructed listing');
});
test('component missing, duplicated or additional filtered cases stop before execution', () => {
  for (const listingChange of [cases => cases.pop(), cases => cases.push(cases[0]), cases => cases.push(row('unexpected'))])
    assert.throws(() => exercise({component: true, listingChange}), error => error.calls.length === 1 && !error.filteredExists);
});
test('component filtered native report must actually acknowledge forbid-only', () => {
  for (const listingReportChange of [report => {report.config.forbidOnly = false;}, report => {delete report.config.forbidOnly;}])
    assert.throws(() => exercise({component: true, listingReportChange}), error => error.calls.length === 1 && !error.filteredExists);
});
test('component execution refuses a disabled forbid-only or colliding evidence outputs', () => {
  for (const runOptions of [{forbidOnly: false}, {forbidOnly: undefined}, {filteredFile: undefined},
    {reportFile: undefined}, {reportFile: 'same.json', filteredFile: 'same.json'}, {filteredFile: '../outside.json'}])
    assert.throws(() => exercise({component: true, runOptions}), error => error.calls.length === 0 && !error.filteredExists);
});
test('component browser failure preserves its validated listing without a retry', () => {
  assert.throws(() => exercise({component: true, execFailure: true}), error =>
    error.message === 'native test failed' && error.calls.length === 2 && error.filteredExists);
});
test('component execution cannot overwrite a prior native report or filtered listing', () => {
  for (const name of ['reportFile', 'filteredFile']) {
    assert.throws(() => exercise({component: true, prepare: files => fs.writeFileSync(files[name], 'original evidence')}), error =>
      error.calls.length === 0 && error.savedEvidence.length === 1 && error.savedEvidence[0] === 'original evidence');
  }
});

test('uneven constructor inventories retain exact coverage without loading the busy part', () => {
  for (const count of [128, 129, 374, 375, 376, 1001]) {
    const expected = source(count), parts = [0, 1, 2, 3].map(part => choose(expected, part));
    exactCases(parts.flatMap(part => part.cases), expected);
    assert(parts[1].cases.every(item => item.spec !== SELECTION_SPEC));
  }
});
const {namespaceScreenshots, assertScreenshotNamespace} = caseParts;
const outputDirs = ['manager2-artifacts', 'visual-artifacts', 'report-attachment-artifacts'];
function screenshotFixture(action) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'm2-screenshot-ownership-'));
  const write = (name, value) => { const file = path.join(root, name); fs.mkdirSync(path.dirname(file), {recursive:true}); fs.writeFileSync(file, value); return file; };
  try { action(root, write); } finally { fs.rmSync(root, {recursive:true, force:true}); }
}
test('identical screenshot names retain separate original bytes in component and full parts', () => {
  const results = [];
  for (const part of [0, 1, 3]) screenshotFixture((root, write) => {
    const name = 'manager2-artifacts/tour-selection-photo-correction-webkit-1440.png';
    const original = write(name, 'native bytes from part ' + part);
    const bytes = fs.readFileSync(original);
    assert.equal(namespaceScreenshots({root, part, outputDirs}), 1);
    const target = 'manager2-artifacts/case-part-' + part + '--tour-selection-photo-correction-webkit-1440.png';
    assert(!fs.existsSync(original)); assert.deepEqual(fs.readFileSync(path.join(root, target)), bytes);
    assertScreenshotNamespace([{path:target}], part); results.push(target);
  });
  assert.equal(new Set(results).size, 3);
});
test('nested screenshots and CLI captures retain all paths and non-PNG files', () => screenshotFixture((root, write) => {
  write('manager2-artifacts/nested/image.png', 'nested');
  write('visual-artifacts/layout.png', 'layout');
  write('manager2-artifacts/report.json', 'preserved');
  assert.equal(namespaceScreenshots({root, part:0, outputDirs}), 2);
  assert.equal(fs.readFileSync(path.join(root, 'manager2-artifacts/case-part-0--nested%2Fimage.png'), 'utf8'), 'nested');
  assert.equal(fs.readFileSync(path.join(root, 'visual-artifacts/case-part-0--layout.png'), 'utf8'), 'layout');
  assert.equal(fs.readFileSync(path.join(root, 'manager2-artifacts/report.json'), 'utf8'), 'preserved');
}));
test('content-addressed report attachments keep their existing shared namespace', () => screenshotFixture((root, write) => {
  const file = write('report-attachment-artifacts/' + 'a'.repeat(64) + '.png', 'attachment');
  assert.equal(namespaceScreenshots({root, part:2, outputDirs}), 0);
  assert.equal(fs.readFileSync(file, 'utf8'), 'attachment');
  assertScreenshotNamespace([{path:'report-attachment-artifacts/' + 'a'.repeat(64) + '.png'}], 2);
}));
test('a prior or foreign namespace refuses the operation before moving any file', () => screenshotFixture((root, write) => {
  const original = write('manager2-artifacts/first.png', 'first');
  write('visual-artifacts/case-part-3--collision.png', 'already saved');
  assert.throws(() => namespaceScreenshots({root, part:0, outputDirs}), /namespace already exists/);
  assert.equal(fs.readFileSync(original, 'utf8'), 'first');
  assert(!fs.existsSync(path.join(root, 'manager2-artifacts/case-part-0')));
}));
test('screenshot symlinks refuse the entire operation before partial moves', () => screenshotFixture((root, write) => {
  const original = write('manager2-artifacts/first.png', 'first');
  fs.mkdirSync(path.join(root, 'visual-artifacts'));
  fs.symlinkSync(original, path.join(root, 'visual-artifacts/linked.png'));
  assert.throws(() => namespaceScreenshots({root, part:0, outputDirs}), /symlinks/);
  assert.equal(fs.readFileSync(original, 'utf8'), 'first');
  assert(!fs.existsSync(path.join(root, 'manager2-artifacts/case-part-0')));
}));
test('a symlinked output root is not followed or silently ignored', () => screenshotFixture((root) => {
  fs.symlinkSync(path.join(root, 'missing-target'), path.join(root, 'manager2-artifacts'));
  assert.throws(() => namespaceScreenshots({root, part:0, outputDirs}), /Unsafe screenshot directory/);
}));
test('unpartitioned and foreign-part evidence remains a release failure', () => {
  for (const name of ['manager2-artifacts/image.png', 'manager2-artifacts/case-part-1--image.png'])
    assert.throws(() => assertScreenshotNamespace([{path:name}], 0), /namespace/);
  assertScreenshotNamespace([{path:'manager2-artifacts/case-part-0--image.png'}], 0);
  assert.throws(() => assertScreenshotNamespace([{path:null}], 0), /Invalid screenshot path/);
});
test('invalid namespace parameters cannot touch the file system', () => screenshotFixture((root, write) => {
  const file = write('manager2-artifacts/image.png', 'original');
  for (const part of [-1, 4, 1.5]) assert.throws(() => namespaceScreenshots({root, part, outputDirs}));
  for (const directories of [[], ['../outside'], ['manager2-artifacts', 'manager2-artifacts']])
    assert.throws(() => namespaceScreenshots({root, part:0, outputDirs:directories}));
  assert.equal(fs.readFileSync(file, 'utf8'), 'original');
}));

test('all renamed PNGs match unchanged top-level upload patterns without basename loss', () => screenshotFixture((root, write) => {
  write('manager2-artifacts/nested/image.png', 'nested');
  write('manager2-artifacts/nested%2Fimage.png', 'literal percent');
  write('manager2-artifacts/plain.png', 'plain');
  assert.equal(namespaceScreenshots({root, part:3, outputDirs}), 3);
  const files = fs.readdirSync(path.join(root, 'manager2-artifacts'), {withFileTypes:true})
    .filter(entry => entry.isFile() && entry.name.endsWith('.png')).map(entry => entry.name).sort();
  assert.deepEqual(files, ['case-part-3--nested%252Fimage.png', 'case-part-3--nested%2Fimage.png', 'case-part-3--plain.png']);
  for (const name of files) assertScreenshotNamespace([{path:'manager2-artifacts/' + name}], 3);
  assert.equal(fs.readFileSync(path.join(root, 'manager2-artifacts/case-part-3--nested%252Fimage.png'), 'utf8'), 'literal percent');
  assert.equal(fs.readFileSync(path.join(root, 'manager2-artifacts/case-part-3--nested%2Fimage.png'), 'utf8'), 'nested');
}));

console.log('Manager2 case partitions: ' + passed + ' tests passed.');
