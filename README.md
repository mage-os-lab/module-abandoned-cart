# Abandoned Cart Reminder

`mage-os/module-abandoned-cart` (`MageOS_AbandonedCart`) — a module for Mage-OS / Magento Open Source.

Sends up to three reminder e-mails for abandoned carts, of customers and of guests, each with its own delay and template.

- A cron job runs every 15 minutes and sends the reminders that are due, at most one per cart per run.
- Guest carts are reminded once the e-mail entered at checkout is saved on the cart: after the shipping step on the Luma checkout, as soon as it is typed on Loki Checkout (see [Compatibility](#compatibility)). Their e-mail links to a page that puts the cart back in their session.
- Recipients: newsletter subscribers only (default), or all customers and guests.
- The e-mail lists the items still for sale with their image and price, and the subtotal. A cart whose products are all disabled or out of stock gets no e-mail.
- Every reminder has a *Stop cart reminders* link, and a one-click `List-Unsubscribe` header (RFC 8058, expected by Gmail and Yahoo), that stops all reminders to that address.
- A report under *Reports > Marketing > Abandoned Cart Reminders* counts the reminders sent, the cart links followed and the orders placed after a reminder. The same page sends any reminder to yourself as a test.
- Optional campaign parameters (`utm_source`, `utm_medium`, `utm_campaign`) on the cart link, for your analytics tool.
- A reminder whose sending fails is tried again one hour later, then six hours later, before being passed over.
- Configuration: *Stores > Configuration > Sales > Abandoned Cart*, per website. Disabled by default.

Limitations: three reminders at most; a reminder due while nothing in the cart is for sale is passed over, not sent later; carts unchanged for more than 30 days are not reminded; on the Luma checkout, a guest who stops at the e-mail field without going past the shipping step is not reminded (the e-mail is not saved yet); the campaign parameters have fixed values.

## Compatibility

Mage-OS 3.6 and 4.x, and Magento Open Source 2.4.9: all share the Magento 2.4.9 base (`magento/framework` ^103.0.9). PHP 8.3 to 8.5. CI runs the check-extension workflow on the currently supported Mage-OS versions (today Mage-OS 3.6.0 with PHP 8.4; Mage-OS 4.0 joins once the check-extension actions know it); developed on Mage-OS 4.0.0 with PHP 8.5.

The module adds nothing to any checkout: it reads the guest's e-mail where the checkout saved it, on the cart (`quote.customer_email`) or else on the cart address changed last (`quote_address.email`).

- **Luma** (theme and checkout): supported. The checkout saves the guest e-mail with the shipping address, when the guest leaves the shipping step. Covered by the integration tests run in CI; the 1.4 changes were not run in a browser.
- **Hyvä Theme**: supported. The only storefront page, the confirmation of the stop link, is a plain form styled for both Hyvä and Luma; the module registers itself for Hyvä's Tailwind build.
- **Hyvä Checkout**: not verified, neither run nor read (no licensed install). Guests are reminded if it saves their e-mail in one of the two places above: check it on yours with the query under [Troubleshooting](#troubleshooting).
- **Loki Checkout**: read from its source (2.8.2), not run. Its e-mail field saves the address on the cart (`quote.customer_email`) as soon as it is filled in, so guests are reminded without going past any step. To check: as a guest, type an e-mail in the checkout, leave, and look for the cart with the query under [Troubleshooting](#troubleshooting).
- **Elasticsuite**: nothing to integrate; the module touches neither search nor product lists.

## Installation

```bash
composer config repositories.mageos-abandoned-cart vcs https://github.com/mage-os-lab/module-abandoned-cart
composer require mage-os/module-abandoned-cart
bin/magento module:enable MageOS_AbandonedCart
bin/magento setup:upgrade
```

## Configuration

### Stores > Configuration > Sales > Abandoned Cart > General

| Setting | Default | Scope | What it does |
|---------|---------|-------|--------------|
| Enabled | No | Website | Turns the reminders on for the website. |
| Recipients | Newsletter subscribers only | Website | *Newsletter subscribers only*: an address subscribed to the newsletter in the cart's website, the subscription being the consent for this marketing e-mail. *All customers and guests*: every cart with an e-mail; choose it only if your privacy policy covers cart reminders. With *Newsletter subscribers only*, set *Customers > Newsletter > Subscription Options > Need to Confirm* to Yes, so nobody can subscribe someone else's address to get it reminders. |
| Minimum Cart Subtotal | 0 | Website | Carts whose subtotal, in base currency before tax and discounts, is below it get no reminder. 0: no minimum. |
| Add Campaign Parameters to the Cart Link | No | Website | Adds `utm_source=abandoned_cart`, `utm_medium=email` and `utm_campaign=reminder_1` (`_2`, `_3`) to the cart link of the reminders, and carries them to the cart page the link opens, so an analytics tool (GA4, Matomo…) credits the visit to the reminder. The values are fixed. |

### Reminder 1, Reminder 2, Reminder 3

| Setting | Default | Scope | What it does |
|---------|---------|-------|--------------|
| Delay (hours) | 24, 0, 0 | Website | Hours without any change to the cart before this reminder. 0 turns the reminder off. Each delay counts from the last change to the cart and must be more than the previous reminder's, otherwise the reminder is skipped. |
| E-mail Template | Abandoned Cart Reminder 1, 2, 3 | Store view | Template of the reminder; copy it under *Marketing > Email Templates* to change the text. |

Reminder n+1 is also never sent sooner than the gap between both delays after reminder n: with 1, 24 and 72 hours, a cart found 3 days old still gets its reminders 23 and 48 hours apart. A change to the cart pushes the next reminder back by its full delay; placing the order stops the reminders. An e-mail address gets at most 3 reminders a week, all carts together, so carts filled in with someone else's address cannot flood it; a reminder over that limit is skipped and the next one follows as usual.

A reminder whose sending fails (mail server down, broken template: see `var/log/system.log`, "Abandoned cart reminder failed") stays due: it is tried again one hour later, then six hours later, and passed over after this third failure, the next reminder following as usual. A change to the cart in the meantime restarts the delay as for any reminder.

Access to this section needs the *Abandoned Cart* ACL resource (*System > Permissions > User Roles > Stores > Settings > Configuration*).

### Reports > Marketing > Abandoned Cart Reminders

One row per reminder, over the last 7, 30, 90 or 365 days: reminders sent, cart links followed, orders placed from the cart after the reminder, and their revenue (grand total in base currency). An order is credited to the last reminder its cart received, whether or not its link was followed. Access needs the *Abandoned Cart Reminders* ACL resource (*Reports > Marketing*). The figures start with 1.2.0: reminders sent before are not counted.

*Send a Test E-mail*, under the report, sends the reminder you pick, as the store view you pick renders it (template, language, sender), to the e-mail address of your admin account. It works while the module is disabled, so a template can be checked before the reminders are turned on. The cart shown is a sample: up to three enabled simple products of the store view at their catalog price. Its links open the cart page: there is no cart to restore and the stop link stops nothing.

![Abandoned cart configuration](docs/images/config.png)

## Getting started

1. Open *Reports > Marketing > Abandoned Cart Reminders* and send yourself each reminder with *Send a Test E-mail*.
2. Set *Enabled* to Yes. For a classic sequence set the delays of *Reminder 1, 2, 3* to 1, 24 and 72.
3. As a customer subscribed to the newsletter, log in and add a product to the cart, then leave. With *Recipients* set to *All customers and guests*, you can also try as a guest: add a product, go to checkout, enter an e-mail and the shipping address, click *Next*, then leave.
4. Once the cart is older than the first delay, the next cron run (every 15 minutes) sends reminder 1, listing the items with their image and price, with a link back to the cart and a *Stop cart reminders* link.
5. Follow the link, then open *Reports > Marketing > Abandoned Cart Reminders*: the reminder is counted as sent and followed.

The links in the e-mail carry a token per cart, signed with the deployment key (`app/etc/env.php`). The guest's link copies the cart's items into the cart of the browser that opens it, unless a customer is signed in there, and closes the reminded cart: a forwarded link never makes two browsers share one cart. Customers' links open the cart page, where they sign in. The stop link opens a confirmation page; the address is opted out once the visitor confirms, or when the mailbox provider sends the one-click unsubscribe. Guests are greeted without a name, since the name typed at checkout is not checked.

## Upgrading from 1.2

`setup:upgrade` replaces the opted-out addresses by their SHA-256 hash: the module no longer keeps them in clear. The links of reminders sent before the upgrade stop working, since links now carry a signed token: a visitor who follows one gets *This cart is no longer available* or *This link is not valid*, and the next reminder brings working links.

## Upgrading from 1.1

Nothing to do for the settings: they keep their values, now read per website. A custom template made from a 1.1 template keeps working but shows no image, price or subtotal: rebuild it from the new *Abandoned Cart Reminder 1, 2, 3*, or add `item.image_url`, `item.price` and `subtotal` to it. Customers' cart links now go through the module's restore link, which opens the cart page as before.

## Upgrading from 1.0

`setup:upgrade` moves the 1.0 *Delay (hours)* and *E-mail Template* settings to *Reminder 1* and counts the carts already reminded as having received reminder 1. A custom template made from the 1.0 *Abandoned Cart Reminder* keeps working: its unsubscribe link now stops cart reminders. Its cart link (`checkout/cart`) does not restore guest carts, and its text still mentions the newsletter: rebuild it from *Abandoned Cart Reminder 1* to use `cart_url`.

## Troubleshooting

- **Nothing happens**: Magento cron must run (`bin/magento cron:run` every minute from the system crontab). Check `cron_schedule` for `mageos_abandoned_cart_reminders`.
- **A customer gets nothing**: they are not subscribed to the newsletter (*Customers > Newsletter Subscribers*) while *Recipients* is *Newsletter subscribers only*, the module is off for the cart's website, the subtotal is below *Minimum Cart Subtotal*, no product of the cart is for sale any more, the cart is empty, converted or older than 30 days, all reminders were sent, the address used the stop link (table `mageos_abandoned_cart_optout`, keyed by `SHA2(LOWER('address'), 256)`; delete the row to resume), or the address already got 3 reminders this week.
- **A guest gets nothing**: no e-mail is saved on the cart yet; on the Luma checkout the guest did not get past the shipping step. To see what a checkout saved, and when: `SELECT q.entity_id, q.customer_email, a.address_type, a.email FROM quote q LEFT JOIN quote_address a ON a.quote_id = q.entity_id WHERE q.is_active = 1 ORDER BY q.updated_at DESC LIMIT 10;`
- **A reminder is late**: its sending failed and it waits for its next try (`quote.abandoned_reminder_failures`, `quote.abandoned_reminder_retry_at`, in UTC); the reason is in `var/log/system.log`.
- **"This cart is no longer available"**: the order was placed, the items were already restored by an earlier click (the reminded cart is then closed), the link predates 1.3.0, or it was altered.
- **E-mail not delivered**: check *Stores > Configuration > Advanced > System > Mail Sending Settings* and the mail log.

## More

- [CHANGELOG](CHANGELOG.md)
- [Security policy](SECURITY.md)
- [License](LICENSE.txt): Open Software License 3.0. This module contains no code from Adobe Commerce.
