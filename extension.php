<?php

declare(strict_types=1);

/**
 * Refresh feeds within the rate limits of their sites, instead of failing with “HTTP 429 Too Many Requests”.
 *
 * FreshRSS refreshes the due feeds one after the other, and only reacts to a 429 afterwards, by pausing the feed.
 * A site that allows few requests, e.g. Reddit with one per minute and per IP address, thus fails all its feeds but
 * the first of each refresh. With this extension, a refresh from the command line (FreshRSS’s cron) waits until the
 * site welcomes the next request ({@see SiteRateLimitsFetch}), as configured per site ({@see SiteRateLimitsRules}) or
 * as the site’s rate-limit headers say, and tries once more after a 429. A feed whose site needs a longer wait is left
 * for a later refresh, without error.
 */
final class SiteRateLimitsExtension extends Minz_Extension {

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/SiteRateLimitsRules.php';
		require_once __DIR__ . '/lib/SiteRateLimitsFetch.php';
		SiteRateLimitsFetch::$rules = SiteRateLimitsRules::parse($this->rulesText());
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('feed_before_actualize', [$this, 'feedBeforeActualize']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
	}

	/** The rules as written in the configuration */
	public function rulesText(): string {
		$rules = $this->getSystemConfigurationValue('rules');
		return is_string($rules) ? $rules : SiteRateLimitsRules::DEFAULT;
	}

	/** In a refresh from the command line, leave a feed for a later refresh when its site needs too long a wait. */
	public function feedBeforeActualize(FreshRSS_Feed $feed): ?FreshRSS_Feed {
		if (PHP_SAPI !== 'cli') {
			return $feed;
		}
		[$site] = SiteRateLimitsFetch::siteOf(htmlspecialchars_decode($feed->url(), ENT_QUOTES));
		if (SiteRateLimitsFetch::tooLongToWait($site)) {
			Minz_Log::debug('Site Rate Limits: ' . $site . ' needs a longer wait, leaving for a later refresh: ' . $feed->url(includeCredentials: false));
			return null;
		}
		return $feed;
	}

	/** SimplePie downloads feeds through {@see SiteRateLimitsFetch}. */
	public function simplepieBeforeInit(\SimplePie\SimplePie $simplePie, FreshRSS_Feed $feed): void {
		$simplePie->get_registry()->register(\SimplePie\File::class, SiteRateLimitsFetch::class);
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();
		if (!Minz_Request::isPost()) {
			return;
		}
		$text = str_replace("\r\n", "\n", Minz_Request::paramString('rules', plaintext: true));
		$this->setSystemConfiguration(['rules' => $text]);	// Not setSystemConfigurationValue(), which FreshRSS 1.28 lacks
		SiteRateLimitsFetch::$rules = SiteRateLimitsRules::parse($text);
		$url = ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
		$invalid = SiteRateLimitsRules::invalidLines($text);
		if ($invalid !== []) {
			Minz_Request::bad(_t('ext.site_rate_limits.invalid', implode(' · ', $invalid)), $url);
		} else {
			Minz_Request::good(_t('feedback.conf.updated'), $url);
		}
	}
}
