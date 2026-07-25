# HUB Frontend

This directory is the versioned source for the HUB browser application.

`resources/frontend/crm` is the active Laravel/Vite shell. It owns HUB boot code,
shared API helpers, mobile/PWA bridges, fallback navigation, shared styles, and
module registration.

`resources/views/crm.blade.php` must stay intentionally thin: server meta tags,
the JSON shell configuration (`#crm-shell-config`), `#root`, and the Vite entry.
Theme boot, logout interception, mobile settings, loader activation, and
compatibility bridges belong in the versioned TypeScript sources.

`resources/frontend/static/assets` contains versioned public assets such as
logos and PWA icons. They are published to `public/assets` by
`php artisan crm:publish-static-assets --force --clean`.

New HUB frontend work should start in `resources/frontend/crm` or in module
`resources/assets` files, not by editing generated files in `public`.

## License

This source is part of Martin Sols HUB and is governed by the repository license
in `LICENSE.md`. It is source-available for personal evaluation and
contributions only; professional use, commercial use, resale, hosting and
redistribution require prior written permission from Jean-Philippe DEGERT / JP2
Creation.
