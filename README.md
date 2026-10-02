# Site Rate Limits — FreshRSS extension

Refresh feeds in [FreshRSS](https://freshrss.org) within the rate limits of their sites. When a site
allows only a few requests, the refresh waits for the next allowed one, instead of failing with
`429 Too Many Requests`. Reddit, for example, allows one request per minute.

## Why

FreshRSS refreshes the due feeds one after the other. It only reacts to a 429 afterwards, by pausing
the feed. It has no setting to space or to retry requests, and its maintainers leave rate limiting to
extensions ([FreshRSS#8928](https://github.com/FreshRSS/FreshRSS/issues/8928)).

So a site that limits requests per IP address fails all its feeds but the first one of each refresh.
The clearest case is Reddit:

* Since June 2026 it allows a logged-out client **one request per calendar minute**, whatever the
  user agent or subreddit.
* It says so in every answer:

  ```http
  x-ratelimit-used: 1
  x-ratelimit-remaining: 0.0
  x-ratelimit-reset: 44
  ```

* It answers a second request in the same minute with a 429, which has no `Retry-After`.

Several Reddit feeds due together therefore end in error, refresh after refresh.

## What it does

* **Waits.** Before FreshRSS downloads a feed, the refresh waits until the feed's site welcomes the
  next request:
  * the seconds of the site's **rule** since its previous request (see below), or
  * the end of its rate-limit window, when its last answer said no request was left.

  The site's headers count for that: `X-RateLimit-Remaining` and `X-RateLimit-Reset` (Reddit,
  GitHub…), or `RateLimit-Remaining` and `RateLimit-Reset` (IETF draft).
* **Retries once after a 429**, e.g. when another device on the same IP address used the site
  meanwhile.
  * It waits as long as the server's `Retry-After` when that is short, else for the site's rule.
  * Then it tries once more, and first lifts the pause FreshRSS has just set on the feed.
* **Leaves a feed for a later refresh** when its site needs a wait of more than 2 minutes, e.g. a
  rule of 10 minutes. Nothing is requested, and the feed is not marked in error.
* **Only background refreshes wait or skip.** These are FreshRSS's cron or
  `./cli/actualize-user.php`. A refresh from the web interface answers at once, as before.

Waiting happens only when FreshRSS really downloads a feed. Refreshes that its cache answers don't
wait. That cache follows the site's `Cache-Control`, which for Reddit is an hour.

## Rules

Under *Administration → Extensions → Site Rate Limits*, one site per line: a domain, then the
seconds to leave between two requests to it **and its subdomains**.

```text
reddit.com 60          # Reddit allows one request per minute without an account
export.arxiv.org 3     # arXiv asks for one request every 3 seconds
```

* The default rules are `reddit.com 60`.
* Text after `#` is a comment. Lines that are not a rule are reported when you save.
* A site without a rule is only waited for when its headers ask.
* After a 429 from a site without a rule, the extension retries only if `Retry-After` is short. Long
  pauses are left to FreshRSS.
* A rule of more than 2 minutes means one feed of that site per refresh: the others are left for
  later refreshes.

## Details

* **Limits.** At most 2 minutes for one wait, and 10 minutes of waiting in one refresh run. After
  that, feeds of sites that need a wait are left for the next refresh.
* **Per site, not per feed.** The limits count for the whole site across processes and users, as
  sites mostly limit per IP address. When a site may next be requested is kept as the modification
  time of `data/cache/site-rate-limit_<site>.txt`.
* **Which feeds.** Waiting and retrying apply to the RSS and Atom feeds that FreshRSS downloads
  itself. Feeds read by web scraping (HTML + XPath, JSON), or downloaded by another extension,
  have no hook around their request. They are still left for later while their site needs more
  than 2 minutes.
* **Cost.** A refresh takes as much longer as its waits.
* **Compared with [RateLimitFeeds](https://github.com/pe1uca/xExtension-RateLimiter):** that
  extension skips feeds instead of waiting, and applies one limit to every site.

## Installation

1. Put this repository in FreshRSS's `extensions/` folder, e.g. as
   `extensions/xExtension-SiteRateLimits`:

   ```sh
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/shcheglovnd/FreshRSS-xExtension-SiteRateLimits.git xExtension-SiteRateLimits
   ```

2. Enable **Site Rate Limits** under *Administration → Extensions*. It is a system extension, so it
   works for all users, and only an administrator can configure it.
3. Optionally, edit the rules in its settings.

Requires FreshRSS 1.28 or newer.

## Development

```sh
php tests/rules.php    # rules and rate-limit headers, without FreshRSS
```

## License

[MIT](LICENSE)
