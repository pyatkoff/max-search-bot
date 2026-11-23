import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {isDeepStrictEqual} from 'node:util';

const ROLES = ['hero', 'detail_left', 'detail_right'];
const HEX = /^[a-f0-9]{64}$/;
const hash = b => crypto.createHash('sha256').update(b).digest('hex');
const requireThat = (value, message) => { if (!value) throw new Error(message); };
const one = (rows, predicate, name) => {
  requireThat(Array.isArray(rows), `${name}:array_required`);
  const matches = rows.filter(predicate);
  requireThat(matches.length === 1, `${name}:expected_exactly_one`);
  return matches[0];
};

export function centeringToFocal(width, height, targetWidth, targetHeight, center) {
  requireThat([width, height, targetWidth, targetHeight].every(n => Number.isInteger(n) && n > 0), 'crop_dimensions_invalid');
  requireThat(Array.isArray(center) && center.length === 2
    && center.every(n => Number.isFinite(n) && n >= 0 && n <= 1), 'crop_center_invalid');
  const ratio = targetWidth / targetHeight;
  let cw = width, ch = height;
  if (cw / ch > ratio) cw = Math.round(ch * ratio); else ch = Math.round(cw / ratio);
  cw = Math.min(cw, width); ch = Math.min(ch, height);
  // Pillow centering is a fraction of the removable margin. Satori focal is a
  // point in the original image. Preserve alignment for the new target box.
  return [((width - cw) * center[0] + cw / 2) / width,
    ((height - ch) * center[1] + ch / 2) / height];
}

async function checkedJSON(file, expected, name) {
  requireThat(HEX.test(expected), `${name}:sha256_required`);
  const bytes = await fs.readFile(file);
  requireThat(hash(bytes) === expected, `${name}:snapshot_changed`);
  return JSON.parse(bytes);
}

async function checkPortableLineage(input, inputPath, inputSHA) {
  if (!input.source_snapshot) return inputSHA;
  const ref = input.source_snapshot;
  requireThat(typeof ref.path === 'string' && !path.isAbsolute(ref.path)
    && !ref.path.includes('..') && !ref.path.includes('://'), 'source_snapshot_path_invalid');
  const original = await checkedJSON(path.resolve(path.dirname(inputPath), ref.path), ref.sha256, 'source_input');
  requireThat(isDeepStrictEqual(ref.selected_ids, input.rows.map(r => r.hotel_id)), 'source_selection_changed');
  const {rows: ignoredRows, source_snapshot: ignoredSource, ...top} = input;
  const {rows: originalRows, ...originalTop} = original;
  requireThat(isDeepStrictEqual(top, originalTop), 'source_metadata_changed');
  for (const row of input.rows) {
    const originalRow = one(originalRows, r => r.hotel_id === row.hotel_id, 'source_hotel');
    const {photos, ...metadata} = row, {photos: originalPhotos, ...originalMetadata} = originalRow;
    requireThat(isDeepStrictEqual(metadata, originalMetadata), 'source_row_metadata_changed');
    requireThat(photos.length === originalPhotos.length, 'source_photo_count_changed');
    for (const photo of photos) {
      const originalPhoto = one(originalPhotos, p => p.role === photo.role, 'source_photo');
      const {path: localPath, original_path: originalPath, ...values} = photo;
      const {path: oldPath, ...oldValues} = originalPhoto;
      requireThat(originalPath === oldPath && isDeepStrictEqual(values, oldValues), 'source_photo_binding_changed');
    }
  }
  return ref.sha256;
}

