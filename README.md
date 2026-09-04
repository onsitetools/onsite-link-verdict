# Onsite Link Verdict — a broken link checker for WordPress

[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/onsite-link-verdict?label=wordpress.org)](https://wordpress.org/plugins/onsite-link-verdict/)
[![Active installs](https://img.shields.io/wordpress/plugin/installs/onsite-link-verdict?label=active%20installs)](https://wordpress.org/plugins/onsite-link-verdict/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/onsite-link-verdict)](https://wordpress.org/plugins/onsite-link-verdict/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

**It only calls a link broken when it has proof.** Everything else goes somewhere else, and says
why.

- **Install it:** [wordpress.org/plugins/onsite-link-verdict](https://wordpress.org/plugins/onsite-link-verdict/) — or, inside your site, **Plugins → Add New → search "Onsite Link Verdict"**
- **Product page:** [onsitetools.dev/onsite-link-verdict](https://onsitetools.dev/onsite-link-verdict/)
- **Support:** [support@onsitetools.dev](mailto:support@onsitetools.dev) · [support page](https://onsitetools.dev/support/) · or open an issue here

---

## The problem this exists for

A link checker makes one hard decision: *is this link dead, or did that server just refuse me?*

Plenty of servers refuse automated requests without the page being gone. Amazon answers `503` to
bots. X answers `403`. LinkedIn answers `999`. A site behind a bot firewall returns a challenge
page. **None of those links are broken** — they open perfectly well in a browser.

Report them as broken and you hand somebody a list of two hundred problems, of which a hundred and
ninety are not problems. The list stops being read. A checker whose output nobody trusts has failed
at the only thing it does.

## What this does instead

Onsite Link Verdict calls a link broken only when it has proof:

- the destination server says the page is gone — `404` or `410`, **and even then it asks a second
  time with a different method** before accusing the link. Some servers answer `404` to `HEAD` and
  `200` to `GET`; a wrong accusation is the expensive kind of mistake here;
- or nothing answers at all, three checks in a row — an expired domain, a host that vanished.

Everything else lands in a separate **Unverifiable** tab with a plain-language note about what
happened. If a server answered — with `403`, `503`, a rate limit, a challenge page — it *answered*,
and that is not proof the page died.

The result is a short list. Short means believable.

## What it checks

| | |
|---|---|
| **Internal links** | Including ones pointing at a draft, private or pending post. Those give visitors a `404` — but instead of saying the page no longer exists, it tells you the page exists and is not published, and which state it is in |
| **External links** | Links out to other sites |
| **Images** | Including the ones a page builder pulls in |
| **Links in comments** | Approved comments |
| **Redirect chains** | Followed, with loops and over-long chains reported as such |

It reads pages **as a visitor sees them**, so links assembled at render time by Elementor, Divi,
SiteOrigin or Beaver are found — on those pages the stored post content is often empty, and a
checker reading only stored content would find nothing to report.

## Where it runs

On your own server, on WordPress cron, in small batches with a hard ceiling on both link count and
wall-clock time per run. `HEAD` first, falling back to `GET` only when a verdict depends on it, and
at most 2 KB of any response body is ever read. The same URL repeated across fifty posts is fetched
**once**: measured, 90 links across 15 posts pointing at 6 addresses cost **8 requests**.

There is no cloud service behind it, no API key, no account and no credits or monthly quota. That
also means it reaches places an outside crawler cannot: a staging site, an intranet, a site behind
HTTP authentication or a firewall.

**One structural limit worth knowing:** a single post or comment is checked to its first 1,000
links, and the panel tells you when that happens.

## What leaves your site

Two destinations, named rather than glossed over:

1. **The servers hosting the links being checked.** Each receives the address being checked and, in
   the `User-Agent` header, your site's address and the plugin version. This is inherent to the job.
2. **Freemius**, which handles licensing and checkout. On activation you are asked whether to share
   information about the site — and **you can decline**, entirely locally, with the plugin working
   the same either way.

Your posts, pages and comments are never uploaded anywhere. The full account, including the domains
and the four moments at which Freemius is contacted, is in the **External services** section of the
[plugin listing](https://wordpress.org/plugins/onsite-link-verdict/) and in the
[privacy note](https://onsitetools.dev/privacy/).

## Free and paid

Everything above is the **free version**, and it stays free — it is not a trial. It finds broken
links and tells you exactly which post, page or comment each one is in. It does not edit your
content: you fix each link in the editor, where you can see the sentence it sits in.

**Onsite Link Verdict Pro** is an annual licence, from 29 EUR/year plus VAT for one site (59 EUR for
5, 99 EUR for 25). It does the mechanical part for people with more than a handful of links to fix:

- **bulk-replace a URL everywhere at once** — you see every place it will change, with the
  surrounding text, *before* anything happens; there is an undo, and the undo refuses to run if you
  edited the content afterwards;
- **it will not touch what it cannot verify** — if a link comes from a shortcode, a page builder or
  a theme filter, the address is not in the content being saved, and the plugin says so rather than
  reporting a success that changed nothing;
- **mute the domains that never answer robots**;
- **CSV export** that opens correctly in Excel.

Paid features stop working when a licence expires; the plugin then does what the free version does.
There is no refund policy — the free version is the trial, and it is complete.

## This repository

This is the **published free version**, mirrored here from the WordPress.org directory so the source
is easy to read, link to and open issues against. It is the same code that ships in the plugin, and
it carries no premium files.

- **Issues and questions are welcome here.** For anything involving your licence, your site's data
  or a purchase, write to [support@onsitetools.dev](mailto:support@onsitetools.dev) instead — this
  repository is public.
- Development happens in a private repository; releases land here and on WordPress.org together.
- Pull requests are read, but a change that ships has to go through the WordPress.org release, so
  expect a conversation before code.

## Requirements

WordPress 6.1 or later · PHP 7.4 or later.

## Licence

GPLv2 or later — see [LICENSE](LICENSE). Published by **Onsite Tools**
([onsitetools.dev](https://onsitetools.dev/)).
