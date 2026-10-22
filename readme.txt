=== Onsite Link Verdict — Broken Link Checker ===
Contributors: onsitetools
Tags: broken links, link checker, dead links, 404, broken link checker
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Broken link checker that calls a link broken only with proof, so links that open fine in your browser stay off the list. No account, no cloud.

== Description ==

Onsite Link Verdict checks the links and images in your whole WordPress site (posts, pages, custom post types and comments) and tells you which ones are dead. Install it, and you can check links in WordPress from your own dashboard, on the same server that runs the site.

A server that refuses a robot is not taken as proof that the page is gone. Links to LinkedIn (`999`), Amazon (`429`, `503`), X (`403`) or a site behind a bot firewall are kept out of your list of broken links: refusals go to their own tab, "Unverifiable", and server errors wait under "Warnings" to be checked again. And the checking runs in small background batches, so it does not slow down your site.

= It knows the difference between "broken" and "I could not check it" =

This distinction is the whole reason this plugin exists.

Plenty of servers refuse automated requests. Amazon answers `503` to bots. X answers `403`. LinkedIn answers `999`. A site behind a firewall throws a challenge page. **None of those links are broken** — they open perfectly well in your browser. Report them as broken and you hand the user a list of two hundred problems, of which one hundred and ninety are not problems, and they stop trusting the list.

So Onsite Link Verdict only ever calls a link broken when it has proof:

* The destination server says the page is gone — `404` or `410`. And even then we ask a second time, with a different method, before accusing the link: there are servers that answer `404` to one kind of request and `200` to another, and a wrong accusation here is the most expensive kind.
* Or there is no server answering at all, three checks in a row — a domain that expired, a host that vanished.

**Everything else goes to a separate "Unverifiable" tab**, with a plain-language note about what happened. If a server answered us — with `403`, `503`, a rate limit, a challenge page, anything that is not `404` or `410` — it answered, and that is not proof the page died. Your list of real problems stays short, and short means believable.

= It speaks your language =

The plugin ships translated into **Portuguese (Portugal), Portuguese (Brazil), Spanish, French, German, Italian and Dutch**, as well as English. That includes the explanations, not just the buttons — which is the point: `403` reads the same in every language, and "the server is refusing automated checks, it is not saying the page is gone" does not.

If your language is missing, the translation template in `/languages` is generated from the current version and covers every text in the plugin.

= It runs on your own server, and stays out of the way =

There is no account to create, no cloud service and no credit card. Your posts, pages and comments are not uploaded anywhere — see "External services" below for everything that does leave your site, and why.

Checking links is real work for the same server that answers your readers. Onsite Link Verdict is built around that:

* **No checking work happens inside the page your visitor is waiting for.** It runs on your site's cron, in a request of its own.
* **No new check starts after 45 seconds of wall clock in a run.** How many links fit in a run depends on the speed you choose (100, 400 or 1,000), and the time limit applies at every speed. Whatever is left waits in the queue for the next run.
* `HEAD` requests first, falling back to `GET` only when a verdict depends on it. At most 2 KB of any response body is ever read.
* The same URL repeated across fifty posts is fetched **once** per run, not fifty times. Measured: 90 links across 15 posts, pointing at 6 different addresses, cost **8 requests**.
* A site that already told us it does not talk to robots is not asked again the next day.

= What it checks =

* **Internal links** — links to your own posts and pages. If one points at something that is still a draft, private or pending, you are told exactly that, instead of being told the page no longer exists
* **External links** — links out to other sites
* **Images**, including the ones a page builder pulls in
* **Links inside comments**
* **Redirects** — it follows the chain, and says so when the chain loops or never arrives anywhere

= A local broken link checker: no cloud service, no credits =

The link scanner runs locally, on your own site's cron, on the same server that answers your readers. There is no cloud service behind the plugin, no API key and no account to create: the results live in a table in your own database, and your content is never uploaded anywhere.

