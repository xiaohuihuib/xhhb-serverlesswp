<?php

class wfShutdownFunction {

	private $callable;
	private $priority;

	public function __construct($callable, $priority) {
		$this->callable = $callable;
		$this->priority = $priority;
	}

	public function invoke() {
		return call_user_func($this->callable);
	}

	public function getPriority() {
		return $this->priority;
	}

	public function __wakeup() {
		$this->callable = function() {};
	}

}

class wfShutdownRegistry {

	private static $instance = null;

	const PRIORITY_LAST = 100;

	private $functions = array();
	private $registered = false;

	public function handleShutdown() {
		usort($this->functions, function ($a, $b) {
			return $a->getPriority() - $b->getPriority();
		});
		foreach ($this->functions as $function) {
			try {
				if ($function->invoke() === false && class_exists('wfWAFRuntimeHealth', false)) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('shutdown');
				}
			}
			catch (Exception $e) {
				if (class_exists('wfWAFRuntimeHealth', false)) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('shutdown');
				}
			}
			catch (Throwable $e) {
				if (class_exists('wfWAFRuntimeHealth', false)) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('shutdown');
				}
			}
		}
		if (class_exists('wfWAFRuntimeHealth', false)) {
			wfWAFRuntimeHealth::flush();
		}
	}

	public function register($function, $priority = 50) {
		array_push($this->functions, new wfShutdownFunction($function, $priority));
		$this->registerSelf();
	}

	private function registerSelf() {
		if (!$this->registered) {
			register_shutdown_function(array($this, 'handleShutdown'));
			$this->registered = true;
		}
	}

	public function __wakeup() {
		$this->functions = array();
		$this->registered = false;
	}

	public static function getDefaultInstance() {
		if (self::$instance === null)
			self::$instance = new self();
		return self::$instance;
	}

}