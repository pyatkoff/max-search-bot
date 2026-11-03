import csv,hashlib,json,pathlib,re,urllib.parse
base=pathlib.Path('.github/traffic-publish')
names=['hotel-posts-telegram.csv','hotel-posts-max.csv','hotel-posts-all.csv']
header=['Property ID','Property name','Final URL','Destination name','Image URL','Category']
accepted_owner_images=set(["https://image-tc.galaxy.tf/wijpeg-bunhs9glqpbhgr07p92hv73nb/028-ls-landscape-104108318.jpg","https://image-tc.galaxy.tf/wijpeg-43nq1ttkef5hcqekzjvfa0g4j/over-view.jpg","https://www.ahstatic.com/photos/b6f4_ho_00_p_1024x768.jpg","https://d1wo7kaelp5eck.cloudfront.net/sunrise-resorts.com-1611976553/cms/cache/v2/67f3ffae5a3de.jpg/1200x630/fit/80/238e6a950d96e74d0cac4d2e2d44f808.jpg"])
previous_files={"hotel-posts-telegram.csv":{"bytes":30425,"sha256":"10409db94653ed1df898426077841b94905d23c1db41d80ed50761f553eae09c","rows":121},"hotel-posts-max.csv":{"bytes":32567,"sha256":"f6d78c160102e274ac37f66532d3dd5216d474548e7488e7a333392ecaa60ccb","rows":121},"hotel-posts-all.csv":{"bytes":62919,"sha256":"7841524f1c2e09eb184249d04aaaee1543969c486024244ca58968cb6fc55509","rows":242}}
tables=[]
for name in names:
 raw=(base/name).read_bytes()
 previous=previous_files[name]
 assert hashlib.sha256(raw[:previous['bytes']]).hexdigest()==previous['sha256'],'previous_public_rows_changed'
 rows=list(csv.DictReader(raw.decode('utf-8').splitlines()))
 assert list(rows[0])==header and len({r['Property ID'] for r in rows})==len(rows)
 for row in rows:
  u=urllib.parse.urlsplit(row['Final URL'])
  assert u.scheme=='https' and not u.query and not u.fragment and not u.username and not u.password and u.port is None
  if row['Property ID'].startswith('tg_'):assert re.fullmatch(r'https://t\.me/any_toour/[1-9][0-9]*',row['Final URL'])
  else:
   assert row['Property ID'].startswith('max_')
   assert re.fullmatch(r'https://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}',row['Final URL'])
  if row['Image URL']:
   assert row['Image URL'] in accepted_owner_images or re.fullmatch(r'https://raw\.githubusercontent\.com/pyatkoff/max-search-bot/[a-f0-9]{40}/\.github/traffic-publish/[A-Za-z0-9_./-]+\.png',row['Image URL'])
  assert row['Property name'] and row['Destination name'] and row['Category']=='отель'
 tables.append(rows)
assert len(tables[0])==len(tables[1])==124 and len(tables[2])==248
assert all(r['Property ID'].startswith('tg_') for r in tables[0])
assert all(r['Property ID'].startswith('max_') for r in tables[1])
assert sorted(tuple(r[k] for k in header) for r in tables[2])==sorted(tuple(r[k] for k in header) for r in tables[0]+tables[1])
assert [sum(not r['Image URL'] for r in rows) for rows in tables]==[2,2,4]
print('THREE_PUBLIC_CHANNEL_FEEDS_VALID rows=124,124,248 previous_bytes=unchanged union=exact unique_ids=1 image_gaps=2,2,4 private_fields=0 provider_calls=0')
