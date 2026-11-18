<?php

/** Shared security news transport, selection, and dashboard integration. */
class wfSecurityNews {
	const CACHE_KEY = 'securityNewsPingCache';
	const STALE_SECONDS = 604800;
	const MAX_BYTES = 262144;
	const POST_COUNT = 5;

	/**
	 * Initialize missing news options even when the plugin version has not changed.
	 *
	 * @return void
	 */
	public static function ensureDefaultSettings() {
		foreach (array('wp', 'wf') as $surface) {
			$enabled = 'security_news_' . $surface . '_enabled';
			$layout = 'security_news_' . $surface . '_layout';
			if (wfConfig::get($enabled, null) === null) {
				wfConfig::setBool($enabled, wfConfig::$defaultConfig['checkboxes'][$enabled]['value']);
			}
			if (wfConfig::get($layout, null) === null) {
				wfConfig::set($layout, wfConfig::$defaultConfig['otherParams'][$layout]['value']);
			}
		}
	}

	/**
	 * Determine whether this dashboard may display news.
	 *
	 * @param string $surface wp or wf.
	 * @return bool
	 */
	public static function enabled($surface) {
		return in_array($surface, array('wp', 'wf'), true) && wfUtils::isAdmin()
			&& (!is_multisite() || is_network_admin()) && wfConfig::getBool('security_news_' . $surface . '_enabled');
	}

	/**
	 * Register the independently dismissible WordPress widget.
	 *
	 * @return void
	 */
	public static function registerWidget() {
		if (self::enabled('wp')) {
			wp_add_dashboard_widget('wordfence_security_news', __('Wordfence Security News', 'wordfence'), array(__CLASS__, 'renderWidget'));
		}
	}

	/**
	 * Render the WordPress widget callback.
	 *
	 * @return void
	 */
	public static function renderWidget() {
		self::render('wp');
	}

