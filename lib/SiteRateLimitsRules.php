<?php

declare(strict_types=1);

/**
 * The rules of the extension, and the rate-limit headers of servers. Plain PHP, without FreshRSS, to be testable alone.
 */
final class SiteRateLimitsRules {
	/** Rules of a new installation */
	public const DEFAULT = "reddit.com 60\n";
	/** Larger values of a reset header are a Unix time (GitHub), smaller ones a number of seconds (Reddit, IETF draft) */
	private const UNIX_TIME_FROM = 1_000_000_000;

	/**
	 * Rules as written in the configuration, one per line: a domain, and the seconds to leave between two requests to it
	 * and its subdomains. Text after `#` is a comment.
	 * @return array<string,int> seconds by domain, in lowercase
	 */
	public static function parse(string $text): array {
		$rules = [];
		foreach (self::lines($text) as $line) {
			$rule = self::parseLine($line);
			if ($rule !== null) {
				$rules[$rule[0]] = $rule[1];
			}
		}
		return $rules;
	}

	/**
	 * @return list<string> the lines that are not a rule, comments and blank lines aside
	 */
	public static function invalidLines(string $text): array {
		return array_values(array_filter(self::lines($text), static fn(string $line): bool => self::parseLine($line) === null));
	}

	/**
	 * The site a host belongs to, for rate limits: the domain of the rule for the host or one of its parent domains,
	 * else the host itself. Then the seconds to leave between two requests to that site, 0 without a rule.
	 * @param array<string,int> $rules
	 * @return array{string,int}
	 */
	public static function site(string $host, array $rules): array {
		$host = strtolower(rtrim($host, '.'));
		$domain = $host;
		while ($domain !== '') {
			if (isset($rules[$domain])) {
				return [$domain, $rules[$domain]];
			}
			$dot = strpos($domain, '.');
			$domain = $dot === false ? '' : substr($domain, $dot + 1);
		}
		return [$host, 0];
	}

	/**
	 * When the server’s current rate-limit window ends, from its headers `X-RateLimit-Remaining` and `X-RateLimit-Reset`
	 * (Reddit, GitHub…) or `RateLimit-Remaining` and `RateLimit-Reset` (IETF draft).
	 * @return int|null The Unix time when the next request is welcome; 0 when requests are left; null when not said
	 */
	public static function windowEnd(string $remaining, string $reset, int $now): ?int {
		if (!is_numeric($remaining) || !is_numeric($reset)) {
			return null;
		}
		if ((float)$remaining >= 1) {
			return 0;
		}
		$reset = (int)ceil((float)$reset);
		return $reset >= self::UNIX_TIME_FROM ? $reset : $now + $reset;
	}

	/**
	 * @return list<string> the non-blank lines without their comments
	 */
	private static function lines(string $text): array {
		$lines = [];
		foreach (preg_split('/\R/', $text) ?: [] as $line) {
			$line = trim(explode('#', $line, 2)[0]);
			if ($line !== '') {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * @return array{string,int}|null
	 */
	private static function parseLine(string $line): ?array {
		if (preg_match('/^(?:https?:\/\/)?([a-z0-9-]+(?:\.[a-z0-9-]+)*)\.?\/?\s+(\d{1,6})$/i', $line, $matches) !== 1 || (int)$matches[2] <= 0) {
			return null;
		}
		return [strtolower($matches[1]), (int)$matches[2]];
	}
}