Some things do go out of your site — the servers hosting the links being checked, and Freemius, which runs our licensing and checkout. **They are set out one by one, with the domains and with what you can decline, under "External services" below.**

What that buys you:

* **Nothing is metered.** How many links you check does not change the price, because there is no price. Fifty thousand links cost the same as fifty. One structural limit worth knowing: a single post or comment with more than 1,000 links is checked to the first 1,000, and the panel tells you when that happens.
* **No back end holds your data.** There is nowhere for your content to be uploaded to, because there is no service on the other end holding it. What that means for your own processing records is your call — this is a description of the plugin, not legal advice.
* **It works from inside the site.** A staging site, an intranet, a site behind HTTP authentication or a firewall is not reachable from the public internet — but the plugin is not arriving from the internet, it is already there. Those pages get checked like any other.

The privacy note for the plugin is at https://onsitetools.dev/privacy/ and says the same thing in more detail.

Once the first sweep is done it stays on as a broken link monitor. Every link is checked again after a week, and one that is failing after a day, so a page that dies next month turns up next month — you do not have to remember to run an audit.

= What you get =

* Checks links **and** images, in posts, pages, custom post types and comments
* **Sees inside page builders.** On a page built with Elementor, Divi, SiteOrigin or Beaver the links are not in the stored post content — they are assembled when the page is rendered. Onsite Link Verdict renders the page first and reads what a visitor sees, so those links are found
* Four clear tabs: Broken, Warnings, Unverifiable, Good
* Plain-language explanation of the results that matter — not just a bare status code
* **It knows your own site.** A link to one of your pages that is still a draft, private or pending gives visitors a `404` — and instead of telling you the page no longer exists, Onsite Link Verdict tells you it exists and is not published, and which status it is in
* Automatic checks on your site's cron, or start a full-site scan with one click. The scan itself is done in small slices and picks up where it left off, so it never has to finish inside a single request
* Progress you can actually see — "750 of 1,000 links checked (75%)", with a rough time estimate, so you know it is working and not stuck
* Recheck any link on demand — it goes to the front of the queue and is checked on the next pass
* Entries disappear when the content does: remove a link from a post, trash the post, or delete a comment, and the entry leaves the list. The table does not grow forever
* Warns you if your site's cron is disabled, so checks do not silently never run
* Clean uninstall — removes its table, options and scheduled events
* **Ask your AI assistant.** On WordPress 6.9 and later, the plugin registers three abilities that an AI assistant connected to the site can use: a summary of how many links are broken, the list of broken links with where each one is, and a request for a full-site scan. Only administrators can use them, and nothing is checked during the request

= What it does not do =

Onsite Link Verdict **finds** broken links and tells you exactly which post, page or comment each one is in. It does not edit your content: you fix each link in the editor, where you can see it in context.

That is deliberate. Where a link should point is a judgement about your content, and the editor is where you can see the sentence it sits in.

Replacing one URL across an entire site in a single step is the obvious next thing to want. That is what Pro is for, below.

= Onsite Link Verdict Pro =

Everything above is free, and stays free. Pro does the mechanical part, for people with more than a handful of links to fix:

* **Bulk-replace a URL everywhere at once.** You type the new address, you see a list of every place it will change with the text around it, and only then does anything happen. There is an undo, and the undo refuses to run if you edited the content afterwards.
* **It will not touch what it cannot verify.** If a link comes from a shortcode, a page builder or a theme filter, the address is not in the content we would be saving. Onsite Link Verdict says so and leaves it alone, so the report you get matches what actually changed.
* **Silence the domains that never answer bots.** One pattern per line, and they stop appearing.
* **Export any tab to CSV**, in a form that opens correctly in Excel.

Pro is an annual licence, from 29 EUR a year plus VAT, for one site. It is bought from https://onsitetools.dev/onsite-link-verdict/ and there is a link inside the plugin. The free version never asks you for an email address of its own: the only place an email is shared is the activation opt-in described above, which you can decline.

== External services ==