	/**
	 * Enqueue assets only on enabled news screens, before the admin head.
	 *
	 * @return void
	 */
	public static function enqueueAssets() {
		global $pagenow;
		$surface = $pagenow === 'index.php' ? 'wp' : ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'Wordfence' && empty($_GET['subpage']) ? 'wf' : null);
		if (!$surface || !self::enabled($surface)) { return; }
		wp_enqueue_style('wordfence-security-news', wfUtils::getBaseURL() . wfUtils::versionedAsset('css/security-news.css'), array('dashicons'), WORDFENCE_VERSION);
	}

	/**
	 * Render an enabled surface using the shared view.
	 *
	 * @param string $surface wp or wf.
	 * @return void
	 */
	public static function render($surface) {
		if (!self::enabled($surface)) { return; }
		$news = self::selectPosts(self::getFeed());
		$layout = wfConfig::get('security_news_' . $surface . '_layout') === 'compact' ? 'compact' : 'rich';
		echo wfView::create('dashboard/security-news', array('surface' => $surface, 'news' => $news, 'layout' => $layout))->render();
	}

	/** Request news independently of the user or screen running the ping. @return array */
	public static function pingRequest() {
		if (!self::requestsEnabled()) { return array(); }
		$cache = self::cache();
		return array('securityNewsHash' => $cache === null ? '' : $cache['hash']);
	}

	/** Determine whether either configured surface needs delivery. @return bool */
	private static function requestsEnabled() {
		return wfConfig::getBool('security_news_wp_enabled') || wfConfig::getBool('security_news_wf_enabled');
	}

	/** Validate persisted content before advertising its hash or rendering it. @return array|null */
	private static function cache() {
		$cache = wfConfig::getJSON(self::CACHE_KEY, null);
		if (!is_array($cache) || !isset($cache['payload'], $cache['hash'], $cache['checkedAt'])
			|| !is_string($cache['payload']) || strlen($cache['payload']) > self::MAX_BYTES
			|| !is_string($cache['hash']) || !hash_equals(hash('sha256', $cache['payload']), $cache['hash'])
			|| !self::validCheckedAt($cache['checkedAt'])) { return null; }
		$cache['feed'] = self::validateFeed(json_decode($cache['payload'], true));
		return $cache['feed'] === null ? null : $cache;
	}

	/** Accept only a recent upstream validation time, tolerating five minutes of clock skew. @param mixed $time UTC seconds. @return bool */
	private static function validCheckedAt($time) {
		return is_int($time) && $time > time() - self::STALE_SECONDS && $time <= time() + 300;
	}

	/**
	 * Process optional news fields from an existing successful license ping.
	 * Invalid or missing fields leave prior content untouched; unchanged hashes confirm only an existing usable copy.
	 * @param array $response Decoded ping response.
	 * @return void
	 */
	public static function processPingResponse($response) {
		if (!self::requestsEnabled() || !isset($response['securityNewsHash'], $response['securityNewsCheckedAt'])
			|| !is_string($response['securityNewsHash']) || !preg_match('/^[a-f0-9]{64}$/D', $response['securityNewsHash'])
			|| !self::validCheckedAt($response['securityNewsCheckedAt'])) { return; }
		if (array_key_exists('securityNews', $response)) {
			$payload = $response['securityNews'];
			if (!is_string($payload) || strlen($payload) > self::MAX_BYTES
				|| !hash_equals(hash('sha256', $payload), $response['securityNewsHash'])
				|| self::validateFeed(json_decode($payload, true)) === null) { return; }
		}
		else {
			$cache = self::cache();
			if ($cache === null || !hash_equals($cache['hash'], $response['securityNewsHash'])) { return; }
			$payload = $cache['payload'];
		}
		wfConfig::setJSON(self::CACHE_KEY, array('payload' => $payload, 'hash' => $response['securityNewsHash'],
			'checkedAt' => min(time(), $response['securityNewsCheckedAt'])), wfConfig::DONT_AUTOLOAD);
	}

	/** Read network-wide local news without performing HTTP requests. @return array|null */
	public static function getFeed() {
		$cache = self::cache();
		return $cache === null ? null : $cache['feed'];
	}

	/**
	 * Validate the versioned envelope and normalize valid posts.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|null
	 */
	public static function validateFeed($data) {
		if (!is_array($data) || !isset($data['version'], $data['posts']) || $data['version'] !== 1 || !is_array($data['posts']) || count($data['posts']) > 50) { return null; }
		$posts = array();
		foreach ($data['posts'] as $item) {
			$post = self::validatePost($item);
			if ($post !== null && !isset($posts[$post['id']])) { $posts[$post['id']] = $post; }
		}
		// A malformed non-empty feed must not replace a useful cached response.
		if (count($data['posts']) && !$posts) { return null; }
		return array('posts' => array_values($posts));
	}

	/**
	 * Normalize a single plain-text article.
	 *
	 * @param mixed $post Feed item.
	 * @return array|null
	 */
	private static function validatePost($post) {
		if (!is_array($post)) { return null; }
		foreach (array('id' => 128, 'url' => 2048, 'title' => 500, 'excerpt' => 2000) as $key => $limit) {
			if (!isset($post[$key]) || !is_string($post[$key]) || strlen($post[$key]) > $limit || ($key !== 'excerpt' && trim($post[$key]) === '')) { return null; }
		}
		if (!self::validArticleURL($post['url']) || !isset($post['published_at']) || !is_int($post['published_at']) || $post['published_at'] <= 0) { return null; }
		$post['title'] = trim(wp_strip_all_tags($post['title']));
		if ($post['title'] === '') { return null; }
		$badge = isset($post['badge']) && in_array($post['badge'], array('none', 'high', 'urgent', 'new'), true) ? $post['badge'] : 'none';
		$priority = isset($post['priority']) && is_int($post['priority']) && $post['priority'] >= 0 && $post['priority'] <= 5 ? $post['priority'] : 0;
		$icon = isset($post['icon']) && in_array($post['icon'], array('news', 'alert', 'wordpress', 'security', 'trend', 'code', 'research', 'login', 'plugins', 'learning'), true) ? $post['icon'] : 'news';
		return array('id' => $post['id'], 'url' => $post['url'], 'title' => trim(wp_strip_all_tags($post['title'])), 'excerpt' => trim(wp_strip_all_tags($post['excerpt'])), 'published_at' => $post['published_at'], 'badge' => $badge, 'icon' => $icon, 'priority' => $priority, 'is_lead' => $priority === 5 && isset($post['is_lead']) && $post['is_lead'] === true);
	}

	/**
	 * Accept safe absolute HTTPS links; NOC1 enforces the publisher's host and path policy.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function validArticleURL($url) {
		$parts = wp_parse_url($url);
		return is_array($parts) && isset($parts['scheme'], $parts['host'], $parts['path'])
			&& $parts['scheme'] === 'https' && $parts['host'] !== '' && isset($parts['path'][0]) && $parts['path'][0] === '/'
			&& !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
			&& !preg_match('/[\x00-\x20\\\\]/', $url);
	}

	/**
	 * Select five unique stories in publisher order.
	 *
	 * @param array|null $feed Normalized feed.
	 * @param int|null $now Testable UTC time.
	 * @return array
	 */
	public static function selectPosts($feed, $now = null) {
		$now = $now === null ? time() : $now;
		if (!$feed) { return array('posts' => array(), 'hasLead' => false); }
		$posts = array_filter($feed['posts'], function($post) use ($now) { return $post['published_at'] <= $now; });
		$posts = array_values($posts);
		// Preserve editorial order, including during an outage.
		$first = $posts ? $posts[0] : null;
		$hasLead = $first && $first['is_lead'];
		return array('posts' => array_slice($posts, 0, self::POST_COUNT), 'hasLead' => $hasLead);
	}

	/**
	 * Prefix NOC1-validated destinations with the surface campaign, retaining host, query, and fragment.
	 *
	 * @param string $url Validated HTTPS article URL.
	 * @param string $surface wp or wf.
	 * @return string
	 */
	public static function attributedURL($url, $surface) {
		$prefix = $surface === 'wp' ? 'gnl1wpSecurityNews' : 'gnl1wfSecurityNews';
		$pathStart = strpos($url, '/', strlen('https://'));
		return substr($url, 0, $pathStart + 1) . $prefix . '/' . substr($url, $pathStart + 1);
	}
}
