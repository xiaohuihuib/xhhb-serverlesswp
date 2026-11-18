<?php

/** @internal Native warning captured at a WAF file-read operation. */
class wfWAFFileReadWarning extends ErrorException {
	private $operation;

	/**
	 * Preserve the original native warning and its operation.
	 *
	 * @param string $operation Native operation name.
	 * @param string $message Original warning.
	 * @param int $severity PHP error level.
	 * @param string $file Warning source file.
	 * @param int $line Warning source line.
	 * @return void
	 */
	public function __construct($operation, $message, $severity, $file, $line) {
		parent::__construct($message, 0, $severity, $file, $line);
		$this->operation = $operation;
	}

	/**
	 * Return the native operation that failed.
	 *
	 * @return string Operation name.
	 */
	public function getOperation() {
		return $this->operation;
	}
}

/** @internal Scoped capture and request-local deduplication for WAF read errors. */
class wfWAFFileReadDiagnostics {
	private static $reported = array();

	/**
	 * Capture native warnings at the operation's call site, forwarding other errors.
	 * The callback must contain only the native operation; included code and stream
	 * callbacks then have distinct source locations and retain their error handling.
	 *
	 * @param string $operation Native operation name.
	 * @param Closure $callback Operation to perform.
	 * @return mixed Operation result.
	 * @throws Exception|Throwable The original exception or captured native warning.
	 */
	public static function capture($operation, $callback) {
		$source = new ReflectionFunction($callback);
		$file = $source->getFileName();
		$firstLine = $source->getStartLine();
		$lastLine = $source->getEndLine();
		$previous = null;
		$previous = set_error_handler(function ($severity, $message, $errorFile, $line) use ($operation, $file, $firstLine, $lastLine, &$previous) {
			if (($severity === E_WARNING || $severity === E_NOTICE) && $errorFile === $file && $line >= $firstLine && $line <= $lastLine) {
				throw new wfWAFFileReadWarning($operation, $message, $severity, $errorFile, $line);
			}
			if ($previous !== null) {
				return call_user_func_array($previous, func_get_args());
			}
			return false;
		});
		try {
			return $callback();
		}
		finally {
			restore_error_handler();
		}
	}

	/**
	 * Log one exhausted read per file per request without exposing arbitrary data.
	 * Only captured native warnings supply message text; other exceptions supply
	 * their class and source location alongside the trusted failure description.
	 *
	 * @param string $path Affected configuration or rules path.
	 * @param string $operation Trusted fallback operation description.
	 * @param string $description Trusted fallback failure description.
	 * @param Exception|Throwable $failure Final failure with its original causes.
	 * @return void
	 */
	public static function report($path, $operation, $description, $failure) {
		if (isset(self::$reported[$path])) {
			return;
		}
		self::$reported[$path] = true;
		try {
			$cause = $failure;
			while ($cause->getPrevious() !== null && !($cause instanceof wfWAFFileReadWarning)) {
				$cause = $cause->getPrevious();
			}
			if ($cause instanceof wfWAFFileReadWarning) {
				$operation = $cause->getOperation();
				$description = $cause->getMessage();
			}
			$entry = 'Wordfence WAF file read failed: ' . $operation . ' [' . $path . ']: ' . $description .
				' (' . get_class($cause) . ' at ' . $cause->getFile() . ':' . $cause->getLine() . ').';
			$entry = str_replace(array("\r", "\n", "\0"), array('\\r', '\\n', '\\0'), $entry);
			self::capture('error_log', function () use ($entry) {
				return error_log($entry);
			});
		}
		catch (Exception $e) {
		}
		catch (Throwable $e) {
		}
	}
}
