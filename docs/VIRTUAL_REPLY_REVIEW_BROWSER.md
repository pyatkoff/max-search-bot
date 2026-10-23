# Private owner review journal

`/manager2/virtual-replies.php` shows all currently readable, completed ordinary
virtual reviews in bounded pages of 20. It uses the existing `/manager/` session
cookie through `/manager/virtual-reply-reviews-api.php`; it does not issue a new
cookie or grant access. Sign in through Manager2 as an active administrator with
current AnyTour project access. The public shell contains no private content.

The journal displays the exact frozen prefix, saved author result and saved
review, including unread/incomplete evidence and a null outgoing draft. A pass
with empty issues is displayed without an invented explanation. Lesson candidates
remain candidates. It sends no customer message, calls no model and records no
lesson, claim, read receipt, assignment or quota change. Hidden control material
and reserved groups are not available here.

Chronological keyset paging is separate from the unchanged finding-first worker
inventory. Each page scans at most 20 candidates, plus one lookahead row. Every
returned case rechecks current source and sampling permissions through the
canonical store. Invalidated/revoked rows can leave a short or empty page; its
cursor still advances. Technical failures remain errors. New reviews appear after
refresh; there is no claim of a globally atomic historical export. Detail reads
repeat the same per-case fence and omit contracts, tokens and source identity.

The browser keeps no localStorage/cache of private replies and clears its visible
data on an observed authentication/access refusal. List/detail generations fence
late responses; all private text is rendered as text, never HTML. A URL fragment
can link one opaque case after authentication; it contains no transcript or token.
Read-only GETs require no new CSRF permission. Production acceptance distinguishes
installed source and anonymous denial from an authenticated owner's actual read.
