import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0,str(Path(__file__).resolve().parent))
import artifacts
import public_read

class WorkflowHelpers(unittest.TestCase):
    def test_public_telegram_exact_caption_and_button(self):
        body = '<div data-post="any_toour/677"><div class="tgme_widget_message_text">Wrong</div></div>'
        body += '<div data-post="any_toour/678"><a class="tgme_widget_message_photo_wrap"></a><div class="tgme_widget_message_text">✈️ Waldorf<br><b>Десять ресторанов, баров и лаунжей</b> &amp; море</div><a class="tgme_widget_message_inline_button" href="https://t.me/anyru_bot?start=h_66198">✈️ Узнать стоимость тура</a></div>'
        caption,buttons = public_read.telegram_caption(body)
        self.assertEqual(caption,'✈️ Waldorf\nДесять ресторанов, баров и лаунжей & море')
        self.assertEqual(buttons,[{'text':'✈️ Узнать стоимость тура','url':'https://t.me/anyru_bot?start=h_66198'}])
        for mutated in [body.replace('any_toour/678','any_toour/679'),body+body,
                        body.replace('tgme_widget_message_photo_wrap','other')]:
            with self.assertRaises(ValueError):
                public_read.telegram_caption(mutated)

    def test_max_public_decoder_and_ambiguous_payload(self):
        text = '✈️ Waldorf\nДесять ресторанов, баров и лаунжей & море'
        body = 'message:{text:'+json.dumps(text.replace('&','&amp;'),ensure_ascii=False)+',other:1}'
        self.assertEqual(public_read.max_caption(body),text)
        for value in ['',body+body,'message:{text:null}']:
            with self.assertRaises(ValueError):
                public_read.max_caption(value)

    def test_original_artifact_members_and_hashes(self):
        members = {name+'.json':json.dumps({'member':name}).encode() for name in ['read-result','read-scope','journal-metadata','capture-proof']}
        def archive(files):
            output = io.BytesIO()
            with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as z:
                for name,value in files.items():
                    z.writestr(name,value)
            return output.getvalue()
        raw = archive(members)
        binding = {'sha256':hashlib.sha256(raw).hexdigest(),'members':{n:hashlib.sha256(v).hexdigest() for n,v in members.items()}}
        self.assertEqual(artifacts.validate_archive(raw,binding),members)
        with self.assertRaises(RuntimeError):
            artifacts.validate_archive(raw+b'x',binding)
        for changed in [members | {'unapproved.json':b'{}'},members | {'read-result.json':b'{"tampered":true}'}]:
            candidate = archive(changed)
            with self.assertRaises(RuntimeError):
                artifacts.validate_archive(candidate,binding | {'sha256':hashlib.sha256(candidate).hexdigest()})

if __name__ == '__main__':
    unittest.main()