This plugin contacts two external destinations: the websites you link to, and Freemius, which
handles our licensing and payments. Here is what goes to each, and when.

= 1. The websites you link to =

Checking whether a link is alive means requesting it from the server that hosts it. That server
receives the address being checked and, in the `User-Agent` header that identifies the checker,
your site's address and the plugin's version. This happens for every link in your content, on the
plugin's own schedule. It is what the plugin is for, and it cannot be switched off without
switching off the checking.

Links to your own site are requested the same way, over HTTP, from your own server. When one of
those answers `404` or `410`, the plugin then looks the address up in your own database, so it can
tell you the page exists but is a draft, private or pending, instead of reporting it gone.

= 2. Freemius =

Freemius Inc. runs our licensing, updates and checkout. It is contacted in four situations, and
only the first is optional.

**On activation, if you opt in.** WordPress shows you a screen asking whether to share information
about your site. There are four groups, and you can clear all but the first:

* your WordPress user's first and last name and email address (required to opt in at all);
* the home page address and title, the WordPress and PHP versions, and the site language;
* this plugin's own version and whether it is active or uninstalled;
* the names, slugs and versions of the plugins and themes installed on the site, and whether each
  is active. **This one is ticked for you** unless you clear it.

**You can decline the whole screen**, and the plugin works exactly the same — declining is handled
entirely inside your own site and sends nothing.

**On a schedule, if you opted in.** Your site then checks in with Freemius periodically to see
whether a licence has been bought or changed. This happens in the free version too, and needs no
licence key.

**When you open the "Change Plan" screen** inside WordPress. That screen loads the current prices
from Freemius when you open it, whether or not you opted in.

**When you deactivate the plugin.** WordPress shows a short form asking why; if you fill it in, the
reason goes to Freemius. Closing it sends nothing.

And, in the paid add-on only, your licence key is validated with the same service.

Domains contacted: `api.freemius.com`, `wp.freemius.com`, `checkout.freemius.com`.
Freemius terms: https://freemius.com/terms/ · Freemius privacy: https://freemius.com/privacy/

= Nothing else =

No other destination is contacted. Your posts, pages and comments are not uploaded anywhere, and
the result of every check is stored in a table in your own database.

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools → Onsite Link Verdict**.
3. Run the first scan. Depending on your site's size, the first full pass may take a while — it runs in small batches on purpose.

== Frequently Asked Questions ==

= My link checker says a link is broken, but it works when I click it. Why? =

Almost always, the link is fine. Many servers answer an automated request differently from a person with a browser: they refuse it with `403`, slow it down with `429`, answer `503`, or show a challenge page. None of those mean the page is gone.

Onsite Link Verdict only reports a link as broken when the server says the page is gone (`404` or `410`, confirmed with a `GET` request) or when nothing answers at all, three checks in a row. Every other answer stays out of "Broken": refusals (`403`, `429`, `999`, challenge pages) go to "Unverifiable", and server errors wait under "Warnings" and are checked again. Each one comes with a note saying what happened.

= Why do LinkedIn links show error 999? Why do Amazon links show 503 or 429? =

`999` is the code LinkedIn uses to refuse automated requests. Amazon answers `503` or `429` to them. The pages are there; the server just does not talk to robots. Onsite Link Verdict never reports them as broken: `999` and `429` go straight to "Unverifiable"; a `503` is checked again first, and after three answers like it the link goes to "Unverifiable" too. Once there, it is not asked again the next day.

= I just installed it. Where is it, and why is the list empty? =

It lives under **Tools → Onsite Link Verdict**. Press the button to scan the whole site. Finding the links is quick; checking each one happens in the background, in small batches, so the lists fill up over the first minutes or hours depending on the size of your site. The panel shows how far it got ("750 of 1,000 links checked") and an estimate of the time left.

If nothing moves at all, your site's cron is probably disabled, and the plugin shows a warning about it at the top of the page.

= Does it check my whole site? =

