<?php

/** Collects authentication failures for Live Traffic without enforcing login limits. */
class wfAuthenticationLog {
	private $username = '';
	private $applicationError;
	private $matchedApplicationError;
	private $applicationFailure;
	private $recognizedApplicationFailure = false;
	private $applicationSucceeded = false;
	private $restObserved = false;
	private $restDispatched = false;
	private $event;

	/**
	 * Registers observers before request logging resolves the current user.
	 * @return void
	 */
	public function __construct() {
		add_filter('authenticate', array($this, 'beginAuthentication'), 1, 3);
		add_action('wp_authenticate_application_password_errors', array($this, 'applicationPasswordChecked'), PHP_INT_MAX, 4);
		add_action('application_password_failed_authentication', array($this, 'applicationPasswordFailed'));
		add_action('application_password_did_authenticate', array($this, 'applicationPasswordSucceeded'));
		add_filter('authenticate', array($this, 'finishAuthentication'), PHP_INT_MAX, 3);
		add_filter('rest_authentication_errors', array($this, 'initializeRestAuthentication'), 1);
		add_filter('rest_authentication_errors', array($this, 'observeRestAuthentication'), PHP_INT_MAX);
		add_filter('rest_pre_dispatch', array($this, 'restDispatch'), PHP_INT_MAX, 3);
		add_filter('rest_post_dispatch', array($this, 'finishRestAuthentication'), PHP_INT_MAX, 3);
	}

	/**
	 * Starts a new ordinary/XML-RPC authentication attempt, discarding only provisional context.
	 * @param mixed $result Authentication result.
	 * @param string $username Supplied username.
	 * @param string $password Unused; never retained.
	 * @return mixed Unchanged result.
	 */
	public function beginAuthentication($result, $username, $password) {
		$this->username = $this->boundedUsername($username);
		$this->applicationError = null;
		$this->matchedApplicationError = null;
		$this->applicationFailure = null;
		$this->recognizedApplicationFailure = false;
		$this->applicationSucceeded = false;
		return $result;
	}

	/**
	 * Tracks the error object associated with a verified application password.
	 * @param WP_Error $error Mutable constraint errors.
	 * @param WP_User $user Verified account.
	 * @param array $item Unused application-password metadata.
	 * @param string $password Unused; never retained.
	 * @return void
	 */
	public function applicationPasswordChecked($error, $user, $item, $password) {
		$this->matchedApplicationError = $error;
		$this->username = $this->boundedUsername($user->user_login);
	}

	/**
	 * Captures an application-password failure without persisting an intermediate result.
	 * @param WP_Error $error Authentication error, retained only for attempt identity.
	 * @return void
	 */
	public function applicationPasswordFailed($error) {
		$this->applicationError = $error;
		$username = doing_filter('authenticate') ? $this->username : (isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : $this->username);
		$this->applicationFailure = $this->makeEvent('applicationPassword', $error->get_error_code(), $username);
	}

	/**
	 * Discards provisional application-password failures after successful authentication.
	 * @param WP_User $user Authenticated account.
	 * @return void
	 */
	public function applicationPasswordSucceeded($user) {
		$this->applicationSucceeded = true;
		$this->applicationError = null;
		$this->applicationFailure = null;
	}

	/**
	 * Identifies only this attempt's unambiguous application-password rejection.
	 * @param mixed $result Authentication result being processed by brute-force protection.
	 * @return bool Whether generic login logging and counting should be skipped.
	 */
	public function isApplicationPasswordFailure($result) {
		if (!doing_filter('authenticate') || !is_wp_error($result) || $result !== $this->applicationError) {
			return false;
		}
		$specific = $result === $this->matchedApplicationError || in_array($result->get_error_code(), array('application_passwords_disabled', 'application_passwords_disabled_for_user'), true);
		$this->recognizedApplicationFailure = $specific;
		return $specific;
	}

	/**
	 * Finalizes specifically identified application-password failures in the login pipeline.
	 * @param mixed $result Final authentication result.
	 * @param string $username Supplied username.
	 * @param string $password Unused; never retained.
	 * @return mixed Unchanged result.
	 */
	public function finishAuthentication($result, $username, $password) {
		if (is_wp_error($result) && ($this->recognizedApplicationFailure || $this->isApplicationPasswordFailure($result))) {
			$this->event = $this->applicationFailure;
		}
		return $result;
	}

	/**
	 * Resolves core credentials before REST application-password errors are checked.
	 * @param mixed $result Authentication result from an earlier handler.
	 * @return mixed Unchanged result; core remains responsible for authentication.
	 */
	public function initializeRestAuthentication($result) {
		if ($result === null) {
			// The REST server clears a cached anonymous user after early plugin initialization.
			wp_get_current_user();
		}
		return $result;
	}

