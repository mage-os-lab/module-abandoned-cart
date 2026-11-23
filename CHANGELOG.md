# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.2.1]

### Fixed

- PHPStan 2 error in the reminder query: the opt-out and newsletter subqueries are built as plain SQL strings.

## [1.2.0]

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

## [1.0.0]

### Added

- Cron that sends one reminder e-mail per abandoned cart of a logged-in customer subscribed to the newsletter in the cart's website, with an unsubscribe link.
- Configuration under *Stores > Configuration > Sales > Abandoned Cart*, protected by its own ACL resource `MageOS_AbandonedCart::config`.
- CI: check-extension workflow on the supported Mage-OS versions, `composer audit` and Dependabot.
- PHPStan (level 8) and Magento coding standard configuration.
- Integration tests for the reminder selection.
- `SECURITY.md`, translations, and a README with configuration, getting started, screenshots and troubleshooting.