Yes: links and images in posts, pages, custom post types and comments, internal and external, including redirect chains. Once the first sweep is done it keeps checking, so a link that dies next month shows up next month.

= Does it work with Elementor, Divi, Beaver Builder or WPBakery? =

Yes. On a page built with a page builder the links are not in the stored post content; they are assembled when the page is rendered. Onsite Link Verdict renders the page first and reads what a visitor sees, so those links are found.

= Does it find broken internal links after I move, unpublish or delete a page? =

Yes. And when a link points at one of your own pages that still exists but is a draft, private or pending, it tells you exactly that, instead of saying the page is gone.

= Does it work on localhost, a staging site or a password-protected site? =

Yes. The checking runs inside your own WordPress, so a site that cannot be reached from the public internet is checked like any other.

= Is it free? Is there a limit on the number of links? =

Finding and checking is free, with no account and no credits, and the number of links does not change anything. The only limit: a single post or comment with more than 1,000 links is checked to its first 1,000. The paid add-on (below) is for fixing many links at once.

= Can I check a link or a whole site without installing anything? =

Yes, with two free tools on our site, no account needed: https://onsitetools.dev/check-a-link/ checks one link the way a robot and a browser see it, side by side, and https://onsitetools.dev/scan/ checks up to 40 public pages of any site.

= Does this send my content anywhere? =

No. Your posts, pages and comments stay where they are, and the results are stored in your own database. There is no cloud service behind the plugin holding your content. See "External services" below for everything that does go out, and why.

= What data does the plugin handle? =

It reads your posts, pages and comments and stores the links it finds in a table in your own database. Your content is not uploaded, and there is no cloud service behind it.

Two destinations are contacted, and both are set out in full under "External services" above: the servers that host the links being checked, and Freemius, which runs our licensing and checkout. The Freemius contact starts with an opt-in screen you can decline.

What that means for your own processing records is for you to decide — this is a description of the plugin, not legal advice.

= Will it slow my site down? =

It is built not to. No checking happens while a visitor is waiting for a page: it runs on your site's cron, in batches, and no new check starts after 45 seconds in a run, whatever the speed setting. The same address repeated across fifty posts is checked once per run, not fifty times. On shared hosting, pick the "Careful" speed.

= Can it fix the broken links for me? =

No. It finds them and tells you exactly where each one is; you change the link in the post editor, where you can see it in context. Replacing a URL across the whole site in one step — with a preview of every change before it happens, and an undo — is what the paid add-on does.

= Why is a link listed as "Unverifiable"? =

Because the server answered us with something that is not `404` or `410`. Some servers refuse automated requests — Amazon, X, LinkedIn and anything behind a bot firewall all do. The link is probably fine; open it in your browser to confirm.

They are kept separate on purpose. A refusal is not a death: the page is there, and a reader with a browser reaches it. Nor is it something you can fix from your own site, since what a distant server does with an automated request is not yours to configure.

= Why is it taking so long to check my links? =

Because it deliberately works in small bites in the background. How long depends on how many links you have and which speed you picked: the panel shows the estimate for your own site next to each setting, under Tools → Onsite Link Verdict.

If it is slower than you want, change the setting to Fast — but read what it says there first. Checking faster means hammering the sites you link to harder, and more of them will start refusing the checks, which puts those links in "Couldn't verify" instead of giving you a real answer.

= I added an exclusion rule and the links are still in the list =

They leave the list as each post is checked again, not all at once. A rule takes effect the next time Onsite Link Verdict scans the post, page or comment a link sits in — so on a small site they disappear quickly, and on a site with hundreds of posts a full sweep takes several background runs. Nothing is stuck; the list is catching up.

If you want a rule to take effect on one specific post right away, open that post and save it. That schedules a fresh scan of it.

(Exclusion rules are part of Pro.)

= Can an AI assistant check my links? =

