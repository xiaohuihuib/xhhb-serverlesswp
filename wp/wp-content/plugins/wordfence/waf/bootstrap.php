<?php

/*
	php_value auto_prepend_file ~/wp-content/plugins/wordfence/waf/bootstrap.php
*/

if (!defined('WFWAF_RUN_COMPLETE')) {

require_once dirname(__FILE__) . '/../vendor/wordfence/wf-waf/src/lib/health.php';
wfWAFRuntimeHealth::begin(realpath(dirname(__DIR__)), defined('WFWAF_LOG_PATH') ? WFWAF_LOG_PATH : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/wflogs/' : null));

// Keep WAF diagnostics out of the response while preserving the site's logging settings.
$wfWAFBootstrapDisplayErrors = function_exists('ini_set') ? @ini_set('display_errors', '0') : false;
try {

if (!defined('WFWAF_AUTO_PREPEND')) {
	define('WFWAF_AUTO_PREPEND', true);
}
if (!defined('WF_IS_WP_ENGINE')) {
	define('WF_IS_WP_ENGINE', isset($_SERVER['IS_WPE']));
}
if (!defined('WF_IS_FLYWHEEL')) {
	define('WF_IS_FLYWHEEL', isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Flywheel/') === 0);
}
if (!defined('WF_IS_PRESSABLE')) {
	define('WF_IS_PRESSABLE', (defined('IS_ATOMIC') && IS_ATOMIC) || (defined('IS_PRESSABLE') && IS_PRESSABLE));
}

require(dirname(__FILE__) . '/../lib/wfVersionSupport.php');
/**
 * @var string $wfPHPDeprecatingVersion
 * @var string $wfPHPMinimumVersion
 */

if (!defined('WF_PHP_UNSUPPORTED')) {
	define('WF_PHP_UNSUPPORTED', version_compare(PHP_VERSION, $wfPHPMinimumVersion, '<'));
}

if (WF_PHP_UNSUPPORTED) {
	return;
}



require_once(dirname(__FILE__) . '/wfWAFUserIPRange.php');
require_once(dirname(__FILE__) . '/wfWAFIPBlocksController.php');
require_once(dirname(__FILE__) . '/../vendor/wordfence/wf-waf/src/init.php');

class wfWAFWordPressRequest extends wfWAFRequest {
	
	/**
	 * @param wfWAFRequest|null $request
	 * @return wfWAFRequest
	 */
	public static function createFromGlobals($request = null) {
		if (version_compare(phpversion(), '5.3.0') >= 0) {
			$class = get_called_class();
			$request = new $class();
		} else {
			$request = new self();
		}
		return parent::createFromGlobals($request);
	}

	public function getIP() {
		static $theIP = null;
		if (isset($theIP)) {
			return $theIP;
		}
		$ips = array();
		$howGet = wfWAF::getInstance()->getStorageEngine()->getConfig('howGetIPs', null, 'synced');
		if ($howGet !== null && !is_string($howGet)) {
			throw new InvalidArgumentException('Invalid client IP configuration');
		}
		if ($howGet) {
			if (is_string($howGet) && is_array($_SERVER) && array_key_exists($howGet, $_SERVER)) {
				$ips[] = array($_SERVER[$howGet], $howGet);
			}
			
			if ($howGet != 'REMOTE_ADDR') {
				$ips[] = array((is_array($_SERVER) && array_key_exists('REMOTE_ADDR', $_SERVER)) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1', 'REMOTE_ADDR');
			}
		}
		else {
			$recommendedField = wfWAF::getInstance()->getStorageEngine()->getConfig('detectProxyRecommendation', null, 'synced');
			if ($recommendedField !== null && !is_string($recommendedField)) {
				throw new InvalidArgumentException('Invalid client IP recommendation');
			}
			if (!empty($recommendedField) && $recommendedField != 'UNKNOWN' && $recommendedField != 'DEFERRED') {
				if (isset($_SERVER[$recommendedField])) {
					$ips[] = array($_SERVER[$recommendedField], $recommendedField);
				}
			}
			
			$ips[] = array((is_array($_SERVER) && array_key_exists('REMOTE_ADDR', $_SERVER)) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1', 'REMOTE_ADDR');
			if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				$ips[] = array($_SERVER['HTTP_X_FORWARDED_FOR'], 'HTTP_X_FORWARDED_FOR');
			}
			if (isset($_SERVER['HTTP_X_REAL_IP'])) {
				$ips[] = array($_SERVER['HTTP_X_REAL_IP'], 'HTTP_X_REAL_IP');
			}
		}
		
		$cleanedIP = $this->_getCleanIPAndServerVar($ips);
		if (is_array($cleanedIP)) {
			list($ip, $variable) = $cleanedIP;
			$theIP = $ip;
			return $ip;
		}
		$theIP = $cleanedIP;
		return $cleanedIP;
	}
	
	/**
	 * Expects an array of items. The items are either IPs or IPs separated by comma, space or tab. Or an array of IP's.
	 * We then examine all IP's looking for a public IP and storing private IP's in an array. If we find no public IPs we return the first private addr we found.
	 *
	 * @param array $arr
	 * @return bool|mixed
	 */
	private function _getCleanIPAndServerVar($arr) {
		$privates = array(); //Store private addrs until end as last resort.
		foreach ($arr as $entry) {
			list($item, $var) = $entry;
			if (is_array($item)) {
				foreach ($item as $j) {
					// try verifying the IP is valid before stripping the port off
					if (!$this->_isValidIP($j)) {
						$j = preg_replace('/:\d+$/', '', $j); //Strip off port
					}
					if ($this->_isValidIP($j)) {
						if ($this->_isIPv6MappedIPv4($j)) {
							$j = wfWAFUtils::inet_ntop(wfWAFUtils::inet_pton($j));
						}
						
						if ($this->_isPrivateIP($j)) {
							$privates[] = array($j, $var);
						}
						else {
							return array($j, $var);
						}
					}
				}
				continue; //This was an array so we can skip to the next item
			}
			$skipToNext = false;
			$trustedProxyConfig = wfWAF::getInstance()->getStorageEngine()->getConfig('howGetIPs_trusted_proxies_unified', null, 'synced');
			$trustedProxies = $trustedProxyConfig === null ? array() : explode("\n", $trustedProxyConfig);
			foreach (array(',', ' ', "\t") as $char) {
				if (strpos($item, $char) !== false) {
					$sp = explode($char, $item);
					$sp = array_reverse($sp);
					foreach ($sp as $index => $j) {
						$j = trim($j, " \t\n\r\0\x0B\x0C");
						if (!$this->_isValidIP($j)) {
							$j = preg_replace('/:\d+$/', '', $j); //Strip off port
						}
						if ($this->_isValidIP($j)) {
							if ($this->_isIPv6MappedIPv4($j)) {
								$j = wfWAFUtils::inet_ntop(wfWAFUtils::inet_pton($j));
							}
							
							foreach ($trustedProxies as $proxy) {
								if (!empty($proxy)) {
									if (wfWAFUtils::subnetContainsIP($proxy, $j) && $index < count($sp) - 1) {
										continue 2;
									}
								}
							}
							
							if ($this->_isPrivateIP($j)) {
								$privates[] = array($j, $var);
							}
							else {
								return array($j, $var);
							}
						}
					}
					$skipToNext = true;
					break;
				}
			}
			if ($skipToNext){ continue; } //Skip to next item because this one had a comma, space or tab so was delimited and we didn't find anything.
			
			if (!$this->_isValidIP($item)) {
				$item = preg_replace('/:\d+$/', '', $item); //Strip off port
			}
			if ($this->_isValidIP($item)) {
				if ($this->_isIPv6MappedIPv4($item)) {
					$item = wfWAFUtils::inet_ntop(wfWAFUtils::inet_pton($item));
				}
				
				if ($this->_isPrivateIP($item)) {
					$privates[] = array($item, $var);
				}
				else {
					return array($item, $var);
				}
			}
		}
		if (sizeof($privates) > 0) {
			return $privates[0]; //Return the first private we found so that we respect the order the IP's were passed to this function.
		}
		return false;
	}
	
	/**
	 * @param string $ip
	 * @return bool
	 */
	private function _isValidIP($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP) !== false;
	}
	
	/**
	 * @param string $ip
	 * @return bool
	 */
	private function _isIPv6MappedIPv4($ip) {
		return preg_match('/^(?:\:(?:\:0{1,4}){0,4}\:|(?:0{1,4}\:){5})ffff\:\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/i', $ip) > 0;
	}
	
	/**
	 * @param string $addr Should be in dot or colon notation (127.0.0.1 or ::1)
	 * @return bool
	 */
	private function _isPrivateIP($ip) {
		// Run this through the preset list for IPv4 addresses.
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$wordfenceLib = realpath(dirname(__FILE__) . '/../lib');
			include($wordfenceLib . '/wfIPWhitelist.php'); // defines $wfIPWhitelist
			$private = $wfIPWhitelist['private'];
			
			foreach ($private as $a) {
				if (wfWAFUtils::subnetContainsIP($a, $ip)) {
					return true;
				}
			}
		}
		
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false
		&& filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
	}
}

class wfWAFWordPressObserver extends wfWAFBaseObserver {

	private $waf;

	public function __construct($waf){
		$this->waf=$waf;
	}

	/**
	 * Run independent optional checks without swallowing WAF decisions.
	 *
	 * @return void
	 */
	public function beforeRunRules() {
		$this->runProtectionStage('url-allowlist', 'checkAllowedURLs');
		$this->runProtectionStage('ip-allowlist', 'checkAllowedIPs');
		$this->runProtectionStage('plugin-blocks', 'checkPluginBlocks');
	}

	/**
	 * Isolate a feature while preserving allow/block control flow.
	 *
	 * @param string $component Component.
	 * @param string $method Local check.
	 * @return void
	 */
	private function runProtectionStage($component, $method) {
		try {
			if ($this->$method() !== false) {
				wfWAFRuntimeHealth::complete($component);
			}
		}
		catch (wfWAFRunException $e) {
			throw $e;
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure($component, 'check-failed', $e);
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure($component, 'check-failed', $e);
		}
	}

	/**
	 * Evaluate valid user URL allowlist entries.
	 *
	 * @return void
	 */
	private function checkAllowedURLs() {
		// Whitelisted URLs (in WAF config)
		$whitelistedURLs = wfWAF::getInstance()->getStorageEngine()->getConfig('whitelistedURLs', null, 'livewaf');
		if ($whitelistedURLs) {
			if (!is_array($whitelistedURLs)) {
				throw new InvalidArgumentException('Invalid URL allowlist');
			}
			$whitelistPattern = "";
			foreach ($whitelistedURLs as $whitelistedURL) {
				if (!is_string($whitelistedURL)) {
					wfWAFRuntimeHealth::failure('url-allowlist', 'invalid-entry');
					continue;
				}
				$whitelistPattern .= preg_replace('/\\\\\*/', '.*?', preg_quote($whitelistedURL, '/')) . '|';
			}
			if ($whitelistPattern === '') {
				return;
			}
			$whitelistPattern = '/^(?:' . wfWAFUtils::substr($whitelistPattern, 0, -1) . ')$/i';

			wfWAFRule::create(wfWAF::getInstance(), 0x8000000, 'rule', 'whitelist', 0, 'User Supplied Allowlisted URL', 'allow',
				new wfWAFRuleComparisonGroup(
					new wfWAFRuleComparison(wfWAF::getInstance(), 'match', $whitelistPattern, array(
						'request.uri',
					))
				)
			)->evaluate();
		}

	}

	/**
	 * Evaluate valid user IP allowlist entries.
	 *
	 * @return void
	 */
	private function checkAllowedIPs() {
		// Whitelisted IPs (Wordfence config)
		$whitelistedIPs = wfWAF::getInstance()->getStorageEngine()->getConfig('whitelistedIPs', null, 'synced');
		if ($whitelistedIPs) {
			if (!is_array($whitelistedIPs)) {
				if (!is_string($whitelistedIPs)) {
					throw new InvalidArgumentException('Invalid IP allowlist');
				}
				$whitelistedIPs = explode(',', $whitelistedIPs);
			}
			foreach ($whitelistedIPs as $whitelistedIP) {
				if (!is_string($whitelistedIP)) {
					wfWAFRuntimeHealth::failure('ip-allowlist', 'invalid-entry');
					continue;
				}
				$ipRange = new wfWAFUserIPRange($whitelistedIP);
				if ($ipRange->isIPInRange(wfWAF::getInstance()->getRequest()->getIP())) {
					throw new wfWAFAllowException('Wordfence allowlisted IP.');
				}
			}
		}
		
	}

	/**
	 * Capture a validated plugin blocking decision for use after rules.
	 *
	 * @return void
	 */
	private function checkPluginBlocks() {
		// Check plugin blocking
		if ($result = wfWAFWordPress::normalizeFinalAction(wfWAF::getInstance()->willPerformFinalAction(wfWAF::getInstance()->getRequest()))) {
			wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('finalAction' => $result)));
		}
	}
	
	/**
	 * Preserve pending plugin decisions even when blocklist work fails.
	 *
	 * @return void
	 */
	public function afterRunRules()
	{
		$this->runProtectionStage('blocklist', 'checkBlocklist');
		$reason = wfWAFWordPress::normalizeFinalAction(wfWAF::getInstance()->getRequest()->getMetadata('finalAction'));
		if ($reason) {
			$e = new wfWAFBlockException($reason['action']);
			$e->setRequest(wfWAF::getInstance()->getRequest());
			throw $e;
		}
	}

	/**
	 * Evaluate the optional real-time IP blocklist.
	 *
	 * @return bool Whether local blocklist health may recover.
	 */
	private function checkBlocklist() {
		$lookupSucceeded = false;
		//Blacklist
		if (!wfWAF::getInstance()->getStorageEngine()->getConfig('disableWAFBlacklistBlocking')) {
			$blockedPrefixes = wfWAF::getInstance()->getStorageEngine()->getConfig('blockedPrefixes', null, 'transient');
			if (is_string($blockedPrefixes) && $blockedPrefixes !== '' && wfWAF::getInstance()->getStorageEngine()->getConfig('isPaid', null, 'synced')) {
				$blockedPrefixes = base64_decode($blockedPrefixes, true);
				if ($this->_prefixListContainsIP($blockedPrefixes, wfWAF::getInstance()->getRequest()->getIP()) !== false) {
					$allowedCacheJSON = wfWAF::getInstance()->getStorageEngine()->getConfig('blacklistAllowedCache', '', 'transient');
					if (!is_string($allowedCacheJSON)) {
						throw new InvalidArgumentException('Invalid blocklist cache');
					}
					$allowedCache = json_decode($allowedCacheJSON, true);
					$validCache = is_array($allowedCache) || $allowedCacheJSON === '';
					if (!is_array($allowedCache)) {
						if ($allowedCacheJSON !== '') {
							wfWAFRuntimeHealth::failure('blocklist-cache', 'invalid-cache', null, false);
						}
						$allowedCache = array();
					}

					$cacheIssues = wfWAFRuntimeHealth::snapshot()['issues'];
					if ($validCache && (!isset($cacheIssues['blocklist-cache']) || $cacheIssues['blocklist-cache']['code'] === 'invalid-cache')) {
						wfWAFRuntimeHealth::complete('blocklist-cache');
					}
					
					$cacheTest = base64_encode(wfWAFUtils::inet_pton(wfWAF::getInstance()->getRequest()->getIP()));
					if (!in_array($cacheTest, $allowedCache)) {
						$guessSiteURL = sprintf('%s://%s/', wfWAF::getInstance()->getRequest()->getProtocol(), wfWAF::getInstance()->getRequest()->getHost());
						try {
							$request = new wfWAFHTTP();
							$response = wfWAFHTTP::get(WFWAF_API_URL_SEC . "?" . http_build_query(array(
									'action' => 'is_ip_blacklisted',
									'ip'	 => wfWAF::getInstance()->getRequest()->getIP(),
									'k'      => wfWAF::getInstance()->getStorageEngine()->getConfig('apiKey', null, 'synced'),
									's'      => wfWAF::getInstance()->getStorageEngine()->getConfig('siteURL', null, 'synced') ? wfWAF::getInstance()->getStorageEngine()->getConfig('siteURL', null, 'synced') : $guessSiteURL,
									'h'      => wfWAF::getInstance()->getStorageEngine()->getConfig('homeURL', null, 'synced') ? wfWAF::getInstance()->getStorageEngine()->getConfig('homeURL', null, 'synced') : $guessSiteURL,
									't'		 => microtime(true),
									'lang'   => wfWAF::getInstance()->getStorageEngine()->getConfig('WPLANG', null, 'synced'),
								), '', '&'), $request);
							
							if ($response instanceof wfWAFHTTPResponse && $response->getBody()) {
								$jsonData = wfWAFUtils::json_decode($response->getBody(), true);
								if (is_array($jsonData) && isset($jsonData['data']) && is_string($jsonData['data'])) {
									$lookupSucceeded = (int) $response->getStatusCode() === 200;
									if ($lookupSucceeded) {
										wfWAFRuntimeHealth::complete('blocklist-lookup');
										// Also recover lookup failures recorded under the original shared component.
										wfWAFRuntimeHealth::complete('blocklist');
									}
									if (preg_match('/^block:(\d+)$/i', $jsonData['data'], $matches)) {
										try {
											$storage = wfWAF::getInstance()->getStorageEngine();
											$ip = wfWAF::getInstance()->getRequest()->getIP();
											if ($storage->blockIP((int)$matches[1] + time(), $ip, wfWAFStorageInterface::IP_BLOCKS_BLACKLIST) === false) {
												throw new RuntimeException('Block cache write failed');
											}
											if ($storage instanceof wfWAFStorageMySQL && $storage->saveConfig() === false) {
												throw new RuntimeException('Block cache persistence failed');
											}
											if (!$storage->isIPBlocked($ip)) {
												throw new RuntimeException('Block cache verification failed');
											}
											wfWAFRuntimeHealth::complete('blocklist-cache');
										}
										catch (Exception $failure) {
											wfWAFRuntimeHealth::failure('blocklist-cache', 'save-failed', $failure, false);
										}
										catch (Throwable $failure) {
											wfWAFRuntimeHealth::failure('blocklist-cache', 'save-failed', $failure, false);
										}
										$e = new wfWAFBlockException();
										$e->setFailedRules(array('blocked'));
										$e->setRequest(wfWAF::getInstance()->getRequest());
										throw $e;
									}
									else { //Allowed, cache until the next prefix list refresh
										$allowedCache[] = $cacheTest;
										wfWAF::getInstance()->getStorageEngine()->setConfig('blacklistAllowedCache', json_encode($allowedCache), 'transient');
									}
								}
							}
						} catch (wfWAFHTTPTransportException $e) {
							wfWAFRuntimeHealth::failure('blocklist-lookup', 'lookup-failed', $e);
						}
					}
				}
			}
		}
		else {
			return false;
		}
		$issues = wfWAFRuntimeHealth::snapshot()['issues'];
		return $lookupSucceeded || !isset($issues['blocklist']) || $issues['blocklist']['code'] !== 'lookup-failed';
	}
	
	/**
	 * Looks up an IP in a structurally valid binary prefix list.
	 * @param mixed $prefixList Decoded prefix data.
	 * @param string $ip IP address to check.
	 * @return int|false Matching record index, or false for invalid data or no match.
	 */
	private function _prefixListContainsIP($prefixList, $ip) {
		if (!is_string($prefixList) || $prefixList === '') {
			wfWAFRuntimeHealth::failure('blocklist', 'invalid-prefix-list');
			return false;
		}
		$size = ord(wfWAFUtils::substr($prefixList, 0, 1));
		$payloadLength = wfWAFUtils::strlen($prefixList) - 1;
		if ($size < 1 || $size > 32 || $payloadLength % $size !== 0 || $payloadLength === 0) {
			wfWAFRuntimeHealth::failure('blocklist', 'invalid-prefix-list');
			return false;
		}
		
		$sha256 = hash('sha256', wfWAFUtils::inet_pton($ip), true);
		$p = wfWAFUtils::substr($sha256, 0, $size);
		
		$count = (int) ($payloadLength / $size);
		$low = 0;
		$high = $count - 1;
		
		while ($low <= $high) {
			$mid = (int) (($high + $low) / 2);
			$val = wfWAFUtils::substr($prefixList, 1 + $mid * $size, $size);
			$cmp = strcmp($val, $p);
			if ($cmp < 0) {
				$low = $mid + 1;
			}
			else if ($cmp > 0) {
				$high = $mid - 1;
			}
			else {
				return $mid;
			}
		}
		
		return false;
	}
}

/**
 *
 */
class wfWAFWordPress extends wfWAF {
	private $learningMode = null;

	/**
	 * Validate and snapshot policy before evaluating the request.
	 *
	 * @return void
	 */
	public function run() {
		try {
			$status = $this->getStorageEngine()->getConfig('wafStatus');
			if (WFWAF_ENABLED && !in_array($status, array('enabled', 'learning-mode', 'disabled'), true)) {
				throw new RuntimeException('Invalid WAF policy');
			}
			if ($status === 'learning-mode') {
				$grace = $this->getStorageEngine()->getConfig('learningModeGracePeriod', 0);
				if (!is_numeric($grace)) {
					throw new RuntimeException('Invalid learning period');
				}
			}
			foreach (array('wafDisabled', 'learningModeGracePeriodEnabled') as $flag) {
				if (!in_array($this->getStorageEngine()->getConfig($flag, false), array(null, false, true, 0, 1, '0', '1', ''), true)) {
					throw new RuntimeException('Invalid WAF policy flag');
				}
			}
			$this->learningMode = parent::isInLearningMode();
			wfWAFRuntimeHealth::complete('policy');
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure('policy', 'policy-unavailable', $e);
			return;
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure('policy', 'policy-unavailable', $e);
			return;
		}
		parent::run();
	}

	/**
	 * Reuse the policy that was validated before evaluation.
	 *
	 * @return bool
	 */
	public function isInLearningMode() {
		return $this->learningMode === null ? parent::isInLearningMode() : $this->learningMode;
	}

	/**
	 * Distinguish learned rule matches from enforcing IP and plugin blocks.
	 *
	 * @param wfWAFRunException $exception Decision.
	 * @return bool
	 */
	protected function shouldEnforceBlock($exception) {
		if (!$this->isInLearningMode()) {
			return true;
		}
		$rules = $exception->getFailedRules();
		return (is_array($rules) && in_array('blocked', $rules, true)) || (bool) $exception->getRequest()->getMetadata('finalAction');
	}

	/**
	 * Normalize supported controller contracts without dropping unknown context fields.
	 *
	 * @param mixed $action Controller result.
	 * @return array|false
	 */
	public static function normalizeFinalAction($action) {
		if ($action === false || $action === null || $action === '') {
			return false;
		}
		if ($action === true) {
			return array('action' => 'Access denied');
		}
		if (is_string($action)) {
			return array('action' => $action);
		}
		if (is_array($action) && isset($action['action']) && is_string($action['action'])) {
			if (isset($action['id']) && !is_numeric($action['id'])) {
				unset($action['id']);
				wfWAFRuntimeHealth::failure('plugin-blocks', 'invalid-decision');
			}
			if (isset($action['lockout']) && !is_scalar($action['lockout'])) {
				unset($action['lockout']);
				wfWAFRuntimeHealth::failure('plugin-blocks', 'invalid-decision');
			}
			return $action;
		}
		wfWAFRuntimeHealth::failure('plugin-blocks', 'invalid-decision');
		return false;
	}

	/**
	 * Terminate an established block even with a legacy parent engine.
	 *
	 * @return void
	 */
	private function emergencyWordPressBlockResponse() {
		wfWAFRuntimeHealth::outcome('blocked');
		try {
			@header('HTTP/1.0 403 Forbidden');
			@header('Content-Type: text/plain; charset=UTF-8');
			@header('Cache-Control: no-store');
		}
		catch (Exception $e) {
		}
		catch (Throwable $e) {
		}
		exit('Forbidden');
	}

	/** @var wfWAFRunException */
	private $learningModeAttackException;

	/**
	 * @param wfWAFBlockException $e
	 * @param int $httpCode
	 */
	public function blockAction($e, $httpCode = 403, $redirect = false, $template = null) {
		$enforce = $this->learningMode !== true;
		try {
			$enforce = $this->shouldEnforceBlock($e);
			$this->performWordPressBlockAction($e, $httpCode, $redirect, $template);
			wfWAFRuntimeHealth::complete('block-handler');
			if (!$enforce) {
				wfWAFRuntimeHealth::outcome('learning');
			}
		}
		catch (wfWAFAllowException $allowed) {
			wfWAFRuntimeHealth::outcome('allowed');
			return;
		}
		catch (Exception $failure) {
			wfWAFRuntimeHealth::failure('block-handler', 'handler-failed', $failure, false);
			if ($enforce) {
				$this->emergencyWordPressBlockResponse();
			}
			wfWAFRuntimeHealth::outcome('learning');
		}
		catch (Throwable $failure) {
			wfWAFRuntimeHealth::failure('block-handler', 'handler-failed', $failure, false);
			if ($enforce) {
				$this->emergencyWordPressBlockResponse();
			}
			wfWAFRuntimeHealth::outcome('learning');
		}
	}

	/**
	 * Apply WordPress response customization inside the guarded action.
	 *
	 * @param wfWAFRunException $e Decision.
	 * @param int $httpCode Status.
	 * @param string|false $redirect Redirect.
	 * @param string|null $template Template.
	 * @return void
	 */
	private function performWordPressBlockAction($e, $httpCode = 403, $redirect = false, $template = null) {
		$failedRules = $e->getFailedRules();
		if (!is_array($failedRules)) {
			$failedRules = array();
		}
		
		if ($this->isInLearningMode() && !$e->getRequest()->getMetadata('finalAction') && !in_array('blocked', $failedRules)) {
			register_shutdown_function(array(
				$this, 'whitelistFailedRulesIfNot404',
			));
			$this->getStorageEngine()->logAttack($e->getFailedRules(), $e->getParamKey(), $e->getParamValue(), $e->getRequest());
			$this->setLearningModeAttackException($e);
		} else {
			wfWAFRuntimeHealth::outcome('blocked');
			if (empty($failedRules)) {
				$finalAction = $e->getRequest()->getMetadata('finalAction');
				if (is_array($finalAction)) {
					$isLockedOut = isset($finalAction['lockout']) && $finalAction['lockout'];
					$finalAction = $finalAction['action'];
					if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY_REDIR)) {
						$redirect = wfWAFIPBlocksController::currentController()->countryRedirURL();
					}
					else if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY_BYPASS_REDIR)) {
						$redirect = wfWAFIPBlocksController::currentController()->countryBypassRedirURL();
					}
					else if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_UAREFIPRANGE)) {
						wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('503Reason' => 'Advanced blocking in effect.', '503Time' => 3600)));
						$httpCode = 503;
					}
					else if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY)) {
						wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('503Reason' => 'Access from your area has been temporarily limited for security reasons.', '503Time' => 3600)));
						$httpCode = 503;
					}
					else if (is_string($finalAction) && strlen($finalAction) > 0) {
						wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('503Reason' => $finalAction, '503Time' => 3600)));
						$httpCode = 503;
						
						if ($isLockedOut) {
							wfWAFRuntimeHealth::complete('block-handler');
							wfWAFRuntimeHealth::outcome('blocked');
							parent::blockAction($e, $httpCode, $redirect, '503-lockout'); //exits
						}
					}
				}
			}
			else if (array_search('blocked', $failedRules) !== false) {
				wfWAFRuntimeHealth::complete('block-handler');
				wfWAFRuntimeHealth::outcome('blocked');
				parent::blockAction($e, $httpCode, $redirect, '403-blacklist'); //exits
			}
			
			wfWAFRuntimeHealth::complete('block-handler');
			wfWAFRuntimeHealth::outcome('blocked');
			parent::blockAction($e, $httpCode, $redirect, $template);
		}
	}

	/**
	 * @param wfWAFBlockXSSException $e
	 * @param int $httpCode
	 */
	public function blockXSSAction($e, $httpCode = 403, $redirect = false) {
		$enforce = $this->learningMode !== true;
		try {
			$enforce = $this->shouldEnforceBlock($e);
			$this->performWordPressBlockXSSAction($e, $httpCode, $redirect);
			wfWAFRuntimeHealth::complete('block-handler');
			if (!$enforce) {
				wfWAFRuntimeHealth::outcome('learning');
			}
		}
		catch (wfWAFAllowException $allowed) {
			wfWAFRuntimeHealth::outcome('allowed');
			return;
		}
		catch (Exception $failure) {
			wfWAFRuntimeHealth::failure('block-handler', 'handler-failed', $failure, false);
			if ($enforce) {
				$this->emergencyWordPressBlockResponse();
			}
			wfWAFRuntimeHealth::outcome('learning');
		}
		catch (Throwable $failure) {
			wfWAFRuntimeHealth::failure('block-handler', 'handler-failed', $failure, false);
			if ($enforce) {
				$this->emergencyWordPressBlockResponse();
			}
			wfWAFRuntimeHealth::outcome('learning');
		}
	}

	/**
	 * Apply WordPress response customization inside the guarded action.
	 *
	 * @param wfWAFRunException $e Decision.
	 * @param int $httpCode Status.
	 * @param string|false $redirect Redirect.
	 * @return void
	 */
	private function performWordPressBlockXSSAction($e, $httpCode = 403, $redirect = false) {
		if ($this->isInLearningMode() && !$e->getRequest()->getMetadata('finalAction')) {
			register_shutdown_function(array(
				$this, 'whitelistFailedRulesIfNot404',
			));
			$this->getStorageEngine()->logAttack($e->getFailedRules(), $e->getParamKey(), $e->getParamValue(), $e->getRequest());
			$this->setLearningModeAttackException($e);
		} else {
			$failedRules = $e->getFailedRules();
			if (empty($failedRules)) {
				$finalAction = $e->getRequest()->getMetadata('finalAction');
				if (is_array($finalAction)) {
					$finalAction = $finalAction['action'];
					if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY_REDIR)) {
						$redirect = wfWAFIPBlocksController::currentController()->countryRedirURL();
					}
					else if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY_BYPASS_REDIR)) {
						$redirect = wfWAFIPBlocksController::currentController()->countryBypassRedirURL();
					}
					else if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_UAREFIPRANGE)) {
						wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('503Reason' => 'Advanced blocking in effect.', '503Time' => 3600)));
						$httpCode = 503;
					}
					else if (wfWAFBlockI18n::matchesBlockKey($finalAction, wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY)) {
						wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('503Reason' => 'Access from your area has been temporarily limited for security reasons.', '503Time' => 3600)));
						$httpCode = 503;
					}
					else if (is_string($finalAction) && strlen($finalAction) > 0) {
						wfWAF::getInstance()->getRequest()->setMetadata(array_merge(wfWAF::getInstance()->getRequest()->getMetadata(), array('503Reason' => $finalAction, '503Time' => 3600)));
						$httpCode = 503;
					}
				}
			}
			
			wfWAFRuntimeHealth::complete('block-handler');
			wfWAFRuntimeHealth::outcome('blocked');
			parent::blockXSSAction($e, $httpCode, $redirect);
		}
	}

	private function isCli() {
		return (php_sapi_name()==='cli') || !array_key_exists('REQUEST_METHOD', $_SERVER);
	}

	/**
	 *
	 */
	public function runCron() {
		if($this->isCli()){
			return;
		}
		// WordPress sends attack data separately in veryFirstAction().
		$storage = $this->getStorageEngine();
		$cron = $storage->getConfig('cron', null, 'livewaf');
		if ($cron !== null && !is_array($cron)) {
			wfWAFRuntimeHealth::reportMaintenanceFailure('cron-schedule');
			return;
		}
		$cron = (array) $cron;
		$run = array();
		$updated = false;
			$cronDeduplication = array();
			foreach ($cron as $index => $event) {
			try {
				if (!($event instanceof wfWAFCronEvent)) {
					unset($cron[$index]);
					$updated = true;
					wfWAFRuntimeHealth::reportMaintenanceFailure('cron-schedule');
					continue;
				}
					$event->setWaf($this);
					if ($event->isInPast()) {
					$run[] = $event;
						$newEvent = $event->reschedule();
						$className = is_object($newEvent) ? get_class($newEvent) : null;
					if ($newEvent instanceof wfWAFCronEvent && $newEvent !== $event && !in_array($className, $cronDeduplication)) {
							$cron[$index] = $newEvent;
							$cronDeduplication[] = $className;
						} else {
							unset($cron[$index]);
					}
							$updated = true;
					}
					else {
						$className = get_class($event);
						if (in_array($className, $cronDeduplication)) {
							unset($cron[$index]);
							$updated = true;
						}
						else {
							$cronDeduplication[] = $className;
						}
					}
				}
			catch (Exception $e) {
				wfWAFRuntimeHealth::reportMaintenanceFailure('cron-schedule');
			}
			catch (Throwable $e) {
				wfWAFRuntimeHealth::reportMaintenanceFailure('cron-schedule');
				}
			}
		try {
		$storage->setConfig('cron', $cron, 'livewaf');
			if ($updated && method_exists($storage, 'saveConfig') && $storage->saveConfig('livewaf') === false) {
				throw new RuntimeException('Cron persistence failed');
		}
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::reportMaintenanceFailure('cron-storage');
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::reportMaintenanceFailure('cron-storage');
		}
		foreach ($run as $event) {
			try {
				if ($event->fire() === false) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('cron-event');
				}
			}
			catch (Exception $e) {
				wfWAFRuntimeHealth::reportMaintenanceFailure('cron-event');
			}
			catch (Throwable $e) {
				wfWAFRuntimeHealth::reportMaintenanceFailure('cron-event');
			}
		}
	}

	/**
	 *
	 */
	public function whitelistFailedRulesIfNot404() {
		/** @var WP_Query $wp_query */
		global $wp_query;
		if (defined('ABSPATH') &&
			isset($wp_query) && class_exists('WP_Query') && $wp_query instanceof WP_Query &&
			method_exists($wp_query, 'is_404') && $wp_query->is_404() &&
			function_exists('is_admin') && !is_admin()) {
			return;
		}
		$this->whitelistFailedRules();
	}

	/**
	 * @param $ip
	 * @return mixed
	 */
	public function isIPBlocked($ip) {
		return parent::isIPBlocked($ip);
	}
	
	/**
	 * @param wfWAFRequest $request
	 * @return bool|string false if it should not be blocked, otherwise true or a reason for blocking 
	 */
	public function willPerformFinalAction($request) {
		try {
			$disableWAFIPBlocking = $this->getStorageEngine()->getConfig('disableWAFIPBlocking', null, 'synced');
			$advancedBlockingEnabled = $this->getStorageEngine()->getConfig('advancedBlockingEnabled', null, 'synced');
			foreach (array($disableWAFIPBlocking, $advancedBlockingEnabled) as $flag) {
				if ($flag !== null && !is_scalar($flag)) {
					throw new UnexpectedValueException('Invalid plugin blocking policy');
				}
			}
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure('plugin-blocks', 'configuration-unavailable', $e);
			return false;
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure('plugin-blocks', 'configuration-unavailable', $e);
			return false;
		}
		
		if ($disableWAFIPBlocking || !$advancedBlockingEnabled) {
			return false;
		}
		
		return wfWAFIPBlocksController::currentController()->shouldBlockRequest($request);
	}
	
	public function uninstall() {
		parent::uninstall();
		@unlink(rtrim(WFWAF_LOG_PATH, '/') . '/.htaccess');
		@unlink(rtrim(WFWAF_LOG_PATH, '/') . '/template.php');
		@unlink(rtrim(WFWAF_LOG_PATH, '/') . '/geoip.mmdb');
		
		self::_recursivelyRemoveWflogs(''); //Removes any remaining files and the directory itself
	}
	
	/**
	 * Removes a path within wflogs, recursing as necessary.
	 *
	 * @param string $file
	 * @param array $processedDirs
	 * @return array The list of removed files/folders.
	 */
	private static function _recursivelyRemoveWflogs($file, $processedDirs = array()) {
		if (preg_match('~(?:^|/|\\\\)\.\.(?:/|\\\\|$)~', $file)) {
			return array();
		}
		
		if (stripos(WFWAF_LOG_PATH, 'wflogs') === false) { //Sanity check -- if not in a wflogs folder, user will have to do removal manually
			return array();
		}
		
		$path = rtrim(WFWAF_LOG_PATH, '/') . '/' . $file;
		if (is_link($path)) {
			if (@unlink($path)) {
				return array($file);
			}
			return array();
		}
		
		if (is_dir($path)) {
			$real = realpath($file);
			if (in_array($real, $processedDirs)) {
				return array();
			}
			$processedDirs[] = $real;
			
			$count = 0;
			$dir = opendir($path);
			if ($dir) {
				$contents = array();
				while ($sub = readdir($dir)) {
					if ($sub == '.' || $sub == '..') { continue; }
					$contents[] = $sub;
				}
				closedir($dir);
				
				$filesRemoved = array();
				foreach ($contents as $f) {
					$removed = self::_recursivelyRemoveWflogs($file . '/' . $f, $processedDirs);
					$filesRemoved = array($filesRemoved, $removed);
				}
			}
			
			if (@rmdir($path)) {
				$filesRemoved[] = $file;
			}
			return $filesRemoved;
		}
		
		if (@unlink($path)) {
			return array($file);
		}
		return array();
	}
	
	public function fileList() {
		$fileList = parent::fileList();
		$fileList[] = rtrim(WFWAF_LOG_PATH, '/') . '/.htaccess';
		$fileList[] = rtrim(WFWAF_LOG_PATH, '/') . '/template.php';
		$fileList[] = rtrim(WFWAF_LOG_PATH, '/') . '/geoip.mmdb';
		return $fileList;
	}

	/**
	 * @return wfWAFRunException
	 */
	public function getLearningModeAttackException() {
		return $this->learningModeAttackException;
	}

	/**
	 * @param wfWAFRunException $learningModeAttackException
	 */
	public function setLearningModeAttackException($learningModeAttackException) {
		$this->learningModeAttackException = $learningModeAttackException;
	}
	
	public static function permissions() {
		if (defined('WFWAF_LOG_FILE_MODE')) {
			return WFWAF_LOG_FILE_MODE;
		}
		
		if (class_exists('wfWAFStorageFile') && method_exists('wfWAFStorageFile', 'permissions')) {
			return wfWAFStorageFile::permissions();
		}
		
		static $_cachedPermissions = null;
		if ($_cachedPermissions === null) {
			if (defined('WFWAF_LOG_PATH')) {
				$template = rtrim(WFWAF_LOG_PATH . '/') . '/template.php';
				if (file_exists($template)) {
					$stat = @stat($template);
					if ($stat !== false) {
						$mode = $stat[2];
						$updatedMode = 0600;
						if (($mode & 0020) == 0020) {
							$updatedMode = $updatedMode | 0060;
						}
						$_cachedPermissions = $updatedMode;
						return $updatedMode;
					}
				}
			}
			return 0660;
		}
		return $_cachedPermissions;
	}
	
	public static function writeHtaccess() {
		@file_put_contents(rtrim(WFWAF_LOG_PATH, '/') . '/.htaccess', <<<APACHE
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>
APACHE
		);
		@chmod(rtrim(WFWAF_LOG_PATH, '/') . '/.htaccess', (wfWAFWordPress::permissions() | 0444));
	}

	public function getGlobal($global) {
		if (wfWAFUtils::strpos($global, '.') === false) {
			return null;
		}
		list($prefix, $_global) = explode('.', $global);
		switch ($prefix) {
			case 'wordpress':
				if ($_global === 'core') {
					return $this->getStorageEngine()->getConfig('wordpressVersion', null, 'synced');
				} else if ($_global === 'plugins') {
					return $this->getStorageEngine()->getConfig('wordpressPluginVersions', null, 'synced');
				} else if ($_global === 'themes') {
					return $this->getStorageEngine()->getConfig('wordpressThemeVersions', null, 'synced');
				}
				break;
		}
		return parent::getGlobal($global);
	}
}

