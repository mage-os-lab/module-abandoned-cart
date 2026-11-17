# Abandoned Cart Reminder

`mage-os/module-abandoned-cart` (`MageOS_AbandonedCart`) — a module for Mage-OS / Magento Open Source.

Sends one reminder e-mail for carts of logged-in customers that have not changed for a configurable number of hours.

- A cron job runs every 15 minutes and e-mails each eligible cart once.
- Configuration: *Stores > Configuration > Sales > Abandoned Cart* (enabled, delay in hours, e-mail template). Disabled by default.
- The e-mail lists the cart items and links back to the cart.

Limitations: guest carts and customers not subscribed to the newsletter are not reminded, and there is a single reminder per cart.

## Compatibility

Mage-OS 3.6 and 4.x, and Magento Open Source 2.4.9: all share the Magento 2.4.9 base (`magento/framework` ^103.0.9). PHP 8.3 to 8.5. CI runs the check-extension workflow on the currently supported Mage-OS versions (today Mage-OS 3.6.0 with PHP 8.4; Mage-OS 4.0 joins once the check-extension actions know it); developed on Mage-OS 4.0.0 with PHP 8.5. No storefront templates, so the theme does not matter.

## Installation

```bash
composer config repositories.mageos-abandoned-cart vcs https://github.com/mage-os-lab/module-abandoned-cart
composer require mage-os/module-abandoned-cart
bin/magento module:enable MageOS_AbandonedCart
bin/magento setup:upgrade
```

## Configuration

### Stores > Configuration > Sales > Abandoned Cart > Reminder E-mail

| Setting | Default | Scope | What it does |
|---------|---------|-------|--------------|
| Enabled | No | Global | Turns the reminder cron on. |
| Delay (hours) | 24 | Global | A cart is reminded once it has not changed for this many hours. |
| E-mail Template | Abandoned Cart Reminder | Store view | Template of the reminder; copy it under *Marketing > Email Templates* to change the text. |

Access to this section needs the *Abandoned Cart* ACL resource (*System > Permissions > User Roles > Stores > Settings > Configuration*).

![Abandoned cart configuration](docs/images/config.png)

## Getting started

1. Set *Enabled* to Yes and keep the 24-hour delay.
2. As a customer subscribed to the newsletter, log in and add a product to the cart, then leave.
3. Once the cart is older than the delay, the next cron run (every 15 minutes) sends one e-mail listing the items, with a link to the cart and an unsubscribe link.

Only active carts of logged-in customers who are subscribed to the newsletter in the cart's website get a reminder: the subscription is the consent for this marketing e-mail. Each cart is reminded once.

## Troubleshooting

- **Nothing happens**: Magento cron must run (`bin/magento cron:run` every minute from the system crontab). Check `cron_schedule` for `mageos_abandoned_cart_reminders`.
- **A customer gets nothing**: they are not subscribed to the newsletter (*Customers > Newsletter Subscribers*), the cart is empty or converted, or the cart was already reminded.
- **E-mail not delivered**: check *Stores > Configuration > Advanced > System > Mail Sending Settings* and the mail log.

## More

- [CHANGELOG](CHANGELOG.md)
- [Security policy](SECURITY.md)
- [License](LICENSE.txt): Open Software License 3.0. This module contains no code from Adobe Commerce.