Yes, on WordPress 6.9 or later. The plugin registers three abilities with the WordPress Abilities API: `onsite-link-verdict/get-summary` (how many links are broken, unverifiable, good or waiting), `onsite-link-verdict/get-links` (the links in one group, with the explanation of each result and the post it appears in) and `onsite-link-verdict/start-scan` (the same as the "Start scan" button). An assistant connected through the MCP Adapter plugin can then answer "are there broken links on this site?" without you opening the panel. They need an administrator account, they never edit your content, and starting a scan only queues it: the checking still runs in small background batches.

= The plugin never seems to check anything =

Your site's cron is probably disabled. Onsite Link Verdict shows a warning at the top of its page when it detects this. Ask your host how to set up a real cron job.

== Screenshots ==

1. The Broken tab, with the "What's happening" column explaining each result in plain language — not just a bare status code.
2. The Unverifiable tab: 401, 403, 429, 451 and 999 responses kept separate from real broken links, because the server is blocking the check, not telling you the page is gone.
3. Choosing how fast to check. Three settings, each with its cost written next to it — and the wait worked out for the number of links actually waiting on your own site.

== Changelog ==

= 0.9.0 =
* **AI assistants can now use the plugin.** On WordPress 6.9 and later it registers three abilities: a summary of the results, the list of links in one group (broken by default) with the plain-language explanation and the post each link is in, and a request for a full-site scan. They are visible to the WordPress REST API and to the MCP Adapter, need an administrator, and give the same numbers and the same explanations as the panel, because they read them from the same code.
* Starting a scan from an assistant does exactly what the "Start scan" button does: it queues the site and returns. Nothing is checked inside the request.

= 0.8.1 =
* Security: updates the bundled Freemius SDK to 2.13.5, which fixes a vulnerability reported to Freemius. Please update.

= 0.8.0 =
* **The plugin can now ask you for a review, once, and only after it has actually done some work for you.** It waits until it has been installed for a week and has checked at least ten links, it appears as an ordinary dismissible notice, and if you say no it never comes back. There is a button for "I have a problem" next to it, which goes to support: if something is wrong we would rather hear it and fix it than read about it later.
* The invitation carries no JavaScript. The usual way to build one has a race in it between opening the review page and recording your answer, and when the browser resolves it the wrong way you get asked again after you have already written the review.
* The seven translations cover the new texts too.

= 0.7.0 =
* **The plugin now speaks seven languages.** Portuguese (Portugal and Brazil), Spanish, French, German, Italian and Dutch, alongside the original English. Every screen is translated, including the plain-language explanation of what happened to each link — which is the part that matters, because a status code is the same in every language and an explanation is not.
* The translation template that shipped with the plugin was out of date: it listed 46 of the 129 texts, because it had been generated back at version 0.1.0 and never refreshed. It is now rebuilt from the current code, so anyone translating the plugin into another language starts from the whole of it.

= 0.6.6 =
* The plugin now links to its own home page, so you can reach the documentation and the paid add-on from the plugins screen instead of searching for them.
* The description says what the plugin checks — internal links, external links, images, links in comments and redirect chains — which it never actually said anywhere.
* Corrected: the description still said the paid add-on was not on sale yet. It has been on sale since 2 September.
* **A new "External services" section sets out both destinations the plugin contacts** — the websites you link to, and Freemius for licensing and checkout — naming the domains, what is sent to each, when, and which parts you can decline. The description used to claim that nothing left your site at all, which was not true: checking a link means contacting the server that hosts it, and the activation opt-in shares your details if you accept it. It is written out now instead of being claimed away.
* Corrected: the description said there was no cap on how many links are checked. There is one — a single post or comment is checked to its first 1,000 links. The panel already told you when that happened; the description did not.

= 0.6.5 =
* Fixed: the "Change Plan" page inside WordPress could open with no plans on it at all. It was asking for prices in US dollars, and the plans are priced in euros. It now opens in euros. The hosted checkout was never affected — it picks the currency from where you are.

