# Reporting a security issue

**Please do not open a public issue for a security problem.** An issue here is visible to everyone,
including to whoever would use the flaw before it is fixed.

Write to **support@onsitetools.dev** instead, with `SECURITY` at the start of the subject line. It
is a small team and that address reaches it directly.

## What helps

- What the flaw lets someone do, and who has to be able to do it — an unauthenticated visitor, a
  subscriber, an editor?
- The plugin version, the WordPress version and the PHP version.
- Steps to reproduce, or the request that triggers it.
- Whether you have told anyone else, and whether you intend to publish.

You do not need a proof-of-concept exploit. A clear description of the mechanism is enough, and is
often safer to send.

## What happens next

- **We reply within two working days**, in English or Portuguese, saying whether we have reproduced
  it. If two days pass with no reply, assume the mail went astray and write again — silence here is
  a failure on our side, not an answer.
- We agree a timeline with you. For a flaw that is being exploited, that means the fastest release
  we can cut; otherwise we aim to ship a fix before any public description.
- The fix ships to WordPress.org and to licence holders together, and the changelog says what was
  wrong. We will not describe the flaw in a way that hands out a recipe before people have had a
  chance to update.
- **You get the credit, under whatever name you choose** — including none. Tell us which.

We have no bug bounty. We are a very small publisher and would rather say so plainly than imply a
reward that does not exist.

## Supported versions

The latest release on
[WordPress.org](https://wordpress.org/plugins/onsite-link-verdict/) is the supported one. There are
no long-term support branches: fixes ship forward.

## Scope

This is about **Onsite Link Verdict itself**. Two things that come up and are not ours to fix:

- The plugin bundles the **Freemius SDK** (`vendor/freemius/`) for licensing. A flaw in that code
  belongs to Freemius — report it to them at https://freemius.com/ — though we would still like to
  hear about it, so we can ship an updated SDK.
- A link the plugin reports on is a page on someone else's server. What that server does is not
  something this plugin controls.
