# Web Consultant

Canonical client-side web consultant for AnyTour.

## Owns

- `index.php` — standalone preview/test page;
- `widget.js` — embeddable website chat UI;
- `api.php` — website chat transport endpoint;
- `rollout.php` — percentage rollout loader.

## Shared core

Dialogue, AI, manager handoff, persistence and provider logic remain in the repository-level `services/`, `ai/`, `integrations/`, `handlers/` and `actions/` modules. Do not duplicate those rules inside this folder.

## Compatibility

The legacy `/website/` paths remain compatibility shims. New integrations should use `/max-search/web-consultant/` URLs.
