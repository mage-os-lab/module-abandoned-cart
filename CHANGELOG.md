# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.4.0] - 2026-11-24

A reminder whose e-mail fails is tried again instead of being lost, and reminders can be tested from the admin and tracked in analytics tools.

Upgrade: the update adds two columns to the `quote` table; on a large shop, plan it like any change to that table.

### Added

- *Send a Test E-mail* under *Reports > Marketing > Abandoned Cart Reminders*: sends the reminder and store view you pick to your own admin address, with sample products as the cart, also while the module is disabled.
- *Add Campaign Parameters to the Cart Link* setting (*Stores > Configuration > Sales > Abandoned Cart > General*, off by default): adds `utm_source`, `utm_medium` and `utm_campaign` to the cart link and carries them to the cart page, so analytics tools credit the visit to the reminder.

### Changed

- A reminder whose sending fails is no longer lost: it is tried again one hour later, then six hours later, and passed over only after this third failure.
- When more carts are due than one run sends, the carts left longest are served first; the order was arbitrary.

### Fixed

- A guest cart whose addresses hold different e-mails is always reminded at the one of the address changed last; either could be picked before.

### Compatibility

- Loki Checkout: guests are reminded as soon as they have typed their e-mail (read from Loki Checkout 2.8.2's source, not run). Hyvä Checkout remains unverified. See *Compatibility* in the README.

## [1.3.0] - 2026-11-23

### Security

- The guest restore link copies the cart's items into the visitor's own cart and closes the reminded cart, instead of putting that cart in the visitor's session: a forwarded link let its sender read and change the checkout data of whoever followed it.
- An e-mail address gets at most 3 reminders a week, all carts together, and guests are greeted without the name typed at checkout: carts filled in with someone else's address could flood it with text of the attacker's choosing.
- The stop link opens a confirmation page and opts out only on its POST or on the one-click POST, so mail scanners that follow links no longer unsubscribe people.
- The stop link opts out the address the reminder was sent to, logged with the reminder, and not the cart's current address.
- Reminder links are forced to HTTPS and carry an HMAC of the cart's secret under the deployment key: the secret itself shows in the storefront's checkout data. Links of reminders sent before 1.3.0 stop working.
- Opted-out addresses are kept as a SHA-256 hash; `setup:upgrade` hashes the existing ones.
- A malformed token (`token[]=`) no longer causes an error page.

### Fixed

- A reminder whose e-mail fails is recorded as skipped instead of being retried at every run, where enough of them held the whole batch.

## [1.2.1] - 2026-11-23

### Fixed

- PHPStan 2 error in the reminder query: the opt-out and newsletter subqueries are built as plain SQL strings.

## [1.2.0] - 2026-11-23

### Added

- Product image, row price and subtotal in the reminder e-mails (`item.image_url`, `item.price`, `subtotal`).
- One-click `List-Unsubscribe` and `List-Unsubscribe-Post` headers (RFC 8058); the stop link also takes the mailbox provider's POST.
- *Minimum Cart Subtotal* setting.
- *Reports > Marketing > Abandoned Cart Reminders*: reminders sent, cart links followed, orders and revenue per reminder, logged in `mageos_abandoned_cart_reminder`, with its own ACL resource.

### Changed

- *Enabled*, *Recipients* and the delays can be set per website.
- Disabled and out-of-stock products are left out of the e-mail; a cart with nothing left for sale gets no e-mail and the reminder is passed over.
- Customers' cart links go through the module's restore link, to count the click; they still open the cart page.

## [1.1.0]

### Added

- Up to three reminders per cart (*Reminder 1, 2, 3*), each with its own delay and e-mail template; reminder 2 and 3 are off by default.
- Guest carts: reminded through the e-mail saved on the cart address at checkout, with a link that restores the cart in the guest's session.
- *Recipients* setting: newsletter subscribers only (default, as in 1.0) or all customers and guests.
- *Stop cart reminders* link in every reminder, recorded per e-mail address in `mageos_abandoned_cart_optout`.

### Changed

- The reminder e-mail links to the module's stop link instead of the newsletter unsubscribe link, and its text no longer mentions the newsletter.
- Settings moved from *Reminder E-mail* to *General* and *Reminder 1*; `setup:upgrade` migrates them.
- Carts last changed more than 30 days ago are no longer reminded.

## [1.0.0] - 2026-11-17

### Added

- Cron that sends one reminder e-mail per abandoned cart of a logged-in customer subscribed to the newsletter in the cart's website, with an unsubscribe link.
- Configuration under *Stores > Configuration > Sales > Abandoned Cart*, protected by its own ACL resource `MageOS_AbandonedCart::config`.
- CI: check-extension workflow on the supported Mage-OS versions, `composer audit` and Dependabot.
- PHPStan (level 8) and Magento coding standard configuration.
- Integration tests for the reminder selection.
- `SECURITY.md`, translations, and a README with configuration, getting started, screenshots and troubleshooting.
