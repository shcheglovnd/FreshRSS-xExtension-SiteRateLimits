<?php

return [
	'site_rate_limits' => [
		'intro' => 'Background refreshes wait until a site welcomes the next request, instead of failing with “429 Too Many Requests”: as long as the site’s rule below asks, or as its rate-limit headers say (<code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code>, or <code>RateLimit-Remaining</code> and <code>RateLimit-Reset</code>). After a 429 all the same, the request is made once more after the wait. A feed whose site needs more than two minutes is left for a later refresh, without error. Refreshes from the web interface do not wait.',
		'rules' => 'Rules',
		'rules_help' => 'One site per line: a domain, then the seconds to leave between two requests to it and its subdomains. <code>reddit.com 60</code> fits Reddit, which allows one request per minute without an account. Text after <code>#</code> is a comment. A site without a rule is only waited for when its headers ask.',
		'invalid' => 'Not a rule, ignored: %s',
	],
];
