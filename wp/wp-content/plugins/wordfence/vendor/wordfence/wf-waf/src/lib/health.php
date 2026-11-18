<?php

if (!class_exists('wfWAFRuntimeHealth', false)) {
	/** Bounded internal protection observations for the current request only. */
	class wfWAFRuntimeHealth
	{
		const SCHEMA = 1;
		const MAX_ISSUES = 24;
		// Legacy format constants retained for callers; persistence is disabled.
		const MAX_BYTES = 32768;
		const WRITE_INTERVAL = 300;
		const HEADER = "<?php exit('Access denied'); __halt_compiler(); ?>\n";
		private static $owner = '';
		private static $started = 0;
		private static $outcome = 'unknown';
		private static $failures = array();
		private static $completed = array();

		/**
		 * Start request-local observation for the installation executing the WAF.
		 *
		 * @param string $owner Installation path.
		 * @param string|null $directory Ignored legacy diagnostic directory.
		 * @return void
		 */
		public static function begin($owner, $directory = null) {
			if (self::$started) {
				return;
			}
			self::$started = microtime(true);
			self::$owner = hash('sha256', $owner);
			self::$outcome = 'incomplete';
		}

		/**
		 * Compatibility hook; transient maintenance failures are intentionally silent.
		 *
		 * @param string $component Trusted component identifier.
		 * @return void
		 */
		public static function reportMaintenanceFailure($component) {
		}

		/**
		 * Record a completed component without erasing a failure earlier in this request.
		 *
		 * @param string $component Stable component identifier.
		 * @return void
		 */
		public static function complete($component) {
			if (self::identifier($component) && count(self::$completed) < 64) {
				self::$completed[$component] = true;
			}
		}

		/**
		 * Record only trusted identifiers, never exception messages, arguments, or request data.
		 *
		 * @param string $component Component.
		 * @param string $code Failure code.
		 * @param Exception|Throwable|null $cause Cause.
		 * @param bool $protection Whether protection was affected.
		 * @return void
		 */
		public static function failure($component, $code, $cause = null, $protection = true) {
			// Keep the first observation per component; only a protection-loss upgrade warrants another report.
			if (is_string($component) && isset(self::$failures[$component]) && (self::$failures[$component]['protection'] || !$protection)) {
				return;
			}
			if (!self::identifier($component) || !self::identifier($code)) {
				return;
			}
			if (!isset(self::$failures[$component]) && count(self::$failures) >= self::MAX_ISSUES) {
				return;
			}
			$kind = is_object($cause) ? get_class($cause) : 'InvalidData';
			if (strpos($kind, '@anonymous') !== false) {
				$kind = 'AnonymousThrowable';
			}
			$kind = substr(preg_replace('/[^a-zA-Z0-9_\\\\]/', '', $kind), 0, 128);
			$now = microtime(true);
			self::$failures[$component] = array('code' => $code, 'kind' => $kind, 'protection' => (bool) $protection, 'first' => $now, 'last' => $now);
		}

		/**
		 * Record a terminal or continuing execution outcome.
		 *
		 * @param string $outcome Outcome.
		 * @return void
		 */
		public static function outcome($outcome) {
			if (in_array($outcome, array('unknown', 'incomplete', 'evaluated', 'allowed', 'blocked', 'learning', 'disabled'), true)) {
				self::$outcome = $outcome;
			}
		}

		/**
		 * Return bounded observations from this request only.
		 *
		 * @return array
		 */
		public static function snapshot() {
			$issues = self::$failures;
			return array('schema' => self::SCHEMA, 'owner' => self::$owner, 'outcome' => self::$outcome, 'protection' => self::protectionState($issues), 'recovery' => $issues ? 'pending' : 'clear', 'completed' => array_keys(self::$completed), 'issues' => $issues);
		}

		/**
		 * Classify execution separately from auxiliary errors and unresolved protection failures.
		 *
		 * @param array $issues Sanitized issues.
		 * @return string
		 */
		private static function protectionState($issues) {
			if (self::$outcome === 'disabled' || self::$outcome === 'learning') {
				return self::$outcome;
			}
			foreach ($issues as $issue) {
				if ($issue['protection']) {
					return isset(self::$completed['rule-evaluation']) || isset(self::$completed['plugin-blocks']) || isset(self::$completed['ip-blocks']) || self::$outcome === 'blocked' ? 'partial' : 'unavailable';
				}
			}
			return self::$outcome === 'incomplete' ? 'unavailable' : self::$outcome;
		}

		/**
		 * Check protection independently of initialization and configuration readability.
		 *
		 * @return bool|null Null denotes an unobserved legacy engine.
		 */
		public static function operational() {
			if (!self::$started) {
				return null;
			}
			foreach (self::snapshot()['issues'] as $issue) {
				if ($issue['protection']) {
					return false;
				}
			}
			return self::$outcome !== 'incomplete';
		}

		/**
		 * Validate bounded identifiers supplied by code.
		 *
		 * @param mixed $value Identifier.
		 * @return bool
		 */
		private static function identifier($value) {
			return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value);
		}

		/**
		 * Compatibility hook; request observations are never persisted.
		 *
		 * @return void
		 */
		public static function flush() {
		}
	}
}
