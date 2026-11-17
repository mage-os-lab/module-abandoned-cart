# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.0.0]

### Added

- Cron that sends one reminder e-mail per abandoned cart of a logged-in customer subscribed to the newsletter in the cart's website, with an unsubscribe link.
- Configuration under *Stores > Configuration > Sales > Abandoned Cart*, protected by its own ACL resource `MageOS_AbandonedCart::config`.
- CI: check-extension workflow on the supported Mage-OS versions, `composer audit` and Dependabot.
- PHPStan (level 8) and Magento coding standard configuration.
- Integration tests for the reminder selection.
- `SECURITY.md`, translations, and a README with configuration, getting started, screenshots and troubleshooting.