= 0.6.4 =
* Fixed: after entering a valid licence key, the panel could still show the invitation to buy Pro. There is one more step after the key — a confirmation email — and until you click the button in it the paid features stay off. The panel now says that, instead of behaving as though you had never bought anything.

= 0.6.3 =
* Fixed: if a background run was interrupted — the host cutting the request short, the site running out of memory — the plugin waits ten minutes and then takes the job back. Two runs that reached that moment together could both decide it was theirs and start checking at the same time, which is twice the work on your server for no extra result. Only one can take it now, and the database is what decides.
* The exclusion rules typed into the box are cleaned before being stored. Web addresses with %-encoded characters are kept exactly as written.

= 0.6.2 =
* The list of links now comes first on the page. The speed setting and the note about the paid add-on moved below it. They were sitting between the tabs and the list, which meant that on a laptop you opened the tool to look at your broken links and saw a setting instead.

= 0.6.1 =
* Fixed: the line above the list still estimated the wait as if every run took an hour, which stopped being true when the speed settings arrived. On Normal it promised "about 19 hours" in the speed box and warned "about 4 days" two lines below, for the same site. Both numbers now come from the same calculation.

= 0.6.0 =
* **You can now choose how fast it checks.** Finding links was always quick; checking whether they are alive was capped at 100 an hour, which meant a site with ten thousand posts waited nearly two weeks for a complete picture. There are now three settings — Careful, Normal and Fast — and the panel shows what each one means for the number of links waiting on *your* site. The default is Normal, which is sixteen times faster than before.
* Faster is not automatically better, and the plugin says so: the quicker you check, the more of the sites you link to will start refusing the checks, and a refused check means the link lands in "Couldn't verify" instead of getting a real answer. Careful is there for shared hosting.
* The per-run time limit did not change. It is the ceiling that actually protects your server, and a run still stops when it hits it.

= 0.5.2 =
* Renamed. The plugin was called Link Sentry; it is now Onsite Link Verdict, after the WordPress.org review pointed out that the old name was too close to an existing service and to a registered trademark. Nothing about what the plugin does has changed.

= 0.5.1 =
* Fixed: with both the free and the paid version installed at the same time — which is exactly the moment somebody upgrades — the paid version could quietly fall back to the free feature set. Only one of the two folders was ever searched for classes. Each folder now looks in its own.

= 0.5.0 =
* Housekeeping, with nothing to see on your site: the code for the paid add-on is now packaged and distributed separately, the way the plugin directory requires. The free version does exactly what it did before.

= 0.3.0 =
* **Links inside page builders are now found.** Elementor, Divi, WPBakery and many shortcodes hook into `the_content` and need to know which post they are drawing, which they ask the global `$post`. Onsite Link Verdict was not setting it up, so on a page built with Elementor — where the post content itself is empty — the scan found **nothing** and reported a clean bill of health. A false all-clear is worse than a false positive: with a false positive you lose time, with this you lose the page and never know. Verified against a real Elementor install.

= 0.2.0 =
* A link is never reported as broken on the strength of a `HEAD` request alone. Some servers answer `404` to `HEAD` and `200` to `GET` — zazzle.com does exactly this — and a `404` is the one verdict Onsite Link Verdict gives immediately, with no second chance. Every `404` and `410` is now confirmed with a `GET` before the link is accused. Healthy links still cost a single request.
* A server that answers is never reported as broken. Only `404`, `410` and a total absence of response now count as proof. This fixes the case where a site that persistently answers `503` to bots — Amazon does exactly this — was reported as a broken link after three checks.
* Challenge pages and bot walls are recognised from the response headers (`CF-Mitigated`, `CF-Chl-Bypass`, `X-DataDome`, `Retry-After`) and go straight to Unverifiable, whatever status code they carry.
* Links already settled as unverifiable are no longer re-checked on the short daily cycle. We stop knocking on doors that told us to go away.
* The Unverifiable tab explains why a server error ended up there, instead of showing a status code that seems to contradict the verdict.

= 0.1.0 =
* First release.
