<?php
if (defined('WFWAF_VERSION') && !defined('WFWAF_RUN_COMPLETE')) {

/**
 * SQLi-only scanner that avoids copying the unconsumed subject for each token.
 */
class wfWAFSQLiStringScanner extends wfWAFStringScanner {
	private static $offsetPatterns;
	private $patterns;
	private $substringRequired = false;
	private $legacyOverload = false;
	private $positionLimit = -1;
	private $line = 1;
	private $lastNewline = 0;
	private $nextNewline = false;

	/**
	 * Prepare offset forms of the fixed SQL token patterns.
	 * @param string|null $string SQL subject.
	 * @return void
	 */
	public function __construct($string = null) {
		$this->legacyOverload = function_exists('mb_internal_encoding') && (ini_get('mbstring.func_overload') & 2);
		if (self::$offsetPatterns === null) {
			self::$offsetPatterns = array();
			$patterns = array('/^\s+/s', '/./s');
			foreach (wfWAFSQLiLexer::getLexerTokenMatchers() as $matcher) {
				$patterns[] = $matcher->getMatch();
			}
			foreach ($patterns as $pattern) {
				$delimiter = strrpos($pattern, '/');
				$start = substr($pattern, 0, 2) === '/^' ? 2 : 1;
				self::$offsetPatterns[$pattern] = '/\G(?:' . substr($pattern, $start, $delimiter - $start) . ')' . substr($pattern, $delimiter);
			}
		}
		parent::__construct($string);
	}

	/**
	 * Install a subject, retaining substring semantics for malformed UTF-8.
	 * @param string $string SQL subject.
	 * @return void
	 */
	public function setString($string) {
		parent::setString($string);
		$ascii = preg_match('/[\x80-\xFF]/', $string) === 0;
		$this->patterns = self::$offsetPatterns;
		$this->substringRequired = $this->legacyOverload || (!$ascii && preg_match('//u', $string) !== 1);
	}

	/**
	 * Reset the pointer and incremental position bookkeeping.
	 * @return self
	 */
	public function reset() {
		parent::reset();
		$this->positionLimit = -1;
		$this->line = 1;
		$this->lastNewline = 0;
		$this->nextNewline = $this->legacyOverload ? false : strpos($this->getString(), "\n");
		return $this;
	}

	/**
	 * Match and consume a SQL token at the current byte offset.
	 * @param string $regex Existing SQL token pattern.
	 * @return string|null
	 */
	public function scan($regex) {
		if (!$this->canMatchAtOffset($regex)) {
			return parent::scan($regex);
		}
		$pointer = $this->getPointer();
		if (preg_match($this->patterns[$regex], $this->getString(), $matches, 0, $pointer) && strlen($matches[0]) > 0) {
			return $this->setState($matches, $pointer + strlen($matches[0]), $pointer);
		}
		return $this->setState();
	}

	/**
	 * Match a SQL token without changing the current pointer.
	 * @param string $regex Existing SQL token pattern.
	 * @return string|null
	 */
	public function check($regex) {
		if (!$this->canMatchAtOffset($regex)) {
			return parent::check($regex);
		}
		if (preg_match($this->patterns[$regex], $this->getString(), $matches, 0, $this->getPointer()) && strlen($matches[0]) > 0) {
			return $this->setState($matches);
		}
		return $this->setState();
	}

	/**
	 * Restrict offset matching to known patterns and valid character boundaries.
	 * @param string $regex SQL token pattern.
	 * @return bool
	 */
	private function canMatchAtOffset($regex) {
		if ($this->substringRequired || !isset($this->patterns[$regex])) {
			return false;
		}
		$pointer = $this->getPointer();
		$string = $this->getString();
		return $pointer >= $this->getLength() || (ord($string[$pointer]) & 0xC0) !== 0x80;
	}

	/**
	 * Return the same line as the shared scanner without rescanning the prefix.
	 * @return int
	 */
	public function getLine() {
		if ($this->legacyOverload) {
			return parent::getLine();
		}
		$this->updatePosition();
		return $this->line;
	}

	/**
	 * Preserve the shared scanner's inclusive-pointer column convention.
	 * @return int
	 */
	public function getColumn() {
		if ($this->legacyOverload) {
			return parent::getColumn();
		}
		$this->updatePosition();
		return $this->getPointer() - $this->lastNewline + 1;
	}

	/**
	 * Advance cached newline positions, restarting if the pointer moves backward.
	 * @return void
	 */
	private function updatePosition() {
		$limit = min($this->getPointer() + 1, $this->getLength());
		if ($limit < $this->positionLimit) {
			$this->line = 1;
			$this->lastNewline = 0;
			$this->nextNewline = strpos($this->getString(), "\n");
		}
		while ($this->nextNewline !== false && $this->nextNewline < $limit) {
			$this->line++;
			$this->lastNewline = $this->nextNewline;
			$this->nextNewline = strpos($this->getString(), "\n", $this->nextNewline + 1);
		}
		$this->positionLimit = $limit;
	}
}
}