class wfWAFWordPressStorageMySQL extends wfWAFStorageMySQL {

	public function getSerializedParams() {
		$params = parent::getSerializedParams();
		$params[] = 'wordpressPluginVersions';
		$params[] = 'wordpressThemeVersions';
		return $params;
	}

	public function getAutoloadParams() {
		$params = parent::getAutoloadParams();
		$params['synced'][] = 'wordpressVersion';
		$params['synced'][] = 'wordpressPluginVersions';
		$params['synced'][] = 'wordpressThemeVersions';
		return $params;
	}
}

class wfWAFWordPressI18n implements wfWAFI18nEngine {

	protected $translations;

	/** @var wfWAFStorageInterface */
	private $storageEngine;
	/**
	 * @var wfMO
	 */
	private $mo;

	/**
	 * @param wfWAFStorageInterface $storageEngine
	 */
	public function __construct($storageEngine) {
		$this->storageEngine = $storageEngine;
		try {
		$this->loadTranslations();
			wfWAFRuntimeHealth::complete('translations');
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure('translations', 'catalog-unavailable', $e, false);
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure('translations', 'catalog-unavailable', $e, false);
		}
	}

	/**
	 * @param string $text
	 * @return string
	 */
	public function __($text) {
		if (!$this->storageEngine->getConfig('wordfenceI18n', true, 'synced')) {
			return $text;
		}

		if ($this->mo) {
			$translated = $this->mo->translate($text);
			if ($translated) {
				return $translated;
			}
		}

		return $text;
	}

