<?php

declare(strict_types=1);

/**
 * Tests of the rules and rate-limit headers, without FreshRSS: php tests/rules.php
 */
require dirname(__DIR__) . '/lib/SiteRateLimitsRules.php';

$failures = 0;
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
	if ($actual === $expected) {
		echo "ok   $name\n";
	} else {
		$failures++;
		echo "FAIL $name\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
	}
};

$text = <<<'TXT'
	reddit.com 60
	# A comment
	https://Export.arXiv.org/ 3   # trailing comment
	example.net
	bad domain 5
	zero.example 0
	TXT;
$rules = SiteRateLimitsRules::parse($text);
$check('parse', $rules, ['reddit.com' => 60, 'export.arxiv.org' => 3]);
$check('invalid lines', SiteRateLimitsRules::invalidLines($text), ['example.net', 'bad domain 5', 'zero.example 0']);
$check('default rules', SiteRateLimitsRules::parse(SiteRateLimitsRules::DEFAULT), ['reddit.com' => 60]);
$check('empty', SiteRateLimitsRules::parse(''), []);

$check('site: subdomain', SiteRateLimitsRules::site('www.reddit.com', $rules), ['reddit.com', 60]);
$check('site: domain itself, case', SiteRateLimitsRules::site('Reddit.COM.', $rules), ['reddit.com', 60]);
$check('site: other domain ending alike', SiteRateLimitsRules::site('notreddit.com', $rules), ['notreddit.com', 0]);
$check('site: no rule', SiteRateLimitsRules::site('t.me', $rules), ['t.me', 0]);

$now = 1_790_000_000;
$check('window: none left, seconds (Reddit)', SiteRateLimitsRules::windowEnd('0.0', '44', $now), $now + 44);
$check('window: none left, Unix time (GitHub)', SiteRateLimitsRules::windowEnd('0', (string)($now + 600), $now), $now + 600);
$check('window: some left', SiteRateLimitsRules::windowEnd('99.0', '553', $now), 0);
$check('window: fractional reset', SiteRateLimitsRules::windowEnd('0', '1.2', $now), $now + 2);
$check('window: no headers', SiteRateLimitsRules::windowEnd('', '', $now), null);

echo $failures === 0 ? "All passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
