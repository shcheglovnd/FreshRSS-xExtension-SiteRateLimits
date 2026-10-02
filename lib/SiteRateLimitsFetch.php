<?php

declare(strict_types=1);

/**
 * SimplePie’s HTTP request for a feed: FreshRSS’s own {@see FreshRSS_SimplePieFetch}, plus, per site
 * ({@see SiteRateLimitsRules::site()}):
 * - before the request, a wait until the site welcomes the next one: the seconds of its rule after the previous
 *   request, or the end of its rate-limit window when its last answer said that no request is left;
 * - after an HTTP 429 all the same (e.g. another device on the same IP address used the site meanwhile), a wait as long
 *   as the server’s `Retry-After` if short, else as the site’s rule, and {@see RETRIES} more request.
 * Only refreshes from the command line, i.e. FreshRSS’s cron, wait. A refresh from the web interface does not.
 *
 * When a site welcomes the next request is kept as the modification time of a file in FreshRSS’s cache directory,
 * shared by processes and users: rate limits are mostly per IP address.
 *
 * It is only made when SimplePie actually downloads the feed, not when its cache of the feed is still fresh.
 */
final class SiteRateLimitsFetch extends \SimplePie\File {
	/** @var array<string,int> Seconds between two requests, by domain ({@see SiteRateLimitsRules::parse()}) */
	public static array $rules = [];
	/** Longest wait before a request, in seconds: a feed whose site needs more is left for a later refresh */
	private const MAX_WAIT = 120;
	/** Most seconds spent waiting in one refresh */
	private const MAX_TOTAL_WAIT = 600;
	/** Seconds added to the time a server gives, for rounding and clock differences */
	private const MARGIN = 1;
	/** More requests after an HTTP 429 */
	private const RETRIES = 1;
	private const FILE_PREFIX = '/site-rate-limit_';

	private static int $waited = 0;

	/**
	 * @param array<string,string>|null $headers
	 * @param array<int,mixed> $curl_options
	 */
	public function __construct(string $url, int $timeout = 10, int $redirects = 5, ?array $headers = null, ?string $useragent = null,
		bool $force_fsockopen = false, array $curl_options = []) {
		[$site, $interval] = self::siteOf($url);
		$canWait = PHP_SAPI === 'cli' && $site !== '';
		$retries = self::RETRIES;
		while (true) {
			if ($canWait) {
				self::wait($site);
			}
			if ($interval > 0) {
				self::book($site, time() + $interval);
			}
			$fetch = new FreshRSS_SimplePieFetch($url, $timeout, $redirects, $headers, $useragent, $force_fsockopen, $curl_options);
			self::remember($site, $fetch);
			if ($fetch->get_status_code() !== 429 || !$canWait || $retries-- <= 0) {
				break;
			}
			$proxy = is_string($curl_options[CURLOPT_PROXY] ?? null) ? $curl_options[CURLOPT_PROXY] : '';
			$retryAt = self::retryTime($fetch->get_final_requested_uri(), $proxy, $interval);
			if ($retryAt === 0) {
				break;
			}
			self::book($site, $retryAt);
			if (self::tooLongToWait($site)) {
				break;	// FreshRSS’s pause stays
			}
			self::liftPause($fetch->get_final_requested_uri(), $proxy);
			Minz_Log::notice('Site Rate Limits: HTTP 429, trying again at ' . date('c', $retryAt) . ': ' . \SimplePie\Misc::url_remove_credentials($url));
		}
		$this->takeOver($fetch);
	}

	/**
	 * @return array{string,int} the site of a URL, and the seconds to leave between two requests to it (0: no rule)
	 */
	public static function siteOf(string $url): array {
		$host = parse_url($url, PHP_URL_HOST);
		return is_string($host) && $host !== '' ? SiteRateLimitsRules::site($host, self::$rules) : ['', 0];
	}

	/** Whether the site needs a longer wait than a refresh does: its feeds are then left for a later refresh. */
	public static function tooLongToWait(string $site): bool {
		$seconds = self::secondsToWait($site);
		return $seconds > 0 && ($seconds > self::MAX_WAIT || self::$waited + $seconds > self::MAX_TOTAL_WAIT);
	}

	private static function file(string $site): string {
		return CACHE_PATH . self::FILE_PREFIX . urlencode($site) . '.txt';
	}

	private static function secondsToWait(string $site): int {
		if ($site === '') {
			return 0;
		}
		$file = self::file($site);
		clearstatcache(true, $file);
		return max(0, (@filemtime($file) ?: 0) - time());
	}

	private static function wait(string $site): void {
		$seconds = self::secondsToWait($site);
		if ($seconds > 0 && !self::tooLongToWait($site)) {
			Minz_Log::debug('Site Rate Limits: waiting ' . $seconds . ' s for ' . $site);
			sleep($seconds);
			self::$waited += $seconds;
		}
	}

	/** The site welcomes no request before that time. */
	private static function book(string $site, int $time): void {
		$file = self::file($site);
		clearstatcache(true, $file);
		if ($time > (@filemtime($file) ?: 0)) {
			@touch($file, $time);
		}
	}

	/** Book the end of the site’s rate-limit window, when the server says no request is left in it. */
	private static function remember(string $site, \SimplePie\File $fetch): void {
		if ($site === '') {
			return;
		}
		$end = SiteRateLimitsRules::windowEnd($fetch->get_header_line('x-ratelimit-remaining'), $fetch->get_header_line('x-ratelimit-reset'), time()) ??
			SiteRateLimitsRules::windowEnd($fetch->get_header_line('ratelimit-remaining'), $fetch->get_header_line('ratelimit-reset'), time());
		if ($end !== null && $end > 0) {
			self::book($site, $end + self::MARGIN);
		}
	}

	/**
	 * When to try again after an HTTP 429, if soon: at the end of the pause FreshRSS has just set from the server’s
	 * `Retry-After`, or after the seconds of the site’s rule. SimplePie does not read the other headers of a 429.
	 * @return int a Unix time, 0 for no retry
	 */
	private static function retryTime(string $url, string $proxy, int $interval): int {
		$pause = FreshRSS_http_Util::getRetryAfter($url, $proxy);
		if ($pause > time() && $pause - time() <= self::MAX_WAIT) {
			return $pause;	// The server’s own Retry-After: FreshRSS’s default pause is longer
		}
		return $interval > 0 ? time() + $interval : 0;
	}

	/** Remove FreshRSS’s pause for the URL ({@see FreshRSS_http_Util::setRetryAfter()}), as it is about to be requested again. */
	private static function liftPause(string $url, string $proxy): void {
		try {
			$file = (new ReflectionMethod(FreshRSS_http_Util::class, 'getRetryAfterFile'))->invoke(null, $url, $proxy);
		} catch (ReflectionException) {
			return;	// Another FreshRSS version: the pause stays, and the next request is answered from it
		}
		if (is_string($file) && $file !== '' && is_file($file)) {
			@unlink($file);
		}
	}

	/** Become the given response: all of SimplePie’s File state, private properties included, as File::fromResponse() does */
	private function takeOver(\SimplePie\File $fetch): void {
		$copy = \Closure::bind(function (\SimplePie\File $from): void {
			foreach (get_object_vars($from) as $name => $value) {
				$this->$name = $value;
			}
		}, $this, \SimplePie\File::class) ?? throw new LogicException('Cannot copy a SimplePie\File');
		$copy($fetch);
	}
}