export async function prepareSatori(args) {
  const inputPath = path.resolve(args.input);
  const runtime = path.join(path.dirname(fileURLToPath(import.meta.url)), 'satori');
  const output = path.resolve(args['output-dir']);
  try {
    await fs.lstat(output);
    throw new Error('output_directory_already_exists');
  } catch (error) { if (error.code !== 'ENOENT') throw error; }
  const input = await checkedJSON(inputPath, args['expected-input-sha256'], 'input');
  const editorial = await checkedJSON(path.resolve(args['editorial-input']), args['expected-editorial-sha256'], 'editorial');
  requireThat(input.schema_version === 1 && Array.isArray(input.rows) && input.rows.length >= 1 && input.rows.length <= 10,
    'cover_batch_must_have_1_to_10_hotels');
  const sourceSHA = await checkPortableLineage(input, inputPath, args['expected-input-sha256']);
  requireThat(editorial.schema_version === 1 && editorial.mode === 'isolated-preview'
    && editorial.publication_allowed === false && editorial.dynamic_prices === false
    && editorial.source_input_sha256 === sourceSHA && HEX.test(editorial.editorial_source_sha256),
    'editorial_policy_or_source_mismatch');
  requireThat(Array.isArray(editorial.rows) && editorial.rows.length === input.rows.length, 'editorial_row_count_mismatch');
  requireThat(input.publication_allowed !== true, 'input_not_preview_only');
  const fromRuntime = createRequire(path.join(runtime, 'package.json'));
  const sharp = fromRuntime('sharp');
  const {renderCard, planHotelLayout, REPOSITORY_ROOT} = await import(pathToFileURL(path.join(runtime, 'src/render.mjs')));
  const ids = new Set(), prepared = [];
  for (const row of input.rows) {
    requireThat(Number.isSafeInteger(row.hotel_id) && row.hotel_id > 0 && row.hotel_id < 1e9 && !ids.has(row.hotel_id), 'hotel_id_invalid_or_duplicate');
    ids.add(row.hotel_id);
    requireThat(row.publication_allowed !== true && typeof row.title === 'string' && row.title.trim().length > 0
      && row.title.length <= 160 && !/[\n\r\t]/.test(row.title), 'accepted_title_invalid');
    requireThat(Array.isArray(row.photos) && row.photos.length === 3, 'three_source_photos_required');
    const copy = one(editorial.rows, e => e.hotel_id === row.hotel_id, 'editorial_hotel');
    requireThat(copy.source?.sha256 === editorial.editorial_source_sha256,
      'editorial_source_reference_mismatch');
    requireThat(copy.title === row.title && copy.demo === false && typeof copy.location === 'string'
      && copy.location.length <= 80 && typeof copy.description === 'string' && copy.description.length <= 160,
      'editorial_identity_or_copy_mismatch');
    requireThat(!/[₽$€]|\d\s*(руб|месяц|минут|час|дн[яей])|гаранти|от\s+\d/iu.test(`${copy.title} ${copy.location} ${copy.description}`),
      'dynamic_terms_not_supported');
    const sources = [], seenSHA = new Set(), seenURL = new Set();
    for (const role of ROLES) {
      const source = one(row.photos, p => p.role === role, 'photo_role');
      requireThat(typeof source.path === 'string' && source.path.length > 0 && !source.path.includes('://'), 'local_photo_path_required');
      let url; try { url = new URL(source.source_url); } catch { throw new Error('source_url_invalid'); }
      requireThat(url.protocol === 'https:' && url.hostname && !url.username && !url.password, 'source_url_invalid');
      requireThat(HEX.test(source.sha256) && !seenSHA.has(source.sha256) && !seenURL.has(source.source_url), 'source_photos_not_distinct');
      seenSHA.add(source.sha256); seenURL.add(source.source_url);
      const bytes = await fs.readFile(path.resolve(path.dirname(inputPath), source.path));
      requireThat(hash(bytes) === source.sha256, 'accepted_source_photo_changed');
      const m = await sharp(bytes, {limitInputPixels: 40_000_000}).metadata();
      const rotated = [5, 6, 7, 8].includes(m.orientation);
      sources.push({source, bytes, width: rotated ? m.height : m.width, height: rotated ? m.width : m.height,
        sourceDimensions: [m.width, m.height], format: m.format});
    }
    const {boxes} = await planHotelLayout({title: row.title, location: copy.location,
      description: copy.description, layout: copy.layout, photos: sources});
    const photos = sources.map((s, i) => ({path: `assets/photos/${s.source.sha256}.image`, sha256: s.source.sha256,
      width: s.width, height: s.height, source_url: s.source.source_url,
      focal: centeringToFocal(s.width, s.height, ...boxes[i], s.source.crop_center ?? [.5, .5])}));
    prepared.push({row, copy, sources, card: {id: `anytour-${row.hotel_id}`, brand: 'anytour', hotel_id: row.hotel_id,
      title: row.title, location: copy.location, description: copy.description, layout: copy.layout, photos, demo: false,
      verified_identity: true}});
  }
  // A failed render must not create a receipt or silently invoke Pillow.
  await fs.mkdir(path.dirname(output), {recursive: true});
  const stage = await fs.mkdtemp(path.join(path.dirname(output), '.satori-stage-'));
  let committed = false;
  try {
    await fs.mkdir(path.join(stage, 'assets/photos'), {recursive: true});
    for (const p of prepared) for (const [i, photo] of p.card.photos.entries())
      await fs.writeFile(path.join(stage, photo.path), p.sources[i].bytes);
    const rendered = [];
    for (const p of prepared) rendered.push({...p, result: await renderCard(p.card, {assetRoot: stage})});
    const receiptRows = [], files = [], thumbnails = [];
    const write = async (name, bytes) => {
      await fs.writeFile(path.join(stage, name), bytes);
      files.push({path: name, sha256: hash(bytes), bytes: Buffer.byteLength(bytes)});
    };
    for (const p of rendered) {
      const id = p.row.hotel_id, r = p.result;
      await write(`${id}.png`, r.png); await write(`${id}.jpg`, r.jpeg);
      await write(`${id}.svg`, r.svg); await write(`${id}-768.jpg`, r.channel);
      for (const width of [320, 360]) await write(`${id}-${width}.png`, r.mobile[width]);
      thumbnails.push(await sharp(r.png).resize(300, 300).png().toBuffer());
      receiptRows.push({...p.row, source_photos: p.sources.map((s, i) => ({...s.source,
        source_bytes: s.bytes.length, source_dimensions: s.sourceDimensions, source_format: s.format,
        actual_sha256: hash(s.bytes), oriented_dimensions: [s.width, s.height],
        crop_center_semantics: 'fraction_of_removable_margin', converted_focal: p.card.photos[i].focal,
        processing: 'EXIF orientation; alignment-preserving crop; no generated image content'})),
        filename: `${id}.png`, sha256: hash(r.png), bytes: r.png.length, dimensions: r.audit.dimensions,
        layout: r.audit, editorial: structuredClone(p.copy)});
    }
    const columns = Math.min(5, rendered.length), rows = Math.ceil(rendered.length / columns);
    const contact = await sharp({create: {width: columns * 300, height: rows * 300,
      channels: 3, background: '#edf2f6'}}).composite(thumbnails.map((input, i) => ({input,
      left: (i % columns) * 300, top: Math.floor(i / columns) * 300}))).jpeg({quality: 93}).toBuffer();
    await write('contact-sheet.jpg', contact);
    const implementation = [];
    for (const file of ['src/render.mjs', 'package-lock.json', 'assets/fonts/OpenSans-Regular.ttf',
      'assets/fonts/OpenSans-Semibold.ttf', 'assets/fonts/OpenSans-Bold.ttf']) {
      const bytes = await fs.readFile(path.join(runtime, file));
      implementation.push({path: file, sha256: hash(bytes), bytes: bytes.length});
    }
    const logoPath = '.github/traffic-publish/brand/anytour-logo-white.png';
    const logoBytes = await fs.readFile(path.join(REPOSITORY_ROOT, logoPath));
    implementation.push({path: logoPath, sha256: hash(logoBytes), bytes: logoBytes.length});
    const bridgeBytes = await fs.readFile(fileURLToPath(import.meta.url));
    implementation.push({path: 'prepare-commercial-covers-satori.mjs', sha256: hash(bridgeBytes), bytes: bridgeBytes.length});
    const {rows: ignoredRows, ...metadata} = input;
    const receipt = {...metadata, state: 'PREPARED_AWAITING_VISUAL_ACCEPTANCE', isolated_preview: true,
      input_sha256: args['expected-input-sha256'], source_input_sha256: sourceSHA,
      editorial_input_sha256: args['expected-editorial-sha256'], publication_allowed: false, dynamic_prices: false,
      renderer: {engine: 'satori', satori: fromRuntime('satori/package.json').version,
        sharp: sharp.versions.sharp, libvips: sharp.versions.vips, node: process.version,
        font_status: 'Open Sans pilot fallback; Aeroport not available or approved'},
      rows: receiptRows, implementation, output_files: files, provider_calls: 0, remote_writes: 0,
      owner_visual_acceptance: false, production_installed: false};
    await fs.writeFile(path.join(stage, 'cover-receipt.json'), JSON.stringify(receipt, null, 2) + '\n');
    // A new output directory is required; never remove a previous preparation.
    try {
      await fs.lstat(output);
      throw new Error('output_directory_already_exists');
    } catch (error) { if (error.code !== 'ENOENT') throw error; }
    await fs.rename(stage, output); committed = true;
    return {ok: true, prepared: receiptRows.length, output_dir: output,
      receipt: path.join(output, 'cover-receipt.json'), renderer: 'satori', provider_calls: 0, remote_writes: 0};
  } finally { if (!committed) await fs.rm(stage, {recursive: true, force: true}); }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const keys = ['input', 'expected-input-sha256', 'output-dir', 'editorial-input', 'expected-editorial-sha256'];
    const args = {};
    for (let i = 2; i < process.argv.length; i += 2) {
      const key = process.argv[i].replace(/^--/, '');
      requireThat(process.argv[i].startsWith('--') && keys.includes(key) && !Object.hasOwn(args, key)
        && process.argv[i + 1], 'arguments_invalid');
      args[key] = process.argv[i + 1];
    }
    requireThat(keys.every(key => args[key]), 'arguments_missing');
    console.log(JSON.stringify(await prepareSatori(args)));
  } catch (error) { console.error(JSON.stringify({ok: false, error: error.message})); process.exitCode = 1; }
}