	/**
	 * Marks the outer REST authentication check; persistence waits for its actual outcome.
	 * @param mixed $result Authentication result.
	 * @return mixed Unchanged result.
	 */
	public function observeRestAuthentication($result) {
		$this->restObserved = true;
		$this->restDispatched = false;
		return $result;
	}

	/**
	 * Records that authentication passed and endpoint authorization is now being evaluated.
	 * @param mixed $result Dispatch short circuit.
	 * @param WP_REST_Server $server REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed Unchanged result.
	 */
	public function restDispatch($result, $server, $request) {
		$this->restDispatched = true;
		return $result;
	}

	/**
	 * Records the final REST authentication outcome, never endpoint permission errors.
	 * @param WP_REST_Response $response Completed response.
	 * @param WP_REST_Server $server REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response Unchanged response.
	 */
	public function finishRestAuthentication($response, $server, $request) {
		if (!$this->restObserved) {
			return $response;
		}
		if (!$this->restDispatched && $response->get_status() >= 400 && $this->hasAuthenticationEvidence()) {
			$data = $response->get_data();
			$code = is_array($data) && isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
			if ($this->applicationError && in_array($code, $this->applicationError->get_error_codes(), true)) {
				$this->event = $this->applicationFailure;
			}
			else {
				$user = wp_get_current_user();
				$username = $user->exists() ? $user->user_login : (isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '');
				$this->event = $this->makeEvent($code === 'rest_cookie_invalid_nonce' ? 'restCookie' : 'rest', $code, $username);
			}
		}
		else if ($this->restDispatched && !$this->applicationSucceeded && !get_current_user_id() && isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && function_exists('wp_is_application_passwords_available') && !wp_is_application_passwords_available()) {
			// Core skips Basic authentication entirely when application passwords are disabled.
			$this->event = $this->makeEvent('applicationPassword', 'application_passwords_disabled', $_SERVER['PHP_AUTH_USER']);
		}
		if (!$this->event && !$this->applicationSucceeded && !get_current_user_id() && $response->get_status() === 401 && isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
			if ($this->applicationFailure) {
				$this->event = $this->applicationFailure;
			}
			else if (class_exists('WP_Application_Passwords') && !WP_Application_Passwords::is_in_use()) {
				// Core skips validation entirely until an application password has been created.
				$this->event = $this->makeEvent('applicationPassword', '', $_SERVER['PHP_AUTH_USER']);
			}
		}
		$this->restObserved = false;
		$this->applicationError = null;
		$this->applicationFailure = null;
		return $response;
	}

	/** @return bool Whether an authentication mechanism was supplied or observed. */
	private function hasAuthenticationEvidence() {
		if ($this->applicationFailure || isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) || !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || isset($_SERVER['HTTP_X_WP_NONCE']) || isset($_REQUEST['_wpnonce'])) {
			return true;
		}
		foreach (array('AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE') as $name) {
			if (defined($name) && !empty($_COOKIE[constant($name)])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Bounds untrusted usernames without storing credential material.
	 * @param mixed $username Supplied account identifier.
	 * @return string Plain-text username, at most 255 bytes.
	 */
	private function boundedUsername($username) {
		return is_string($username) ? wp_check_invalid_utf8(substr(wp_strip_all_tags($username), 0, 255), true) : '';
	}

	/**
	 * Converts external errors into a fixed set of non-sensitive reason codes.
	 * @param string $mechanism Authentication mechanism.
	 * @param string $code External error code.
	 * @param string $username Available username.
	 * @return array Bounded Live Traffic authentication metadata.
	 */
	private function makeEvent($mechanism, $code, $username) {
		$reason = 'rejected';
		if (in_array($code, array('invalid_username', 'invalid_email', 'incorrect_password'), true)) {
			$reason = 'invalidCredentials';
		}
		else if (in_array($code, array('application_passwords_disabled', 'application_passwords_disabled_for_user'), true)) {
			$reason = 'applicationPasswordsDisabled';
		}
		else if ($code === 'rest_cookie_invalid_nonce') {
			$reason = 'invalidRestNonce';
		}
		$event = array('mechanism' => $mechanism, 'reason' => $reason);
		$username = $this->boundedUsername($username);
		if ($username !== '') { $event['username'] = $username; }
		return $event;
	}

	/**
	 * Attaches the event to the current hit without replacing other security events.
	 * @param wfRequestModel $request Current initialized request.
	 * @return void
	 */
	public function annotateRequest($request) {
		if (!$this->event) { return; }
		$data = wfRequestModel::unserializeActionData((string) $request->actionData);
		$data['authentication'] = $this->event;
		$encoded = wfRequestModel::serializeActionData($data);
		if ($encoded === null) { return; } // Never erase existing firewall evidence to fit an annotation.
		$request->actionData = $encoded;
		if (!$request->action || strpos($request->action, 'authFail:') === 0) {
			$request->action = 'authFail:' . $this->event['mechanism'];
		}
	}
}