	private function getPotentialDirectories() {
		return array(
			dirname(__FILE__) . "/../languages/",
			dirname(WFWAF_LOG_PATH) . "/languages/plugins/"
		);
	}

	protected function loadTranslations() {
		require_once dirname(__FILE__) . '/pomo/mo.php';

		$currentLocale = $this->storageEngine->getConfig('WPLANG', '', 'synced');
		// WordPress returns false when the site language option is unset.
		if ($currentLocale === false) {
			$currentLocale = '';
		}
		if (!is_string($currentLocale)) {
			throw new InvalidArgumentException('Invalid locale');
		}
		if (!preg_match("/^[a-zA-Z_]+$/", $currentLocale))
			return false;
		$filename = "wordfence-{$currentLocale}.mo";

		foreach ($this->getPotentialDirectories() as $directory) {
			$path = "{$directory}/{$filename}";
			if (file_exists($path)) {
				$this->mo = new wfMO();
				return $this->mo->import_from_file($path);
			}
		}
		return false;
	}
}

try {

if (!defined('WFWAF_LOG_PATH')) {
	if (!defined('WP_CONTENT_DIR')) { //Loading before WordPress
		exit();
	}
	define('WFWAF_LOG_PATH', WP_CONTENT_DIR . '/wflogs/');
}
if (!is_dir(WFWAF_LOG_PATH)) {
	@mkdir(WFWAF_LOG_PATH, (wfWAFWordPress::permissions() | 0755));
	@chmod(WFWAF_LOG_PATH, (wfWAFWordPress::permissions() | 0755));
	wfWAFWordPress::writeHtaccess();
}


try {
	if (!defined('WFWAF_STORAGE_ENGINE') && isset($_SERVER['WFWAF_STORAGE_ENGINE'])) {
		define('WFWAF_STORAGE_ENGINE', $_SERVER['WFWAF_STORAGE_ENGINE']);
	}
	else if (!defined('WFWAF_STORAGE_ENGINE') && (WF_IS_WP_ENGINE || WF_IS_FLYWHEEL)) {
		define('WFWAF_STORAGE_ENGINE', 'mysqli');
	}

	$specifiedStorageEngine = defined('WFWAF_STORAGE_ENGINE');
	$fallbackStorageEngine = false;
	if ($specifiedStorageEngine) {
		switch (WFWAF_STORAGE_ENGINE) {
			case 'mysqli':
				$wfWAFDBCredentials = array();
				$sslOptions = array();
				$overrideConstants = array(
					'wfWAFDBCredentials' => array(
						'WFWAF_DB_NAME' => 'database',
						'WFWAF_DB_USER' => 'user',
						'WFWAF_DB_PASSWORD' => 'pass',
						'WFWAF_DB_HOST' => 'host',
						'WFWAF_DB_CHARSET' => 'charset',
						'WFWAF_DB_COLLATE' => 'collation',
						'WFWAF_MYSQL_CLIENT_FLAGS' => 'flags',
						'WFWAF_TABLE_PREFIX' => 'tablePrefix'
					),
					'sslOptions' => array(
						'WFWAF_DB_SSL_KEY' => 'key',
						'WFWAF_DB_SSL_CERTIFICATE' => 'certificate',
						'WFWAF_DB_SSL_CA_CERTIFICATE' => 'ca_certificate',
						'WFWAF_DB_SSL_CA_PATH' => 'ca_path',
						'WFWAF_DB_SSL_CIPHER_ALGOS' => 'cipher_algos'
					)
				);
				foreach ($overrideConstants as $variable => $constants) {
					foreach ($constants as $constant => $key) {
						if (defined($constant)) {
							${$variable}[$key] = constant($constant);
						}
					}
				}

				// Find the wp-config.php
				if (is_dir(dirname(WFWAF_LOG_PATH))) {
					if (file_exists(dirname(WFWAF_LOG_PATH) . '/../wp-config.php')) {
						wfWAFUtils::extractCredentialsWPConfig(dirname(WFWAF_LOG_PATH) . '/../wp-config.php', $wfWAFDBCredentials);
					} else if (file_exists(dirname(WFWAF_LOG_PATH) . '/../../wp-config.php')) {
						wfWAFUtils::extractCredentialsWPConfig(dirname(WFWAF_LOG_PATH) . '/../../wp-config.php', $wfWAFDBCredentials);
					}
				} else if (!empty($_SERVER['DOCUMENT_ROOT'])) {
					if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php')) {
						wfWAFUtils::extractCredentialsWPConfig($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php', $wfWAFDBCredentials);
					} else if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/../wp-config.php')) {
						wfWAFUtils::extractCredentialsWPConfig($_SERVER['DOCUMENT_ROOT'] . '/../wp-config.php', $wfWAFDBCredentials);
					}
				} else {
					$wfWAFDBCredentials = false;
				}

				if (!empty($wfWAFDBCredentials)) {
					$wfWAFStorageEngine = new wfWAFWordPressStorageMySQL(new wfWAFStorageEngineMySQLi(), $wfWAFDBCredentials['tablePrefix'], wfShutdownRegistry::getDefaultInstance());
					$wfWAFStorageEngine->getDb()->connect(
						$wfWAFDBCredentials['user'],
						$wfWAFDBCredentials['pass'],
						$wfWAFDBCredentials['database'],
						!empty($wfWAFDBCredentials['ipv6']) ? '[' . $wfWAFDBCredentials['host'] . ']' : $wfWAFDBCredentials['host'],
						!empty($wfWAFDBCredentials['port']) ? $wfWAFDBCredentials['port'] : null,
						!empty($wfWAFDBCredentials['socket']) ? $wfWAFDBCredentials['socket'] : null,
						array_key_exists('flags', $wfWAFDBCredentials) ? $wfWAFDBCredentials['flags'] : 0,
						$sslOptions
					);
					if (array_key_exists('charset', $wfWAFDBCredentials)) {
						$wfWAFStorageEngine->getDb()
							->setCharset($wfWAFDBCredentials['charset'],
								!empty($wfWAFDBCredentials['collation']) ? $wfWAFDBCredentials['collation'] : '');
					}
					if (defined('ABSPATH')) {
						$tableExists = false;
						$optionName = 'wordfence_installed'; //Also exists in wfConfig.php
						if (is_multisite() && function_exists('get_network_option')) {
							$tableExists = get_network_option(null, $optionName, null);
						}
						else {
							$tableExists = get_option($optionName, null);
						}
						
						$wfWAFStorageEngine->installing = !$tableExists;
						$wfWAFStorageEngine->getDb()->installing = $wfWAFStorageEngine->installing;
					}

				} else {
					unset($wfWAFDBCredentials);
				}

				break;
		}
	}

	if (empty($wfWAFStorageEngine)) {
		$wfWAFStorageEngine = new wfWAFStorageFile(
			WFWAF_LOG_PATH . 'attack-data.php',
			WFWAF_LOG_PATH . 'ips.php',
			WFWAF_LOG_PATH . 'config.php',
			WFWAF_LOG_PATH . 'rules.php',
			WFWAF_LOG_PATH . 'wafRules.rules'
		);
		if ($specifiedStorageEngine)
			$fallbackStorageEngine = true;
	}

	wfWAF::setSharedStorageEngine($wfWAFStorageEngine, $fallbackStorageEngine);
	wfWAF::setInstance(new wfWAFWordPress(wfWAFWordPressRequest::createFromGlobals(), wfWAF::getSharedStorageEngine()));
	wfWAF::getInstance()->getEventBus()->attach(new wfWAFWordPressObserver(wfWAF::getInstance()));

	if ($wfWAFStorageEngine instanceof wfWAFStorageFile) {
		$rulesFiles = array(
			WFWAF_LOG_PATH . 'rules.php',
			// WFWAF_PATH . 'rules.php',
		);
		foreach ($rulesFiles as $rulesFile) {
			if (!file_exists($rulesFile) && !wfWAF::getInstance()->isReadOnly()) {
				@touch($rulesFile);
			}
			@chmod($rulesFile, (wfWAFWordPress::permissions() | 0444));
			if (is_writable($rulesFile)) {
				wfWAF::getInstance()->setCompiledRulesFile($rulesFile);
				break;
			}
		}
	} else if ($wfWAFStorageEngine instanceof wfWAFStorageMySQL) {
		$wfWAFStorageEngine->runMigrations();
		$wfWAFStorageEngine->setDefaults();
	}

	if (!wfWAF::getInstance()->isReadOnly()) {
		if (wfWAF::getInstance()->getStorageEngine()->needsInitialRules()) {
			try {
				if (wfWAF::getInstance()->getStorageEngine()->getConfig('apiKey', null, 'synced') !== null &&
					wfWAF::getInstance()->getStorageEngine()->getConfig('createInitialRulesDelay', null, 'transient') < time()
				) {
					$event = new wfWAFCronFetchRulesEvent(time() - 60);
					$event->setWaf(wfWAF::getInstance());
					$event->fire();
					wfWAF::getInstance()->getStorageEngine()->setConfig('createInitialRulesDelay', time() + (5 * 60), 'transient');
				}
			} catch (wfWAFBuildRulesException $e) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('rule-refresh');
			} catch (Exception $e) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('rule-refresh');
				}
				catch (Throwable $e) {
					wfWAFRuntimeHealth::reportMaintenanceFailure('rule-refresh');
			}
		}
	}

	if (WFWAF_DEBUG && file_exists(wfWAF::getInstance()->getStorageEngine()->getRulesDSLCacheFile())) {
		try {
			wfWAF::getInstance()->updateRuleSet(file_get_contents(wfWAF::getInstance()->getStorageEngine()->getRulesDSLCacheFile()), false);
		} catch (wfWAFBuildRulesException $e) {
			$GLOBALS['wfWAFDebugBuildException'] = $e;
		} catch (Exception $e) {
			$GLOBALS['wfWAFDebugBuildException'] = $e;
		}
	}

	wfWAFI18n::setInstance(new wfWAFI18n(new wfWAFWordPressI18n($wfWAFStorageEngine)));

	try {
		wfWAF::getInstance()->run();
	} catch (wfWAFBuildRulesException $e) {
			wfWAFRuntimeHealth::failure('rules', 'load-failed', $e);
	}

} catch (wfWAFStorageFileConfigException $e) {
		wfWAFRuntimeHealth::failure('bootstrap', 'storage-unavailable', $e);

} catch (wfWAFStorageEngineMySQLiException $e) {
		wfWAFRuntimeHealth::failure('bootstrap', 'storage-unavailable', $e);

} catch (wfWAFStorageFileException $e) {
		wfWAFRuntimeHealth::failure('bootstrap', 'storage-unavailable', $e);
}

}
catch (Exception $e) {
	wfWAFRuntimeHealth::failure('bootstrap', 'execution-failed', $e);
	$GLOBALS['wf_waf_failure'] = array('throwable' => new RuntimeException('WAF execution failed'));
}
catch (Throwable $e) {
	wfWAFRuntimeHealth::failure('bootstrap', 'execution-failed', $e);
	$GLOBALS['wf_waf_failure'] = array('throwable' => new RuntimeException('WAF execution failed'));
}
if (wfWAF::getInstance() === null) {
	require_once __DIR__ . '/dummy.php';
	wfWAF::setInstance(new wfDummyWaf());
}
if (wfWAFRuntimeHealth::snapshot()['outcome'] !== 'incomplete') {
	wfWAFRuntimeHealth::complete('bootstrap');
}
wfWAFRuntimeHealth::flush();
define('WFWAF_RUN_COMPLETE', true);
}
catch (Exception $e) {
wfWAFRuntimeHealth::failure('bootstrap', 'initialization-failed', $e);
}
catch (Throwable $e) {
wfWAFRuntimeHealth::failure('bootstrap', 'initialization-failed', $e);
}
finally {
	// exit() intentionally leaves display disabled for the rest of a blocked request.
	if ($wfWAFBootstrapDisplayErrors !== false) {
		@ini_set('display_errors', $wfWAFBootstrapDisplayErrors);
	}
	unset($wfWAFBootstrapDisplayErrors);
}
}
