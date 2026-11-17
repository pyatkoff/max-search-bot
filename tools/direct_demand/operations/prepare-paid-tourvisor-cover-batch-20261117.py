#!/usr/bin/env python3
"""Build reviewable AnyTour hotel covers from exact source photos; never publishes.

Input: commercial-expansion100-first10-publisher-caption-input-20261117.json.
Output: 1080px PNG covers plus a read-only provenance/QA receipt. Operator must
visually approve and freeze the PNG under the existing channel publisher.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import tempfile
from urllib.parse import urlsplit

from PIL import Image, ImageDraw, ImageFont, ImageOps

SIZE = (1080, 1080)
BLUE = '#2743cb'
LOGO_SHA256 = 'b27cda894047b1ecb4125eea2ecf0db9d1e1674472cad62f70edf1ef1a6c968f'
MEDIA_ROOT = re.compile(r'^/hotel_pics/verybig/([1-9][0-9]*)/[a-zA-Z0-9][a-zA-Z0-9._-]*\.(?:jpg|jpeg|png)$', re.I)
MAX_BYTES = 12 * 1024 * 1024


def sha(data):
    return hashlib.sha256(data).hexdigest()


def check_photo_url(url):
    if not isinstance(url, str) or len(url) > 700:
        raise ValueError('invalid_photo_url')
    p = urlsplit(url)
    if (p.scheme, p.hostname, p.port, p.username, p.password, p.query, p.fragment) != ('https', 'static.tourvisor.ru', None, None, None, '', ''):
        raise ValueError('unexpected_photo_origin_or_params')
    m = MEDIA_ROOT.fullmatch(p.path)
    if not m or '..' in p.path:
        raise ValueError('unexpected_photo_path')
    return int(m.group(1))


def validate_rows(obj):
    rows = obj.get('rows')
    if obj.get('state') != 'EDITORIAL_AND_TOURVISOR_PHOTO_SOURCES_REVIEWABLE_NOT_SENT' or not isinstance(rows, list) or not (1 <= len(rows) <= 10):
        raise ValueError('unapproved_or_oversized_input')
    ids = set()
    for r in rows:
        if not isinstance(r, dict) or not isinstance(r.get('hotel_id'), int) or not isinstance(r.get('anytour_canonical_id'), int):
            raise ValueError('hotel_binding_missing')
        if r['hotel_id'] in ids or r['hotel_id'] < 1 or r['anytour_canonical_id'] < 1 or r.get('commercial_send_allowed') is not False:
            raise ValueError('duplicate_or_unapproved_row')
        ids.add(r['hotel_id'])
        if not isinstance(r.get('hotel'), str) or not r['hotel'].strip() or len(r['hotel']) > 105:
            raise ValueError('invalid_hotel_name')
        urls = r.get('photo_urls')
        if not isinstance(urls, list) or not (3 <= len(urls) <= 4) or len(set(urls)) != len(urls):
            raise ValueError('missing_independent_photos')
        countries = {check_photo_url(u) for u in urls}
        if len(countries) != 1:
            raise ValueError('photo_country_mismatch')
    return rows


def download_photo(url):
    import requests
    check_photo_url(url)
    resp = requests.get(url, allow_redirects=False, timeout=(8, 35), stream=True, headers={'Accept': 'image/jpeg,image/png'})
    try:
        if resp.status_code != 200 or not resp.headers.get('Content-Type', '').lower().startswith(('image/jpeg', 'image/png')):
            raise ValueError('photo_http_or_mime_not_accepted')
        parts, count = [], 0
        for part in resp.iter_content(chunk_size=65536):
            count += len(part)
            if count > MAX_BYTES:
                raise ValueError('photo_too_large')
            parts.append(part)
        data = b''.join(parts)
        with Image.open(io.BytesIO(data)) as im:
            im.verify()
        with Image.open(io.BytesIO(data)) as im:
            if min(im.size) < 350 or max(im.size) > 15000:
                raise ValueError('photo_dimensions_invalid')
            photo = ImageOps.exif_transpose(im).convert('RGB')
        return photo, {'source_url': url, 'bytes': len(data), 'sha256': sha(data), 'width': photo.width, 'height': photo.height, 'mime': resp.headers.get('Content-Type')}
    finally:
        resp.close()


def font(size):
    for path in ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf']:
        if Path(path).is_file():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def fit_title(draw, text, width=1000):
    title = text.upper()
    for size in range(44, 23, -1):
        f = font(size)
        if draw.textbbox((0, 0), title, font=f)[2] <= width:
            return title, f
    raise ValueError('hotel_title_too_long_for_cover')


def draw_cover(name, photos, logo):
    if len(photos) != 3:
        raise ValueError('need_exactly_three_photos')
    cover = Image.new('RGB', SIZE, 'white')
    cover.paste(ImageOps.fit(photos[0], (1080, 632), method=Image.Resampling.LANCZOS), (0, 0))
    cover.paste(ImageOps.fit(photos[1], (536, 337), method=Image.Resampling.LANCZOS), (0, 640))
    cover.paste(ImageOps.fit(photos[2], (536, 337), method=Image.Resampling.LANCZOS), (544, 640))
    draw = ImageDraw.Draw(cover, 'RGBA')
    draw.rectangle((0, 0, 1080, 111), fill=(0, 0, 0, 75))
    draw.text((38, 44), 'рубрика / отели', fill='white', font=font(34))
    original = ImageOps.contain(logo.convert('RGBA'), (259, 70), method=Image.Resampling.LANCZOS)
    cover.paste(original, (1036 - original.width, 30), mask=original.getchannel('A'))
    draw = ImageDraw.Draw(cover)
    draw.rectangle((0, 983, 1080, 1080), fill=BLUE)
    title, f = fit_title(draw, name)
    draw.text((38, 1000), title, fill='white', font=f)
    return cover


def encode_png(cover):
    # Palette PNG keeps three genuine photo crops under the established publisher size limit.
    out = io.BytesIO()
    cover.quantize(colors=256, method=Image.Quantize.MEDIANCUT).save(out, format='PNG', optimize=True)
    data = out.getvalue()
    if len(data) > 1_600_000 or len(data) < 10_000:
        raise ValueError('branded_cover_bytes_out_of_range')
    return data


def build(manifest, logo, out_dir, fetch=download_photo):
    rows = validate_rows(manifest)
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    receipts = []
    for row in rows:
        hid = row['hotel_id']
        result = {'hotel_id': hid, 'work_id': row['work_id'], 'anytour_canonical_id': row['anytour_canonical_id'], 'status': 'held', 'published': False, 'photo_sources': []}
        try:
            selected = []
            for url in row['photo_urls']:
                try:
                    photo, source = fetch(url)
                    selected.append(photo)
                    result['photo_sources'].append(source)
                    if len(selected) == 3:
                        break
                except Exception as exc:
                    result.setdefault('photo_errors', []).append({'url': url, 'reason': type(exc).__name__ + ':' + str(exc)[:100]})
            if len(selected) < 3:
                result['reason'] = 'fewer_than_three_valid_source_images'
            else:
                png = encode_png(draw_cover(row['hotel'], selected, logo))
                path = out_dir / (str(hid) + '.png')
                if path.exists():
                    raise ValueError('frozen_cover_already_exists_no_overwrite')
                with tempfile.NamedTemporaryFile(dir=out_dir, prefix='cover-', suffix='.part', delete=False) as tmp:
                    tmp.write(png)
                    tmp.flush()
                    os.fsync(tmp.fileno())
                    temp = Path(tmp.name)
                temp.replace(path)
                result.update({'status': 'prepared_needs_visual_acceptance', 'path': path.name, 'png_sha256': sha(png), 'png_bytes': len(png), 'width': 1080, 'height': 1080, 'source_image_count': 3, 'publication_allowed': False})
        except Exception as exc:
            result['reason'] = type(exc).__name__ + ':' + str(exc)[:180]
        receipts.append(result)
    return {'schema_version': 1, 'state': 'PHOTO_RENDERER_ONLY_NO_PUBLICATION', 'rows': receipts, 'prepared': sum(x['status'] == 'prepared_needs_visual_acceptance' for x in receipts), 'actual_posts': 0, 'sender_calls': 0, 'feed_writes': 0}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--input', type=Path, required=True)
    ap.add_argument('--logo', type=Path, required=True)
    ap.add_argument('--out', type=Path, required=True)
    ap.add_argument('--expected-input-git-blob', required=True)
    args = ap.parse_args()
    raw = args.input.read_bytes()
    git_blob = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
    if git_blob != args.expected_input_git_blob:
        raise SystemExit('STOP: source input blob changed')
    logo_raw = args.logo.read_bytes()
    if sha(logo_raw) != LOGO_SHA256:
        raise SystemExit('STOP: canonical AnyTour logo differs from accepted source')
    with Image.open(io.BytesIO(logo_raw)) as image:
        logo = image.convert('RGBA')
    result = build(json.loads(raw), logo, args.out)
    receipt_path = args.out / 'cover-preparation-receipt.json'
    if receipt_path.exists():
        raise SystemExit('STOP: receipt already exists; inspect earlier operation')
    receipt_path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'state': result['state'], 'prepared': result['prepared'], 'total': len(result['rows']), 'sender_calls': 0, 'feed_writes': 0}))


if __name__ == '__main__':
    main()
