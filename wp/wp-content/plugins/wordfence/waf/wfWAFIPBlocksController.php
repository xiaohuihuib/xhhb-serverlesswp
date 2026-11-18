<?php
if (!defined('WFWAF_RUN_COMPLETE')) {

require_once __DIR__ . '/../vendor/wordfence/wf-waf/src/lib/shutdown.php';
require_once __DIR__ . '/wfWAFBlockI18n.php';
require_once __DIR__ . '/wfWAFBlockConstants.php';

class wfWAFIPBlocksController implements wfWAFBlockConstants /* wfWAFBlockConstants is used here for backwards compatibility, wfWAFBlockI18n should be considered the canonical owner of them */
{
	protected static $_currentController = null;

	public static function currentController() {
		if (self::$_currentController === null) {
			self::$_currentController = new wfWAFIPBlocksController();
		}
		return self::$_currentController;
	}
	
	public static function setCurrentController($currentController) {
		self::$_currentController = $currentController;
	}
	
	/**
	 * Schedules a config sync to happen at the end of the current process's execution.
	 */
	public static function setNeedsSynchronizeConfigSettings() {
		static $willSynchronize = false;
		if (!$willSynchronize) {
			$willSynchronize = true;
			wfShutdownRegistry::getDefaultInstance()->register('wfWAFIPBlocksController::synchronizeConfigSettings');
		}
	}
	
	/**
	 * Synchronize current blocking settings without replacing unrelated configuration.
	 *
	 * @return bool|null False on failure.
	 */
	public static function synchronizeConfigSettings() {
		if (!class_exists('wfConfig') || !wfConfig::tableExists() || !wfWAF::getInstance()) { // Ensure this is only called when WordPress and the plugin are fully loaded
			return;
		}
		
		static $isSynchronizing = false;
		if ($isSynchronizing) {
			return;
		}
		$isSynchronizing = true;
		
		global $wpdb;
		$suppressed = $wpdb->suppress_errors(!(defined('WFWAF_DEBUG') && WFWAF_DEBUG));
		try {
			// Pattern Blocks
			$blocks = wfBlock::patternBlocks(true);
			if ($wpdb->last_error) {
				throw new RuntimeException('Unable to read authoritative blocks');
			}
			$patternBlocks = array();
			foreach ($blocks as $b) {
				$patternBlocks[] = array('id' => $b->id, 'ipRange' => $b->ipRange, 'hostnamePattern' => $b->hostname, 'uaPattern' => $b->userAgent, 'refPattern' => $b->referrer, 'expiration' => $b->expiration);
			}

			// Country Blocks
			$countryBlocks = array();
			$countryBlockEntries = wfBlock::countryBlocks(true);
			if ($wpdb->last_error) {
				throw new RuntimeException('Unable to read authoritative blocks');
			}
			$countryBlocks['blocks'] = array();
			foreach ($countryBlockEntries as $b) {
				$reason = wfI18n::__('Access from your area has been temporarily limited for security reasons', 'wordfence');

				$countryBlocks['blocks'][] = array(
					'id' => $b->id,
					'countries' => $b->countries,
					'blockLogin' => $b->blockLogin,
					'blockSite' => $b->blockSite,
					'reason' => $reason,
					'expiration' => $b->expiration,
				);
			}
			$countryBlocks['action'] = wfConfig::get('cbl_action', false);
			$countryBlocks['loggedInBlocked'] = wfConfig::get('cbl_loggedInBlocked', false);
			$countryBlocks['bypassRedirURL'] = wfConfig::get('cbl_bypassRedirURL', '');
			$countryBlocks['bypassRedirDest'] = wfConfig::get('cbl_bypassRedirDest', '');
			$countryBlocks['bypassViewURL'] = wfConfig::get('cbl_bypassViewURL', '');
			$countryBlocks['redirURL'] = wfConfig::get('cbl_redirURL', '');
			$countryBlocks['cookieVal'] = wfBlock::countryBlockingBypassCookieValue();

			//Other Blocks
			$otherBlocks = array('blockedTime' => wfConfig::get('blockedTime', 0));
			$otherBlockEntries = wfBlock::ipBlocks(true);
			if ($wpdb->last_error) {
				throw new RuntimeException('Unable to read authoritative blocks');
			}
			$otherBlocks['blocks'] = array();
			foreach ($otherBlockEntries as $b) {
				$reason = $b->reason;
				if ($b->type == wfBlock::TYPE_IP_MANUAL || $b->type == wfBlock::TYPE_IP_AUTOMATIC_PERMANENT) {
					$reason = wfI18n::__('Manual block by administrator', 'wordfence');
				}

				$otherBlocks['blocks'][] = array(
					'id' => $b->id,
					'IP' => base64_encode(wfUtils::inet_pton($b->ip)),
					'reason' => $reason,
					'expiration' => $b->expiration,
				);
			}

			//Lockouts
			$lockoutEntries = wfBlock::lockouts(true);
			if ($wpdb->last_error) {
				throw new RuntimeException('Unable to read authoritative blocks');
			}
			$lockoutSecs = wfConfig::get('loginSec_lockoutMins') * 60;
			$lockouts = array('lockedOutTime' => $lockoutSecs, 'lockouts' => array());
			foreach ($lockoutEntries as $l) {
				$lockouts['lockouts'][] = array(
					'id' => $l->id,
					'IP' => base64_encode(wfUtils::inet_pton($l->ip)),
					'reason' => $l->reason,
					'expiration' => $l->expiration,
				);
			}

			// Save it
			$patternBlocksJSON = wfWAFUtils::json_encode($patternBlocks);
			wfWAF::getInstance()->getStorageEngine()->setConfig('patternBlocks', $patternBlocksJSON, 'synced');
			$countryBlocksJSON = wfWAFUtils::json_encode($countryBlocks);
			wfWAF::getInstance()->getStorageEngine()->setConfig('countryBlocks', $countryBlocksJSON, 'synced');
			$otherBlocksJSON = wfWAFUtils::json_encode($otherBlocks);
			wfWAF::getInstance()->getStorageEngine()->setConfig('otherBlocks', $otherBlocksJSON, 'synced');
			$lockoutsJSON = wfWAFUtils::json_encode($lockouts);
			wfWAF::getInstance()->getStorageEngine()->setConfig('lockouts', $lockoutsJSON, 'synced');
			
			wfWAF::getInstance()->getStorageEngine()->setConfig('advancedBlockingEnabled', wfConfig::get('firewallEnabled'), 'synced');
			wfWAF::getInstance()->getStorageEngine()->setConfig('disableWAFIPBlocking', wfConfig::get('disableWAFIPBlocking'), 'synced');
			$storage = wfWAF::getInstance()->getStorageEngine();
			if (isset(wfWAFRuntimeHealth::snapshot()['issues']['config-sync']) && method_exists($storage, 'saveConfig') && $storage->saveConfig('synced') !== false) {
				wfWAFRuntimeHealth::complete('config-sync');
			}
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure('config-sync', 'synchronization-failed', $e);
			return false;
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure('config-sync', 'synchronization-failed', $e);
			return false;
		}
		finally {
			$isSynchronizing = false;

			$wpdb->suppress_errors($suppressed);
		}
	}
	
	/**
	 * @param wfWAFRequest $request
	 * @return bool|string If not blocked, returns false. Otherwise a string of the reason it was blocked or true. 
	 */
	public function shouldBlockRequest($request) {
		// Checking the user whitelist is done before reaching this call
		
		$ip = $request->getIP();
		
		//Check the system whitelist
		if ($this->checkForWhitelisted($ip)) {
			return false;
		}
		
		//Let the plugin handle these
		$wfFunc = $request->getQueryString('_wfsf');
		if ($wfFunc == 'unlockEmail' || $wfFunc == 'unlockAccess') { // Can't check validity here, let it pass through to plugin level where it can
			return false;
		}
		
		$logHuman = $request->getQueryString('wordfence_lh');
		if ($logHuman !== null) {
			return false;
		}
		
		//Start block checks
		$ipNum = wfWAFUtils::inet_pton($ip);
		$hostname = null;
		$ua = $request->getHeaders('User-Agent'); if ($ua === null) { $ua = ''; }
		$referer = $request->getHeaders('Referer'); if ($referer === null) { $referer = ''; }
		
		$isPaid = $this->optionalConfig('isPaid', false);
		if (!is_scalar($isPaid)) {
			wfWAFRuntimeHealth::failure('country-blocks', 'invalid-license-state');
			$isPaid = false;
		}
		$pluginABSPATH = $this->optionalConfig('pluginABSPATH', '');
		if (!is_string($pluginABSPATH)) {
			$pluginABSPATH = '';
			wfWAFRuntimeHealth::failure('plugin-blocks', 'invalid-root');
		}
		$patternBlocksJSON = $this->optionalConfig('patternBlocks');
		$countryBlocksJSON = $this->optionalConfig('countryBlocks');
		$otherBlocksJSON = $this->optionalConfig('otherBlocks');
		$lockoutsJSON = $this->optionalConfig('lockouts');
		$bareRequestURI = wfWAFUtils::extractBareURI($request->getURI());
		
		if (isset($_SERVER['SCRIPT_FILENAME']) && (strpos($_SERVER['SCRIPT_FILENAME'], $pluginABSPATH . "wp-admin/") === 0 || strpos($_SERVER['SCRIPT_FILENAME'], $pluginABSPATH . "wp-content/") === 0 || strpos($_SERVER['SCRIPT_FILENAME'], $pluginABSPATH . "wp-includes/") === 0)) {
			return false; //Rely on WordPress's own access control and blocking at the plugin level
		}
		
		// Pattern Blocks from the Advanced Blocking page (IP Range, UA, Referer)
		$patternBlocks = $this->decodeBlockConfig($patternBlocksJSON, 'patternBlocks');
		$patternAction = false;
		$patternsRemaining = count($patternBlocks);
		if (is_array($patternBlocks)) {
			// Instead of a long block of if/else statements, using bitshifting to generate an expected value and a found value
			$ipRangeOffset = 1;
			$uaPatternOffset = 2;
			$refPatternOffset = 3;
			
			foreach ($patternBlocks as $b) {
				$patternsRemaining--;
				$valid = is_array($b) && isset($b['id']) && is_numeric($b['id']) &&
					(!isset($b['expiration']) || is_numeric($b['expiration'])) &&
					(!isset($b['ipRange']) || is_string($b['ipRange'])) &&
					(!isset($b['hostnamePattern']) || is_string($b['hostnamePattern'])) &&
					(!isset($b['uaPattern']) || is_string($b['uaPattern'])) &&
					(!isset($b['refPattern']) || is_string($b['refPattern']));
				if (!$valid) {
					wfWAFRuntimeHealth::failure('config-patternBlocks', 'invalid-record');
					continue;
				}
				try {
					$expectedBits = 0;
					$foundBits = 0;
				
					if (isset($b['expiration']) && $b['expiration'] < time() && $b['expiration'] != 0) {
						continue;
					}

					if (!empty($b['ipRange'])) {
						$expectedBits |= (1 << $ipRangeOffset);
					
						$range = new wfWAFUserIPRange($b['ipRange']);
						if ($range->isIPInRange($ip)) {
							$foundBits |= (1 << $ipRangeOffset);
						}
					}
				
					if (!empty($b['hostnamePattern'])) {
						$expectedBits |= (1 << $ipRangeOffset);
						if ($hostname === null) {
							$hostname = wfWAFUtils::reverseLookup($ip);
						}
						if (preg_match(wfWAFUtils::patternToRegex($b['hostnamePattern']), $hostname)) {
							$foundBits |= (1 << $ipRangeOffset);
						}
					}

					if (!empty($b['uaPattern'])) {
						$expectedBits |= (1 << $uaPatternOffset);
						if (wfWAFUtils::isUABlocked($b['uaPattern'], $ua)) {
							$foundBits |= (1 << $uaPatternOffset);
						}
					}
				
					if (!empty($b['refPattern'])) {
						$expectedBits |= (1 << $refPatternOffset);
						if (wfWAFUtils::isRefererBlocked($b['refPattern'], $referer)) {
							$foundBits |= (1 << $refPatternOffset);
						}
					}
				
					if (!$patternAction && $foundBits === $expectedBits && $expectedBits > 0) {
						$patternAction = array('action' => wfWAFBlockI18n::getBlockDescription(wfWAFBlockI18n::WFWAF_BLOCK_UAREFIPRANGE), 'id' => $b['id']);
						break;
					}
				}
				catch (wfWAFRunException $e) {
					throw $e;
				}
				catch (Exception $e) {
					wfWAFRuntimeHealth::failure('pattern-blocks', 'evaluation-failed', $e);
				}
				catch (Throwable $e) {
					wfWAFRuntimeHealth::failure('pattern-blocks', 'evaluation-failed', $e);
				}
			}
		}
		if ($patternsRemaining === 0) {
			wfWAFRuntimeHealth::complete('config-patternBlocks');
		}
		$patternHealth = wfWAFRuntimeHealth::snapshot();
		if ($patternsRemaining === 0 && in_array('config-read-patternBlocks', $patternHealth['completed'], true) && !isset($patternHealth['issues']['config-patternBlocks'])) {
			wfWAFRuntimeHealth::complete('pattern-blocks');
		}
		if ($patternAction) {
			return $patternAction;
		}
		// End Pattern Blocks
		
		// Country Blocking
		if ($isPaid) {
			try {
				$countryBlocks = $this->decodeBlockConfig($countryBlocksJSON, 'countryBlocks');
				if (is_array($countryBlocks) && isset($countryBlocks['blocks'])) {
					$blocks = $countryBlocks['blocks'];
					foreach ($blocks as $b) {
						$blockedCountries = $b['countries'];
						$bareRequestURI = wfWAFUtils::extractBareURI($request->getURI());
						$bareBypassRedirURI = wfWAFUtils::extractBareURI($countryBlocks['bypassRedirURL']);
						$skipCountryBlocking = false;
					
						if ($bareBypassRedirURI && $bareRequestURI == $bareBypassRedirURI) { // Run this before country blocking because even if the user isn't blocked we need to set the bypass cookie so they can bypass future blocks.
							if ($countryBlocks['bypassRedirDest']) {
								if ($this->setBypassCookie($countryBlocks['cookieVal'])) {
									return array('action' => wfWAFBlockI18n::getBlockDescription(wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY_BYPASS_REDIR), 'id' => $b['id']);
								}
							}
						}
					
						$bareBypassViewURI = wfWAFUtils::extractBareURI($countryBlocks['bypassViewURL']);
						if ($bareBypassViewURI && $bareBypassViewURI == $bareRequestURI) {
							$this->setBypassCookie($countryBlocks['cookieVal']);
							$skipCountryBlocking = true;
						}
					
						$bypassCookieSet = false;
						$bypassCookie = $request->getCookies('wfCBLBypass');
						if (is_string($bypassCookie) && $countryBlocks['cookieVal'] !== '' && wfWAFUtils::hash_equals($bypassCookie, $countryBlocks['cookieVal'])) {
							$bypassCookieSet = true;
						}
					
						if (!$skipCountryBlocking && $blockedCountries && !$bypassCookieSet) {
							$isAuthRequest = (strpos($bareRequestURI, '/wp-login.php') !== false);
							$isXMLRPC = (strpos($bareRequestURI, '/xmlrpc.php') !== false);
							$isUserLoggedIn = wfWAF::getInstance()->parseAuthCookie() !== false;
						
							// If everything is checked, make sure this always runs.
							if ($countryBlocks['loggedInBlocked'] && $b['blockLogin'] && $b['blockSite']) {
								if ($blocked = $this->checkForBlockedCountry($countryBlocks, $ip, $bareRequestURI)) { $blocked['id'] = $b['id']; return $blocked; }
							}
							// Block logged in users.
							if ($countryBlocks['loggedInBlocked'] && $isUserLoggedIn) {
								if ($blocked = $this->checkForBlockedCountry($countryBlocks, $ip, $bareRequestURI)) { $blocked['id'] = $b['id']; return $blocked; }
							}
							// Block the login form itself and any attempt to authenticate.
							if ($b['blockLogin'] && $isAuthRequest) {
								if ($blocked = $this->checkForBlockedCountry($countryBlocks, $ip, $bareRequestURI)) { $blocked['id'] = $b['id']; return $blocked; }
							}
							// Block requests that aren't to the login page, xmlrpc.php, or a user already logged in.
							if ($b['blockSite'] && !$isAuthRequest && !$isXMLRPC && !$isUserLoggedIn) {
								if ($blocked = $this->checkForBlockedCountry($countryBlocks, $ip, $bareRequestURI)) { $blocked['id'] = $b['id']; return $blocked; }
							}
							// XMLRPC is inaccesible when public portion of the site and auth is disabled.
							if ($b['blockLogin'] && $b['blockSite'] && $isXMLRPC) {
								if ($blocked = $this->checkForBlockedCountry($countryBlocks, $ip, $bareRequestURI)) { $blocked['id'] = $b['id']; return $blocked; }
							}
						
							// Any bypasses and other block possibilities will be checked at the plugin level once WordPress loads
						}
					}
				}
			}
			catch (wfWAFRunException $e) {
				throw $e;
			}
			catch (Exception $e) {
				wfWAFRuntimeHealth::failure('country-blocks', 'evaluation-failed', $e);
			}
			catch (Throwable $e) {
				wfWAFRuntimeHealth::failure('country-blocks', 'evaluation-failed', $e);
			}
		}
		// End Country Blocking
		
		// Other Blocks
		$otherBlocks = $this->decodeBlockConfig($otherBlocksJSON, 'otherBlocks');
		if (is_array($otherBlocks)) {
			$blocks = $otherBlocks['blocks'];
			$bareRequestURI = wfWAFUtils::extractBareURI($request->getURI());
			$isAuthRequest = (stripos($bareRequestURI, '/wp-login.php') !== false);
			foreach ($blocks as $b) {
				if (isset($b['expiration']) && $b['expiration'] < time() && $b['expiration'] != 0) {
					continue;
				}
				
				if (base64_decode($b['IP']) != $ipNum) {
					continue;
				}
				
				if ($isAuthRequest && isset($b['wfsn']) && $b['wfsn']) {
					return array('action' => wfWAFBlockI18n::getBlockDescription(wfWAFBlockI18n::WFWAF_BLOCK_WFSN), 'id' => $b['id']);
				}
				
				return array('action' => (empty($b['reason']) ? '' : $b['reason']), 'id' => $b['id'], 'block' => true);
			}
		}
		// End Other Blocks
		
		// Lockouts
		$lockouts = $this->decodeBlockConfig($lockoutsJSON, 'lockouts');
		if (is_array($lockouts)) {
			$lockouts = $lockouts['lockouts'];
			$isAuthRequest = (stripos($bareRequestURI, '/wp-login.php') !== false) || (stripos($bareRequestURI, '/xmlrpc.php') !== false);
			if ($isAuthRequest) {
				foreach ($lockouts as $l) {
					if (isset($l['expiration']) && $l['expiration'] < time()) {
						continue;
					}
					
					if (base64_decode($l['IP']) != $ipNum) {
						continue;
					}
					
					return array('action' => (empty($l['reason']) ? '' : $l['reason']), 'id' => $l['id'], 'lockout' => true);
				}
			}
		}
		// End Lockouts
		
		return false;
	}
	
	/**
	 * Read a validated country block redirect.
	 *
	 * @param array|null $countryBlocks Optional decoded data.
	 * @return string|false
	 */
	public function countryRedirURL($countryBlocks = null) {
		if ($countryBlocks === null) {
			$countryBlocks = $this->decodeBlockConfig($this->optionalConfig('countryBlocks'), 'countryBlocks');
		}
		return is_array($countryBlocks) && isset($countryBlocks['action'], $countryBlocks['redirURL']) && $countryBlocks['action'] === 'redir' && is_string($countryBlocks['redirURL']) ? $countryBlocks['redirURL'] : false;
	}

	/**
	 * Read a validated country bypass redirect.
	 *
	 * @param array|null $countryBlocks Optional decoded data.
	 * @return string|false
	 */
	public function countryBypassRedirURL($countryBlocks = null) {
		if ($countryBlocks === null) {
			$countryBlocks = $this->decodeBlockConfig($this->optionalConfig('countryBlocks'), 'countryBlocks');
		}
		return is_array($countryBlocks) && isset($countryBlocks['bypassRedirDest']) && is_string($countryBlocks['bypassRedirDest']) ? $countryBlocks['bypassRedirDest'] : false;
	}

	/**
	 * Read an optional feature independently of other configuration.
	 *
	 * @param string $key Setting.
	 * @param mixed $default Missing value.
	 * @return mixed
	 */
	private function optionalConfig($key, $default = null) {
		try {
			$value = wfWAF::getInstance()->getStorageEngine()->getConfig($key, $default, 'synced');
			wfWAFRuntimeHealth::complete('config-read-' . $key);
			return $value;
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure('config-read-' . $key, 'read-failed', $e);
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure('config-read-' . $key, 'read-failed', $e);
		}
		return $default;
	}

	/**
	 * Decode feature configuration and retain only structurally valid records.
	 *
	 * @param mixed $json Stored data.
	 * @param string $key Feature setting.
	 * @return array
	 */
	private function decodeBlockConfig($json, $key) {
		$field = $key === 'lockouts' ? 'lockouts' : 'blocks';
		$empty = $key === 'patternBlocks' ? array() : array($field => array());
		if ($json === null || $json === '') {
			wfWAFRuntimeHealth::complete('config-' . $key);
			return $empty;
		}
		$data = is_string($json) ? wfWAFUtils::json_decode($json, true) : null;
		if (!is_array($data) || ($key !== 'patternBlocks' && (!isset($data[$field]) || !is_array($data[$field])))) {
			wfWAFRuntimeHealth::failure('config-' . $key, 'invalid-container');
			return $empty;
		}
		// Pattern records are validated as consumed so an established block skips later work.
		if ($key === 'patternBlocks') {
			return $data;
		}
		$records = $data[$field];
		if ($key === 'countryBlocks') {
			foreach (array('bypassRedirURL', 'bypassRedirDest', 'bypassViewURL', 'redirURL', 'cookieVal') as $name) {
				if (!isset($data[$name])) {
					$data[$name] = '';
				}
				if (!is_string($data[$name])) {
					wfWAFRuntimeHealth::failure('config-' . $key, 'invalid-options');
					return $empty;
				}
			}
			if (!isset($data['action'])) {
				$data['action'] = 'block';
			}
			if (!isset($data['loggedInBlocked'])) {
				$data['loggedInBlocked'] = false;
			}
			if (!is_scalar($data['action']) || !is_scalar($data['loggedInBlocked'])) {
				wfWAFRuntimeHealth::failure('config-' . $key, 'invalid-options');
				return $empty;
			}
		}
		$valid = array();
		foreach ($records as $record) {
			$ok = is_array($record) && isset($record['id']) && is_numeric($record['id']);
			if ($ok && isset($record['expiration']) && !is_numeric($record['expiration'])) {
				$ok = false;
			}
			if ($ok && $key === 'countryBlocks') {
				$ok = isset($record['countries'], $record['blockLogin'], $record['blockSite']) && is_array($record['countries']) && is_scalar($record['blockLogin']) && is_scalar($record['blockSite']);
				if ($ok) {
					foreach ($record['countries'] as $country) {
						if (!is_string($country)) {
							$ok = false;
						}
					}
				}
			}
			elseif ($ok) {
				$ok = isset($record['IP']) && is_string($record['IP']) && strlen(base64_decode($record['IP'], true)) === 16;
				if (isset($record['reason']) && !is_string($record['reason'])) {
					$ok = false;
				}
			}
			if ($ok) {
				$valid[] = $record;
			}
			else {
				wfWAFRuntimeHealth::failure('config-' . $key, 'invalid-record');
			}
		}
		wfWAFRuntimeHealth::complete('config-' . $key);
		$data[$field] = $valid;
		return $data;
	}
	
	/**
	 * Keep cookie/header failures from aborting rule evaluation.
	 *
	 * @param string $value Bypass cookie.
	 * @return bool
	 */
	private function setBypassCookie($value) {
		try {
			$result = setcookie('wfCBLBypass', $value, time() + (86400 * 365), '/', '', $this->isFullSSL(), true);
			if (!$result) {
				wfWAFRuntimeHealth::failure('country-cookie', 'cookie-failed', null, false);
			}
			else {
				wfWAFRuntimeHealth::complete('country-cookie');
			}
			return $result;
		}
		catch (Exception $e) {
			wfWAFRuntimeHealth::failure('country-cookie', 'cookie-failed', $e, false);
		}
		catch (Throwable $e) {
			wfWAFRuntimeHealth::failure('country-cookie', 'cookie-failed', $e, false);
		}
		return false;
	}
	
	protected function checkForBlockedCountry($countryBlock, $ip, $bareRequestURI) {
		$homeURL = $this->optionalConfig('homeURL', '');
		if (!is_string($homeURL)) {
			$homeURL = '';
		}
		
		$bareRequestURI = rtrim($bareRequestURI, '/\\');
		if ($country = $this->ip2Country($ip)) {
			wfWAFRuntimeHealth::complete('country-blocks');
			$blocks = $countryBlock['blocks'];
			foreach ($blocks as $b) {
				foreach ($b['countries'] as $blocked) {
					if (strtoupper($blocked) == strtoupper($country)) {
						if ($countryBlock['action'] == 'redir') {
							$redirURL = $countryBlock['redirURL'];
							$eRedirHost = wfWAFUtils::extractHostname($redirURL);
							$isExternalRedir = false;
							if ($eRedirHost && $homeURL && $eRedirHost != wfWAFUtils::extractHostname($homeURL)) {
								$isExternalRedir = true;
							}
							
							if ((!$isExternalRedir) && rtrim(wfWAFUtils::extractBareURI($redirURL), '/\\') == $bareRequestURI){ //Is this the URI we want to redirect to, then don't block it
								//Do nothing
							}
							else {
								return array('action' => wfWAFBlockI18n::getBlockDescription(wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY_REDIR, $redirURL));
							}
						}
						else {
							return array('action' => wfWAFBlockI18n::getBlockDescription(wfWAFBlockI18n::WFWAF_BLOCK_COUNTRY));
						}
					}
				}
			}
		}
		
		return false;
	}
	
	protected function checkForWhitelisted($ip) {
		$allowed = false;
		$pluginABSPATH = $this->optionalConfig('pluginABSPATH', '');
		if (!is_string($pluginABSPATH)) {
			$pluginABSPATH = '';
		}
		$serverIPsJSON = $this->optionalConfig('serverIPs', '[]');
		$whitelistedServiceIPsJSON = $this->optionalConfig('whitelistedServiceIPs', null);

		$serverIPs = is_string($serverIPsJSON) ? wfWAFUtils::json_decode($serverIPsJSON, true) : null;
		if ($serverIPsJSON !== null && !is_array($serverIPs)) {
			wfWAFRuntimeHealth::failure('service-allowlist', 'invalid-container');
		}
		if (is_array($serverIPs)) {
			// WordPress performs the final cron/synchronization access checks after startup.
			$serverRequest = (isset($_SERVER['SCRIPT_FILENAME']) && $_SERVER['SCRIPT_FILENAME'] == realpath($pluginABSPATH . DIRECTORY_SEPARATOR . 'wp-cron.php')) || !empty($_GET['wordfence_syncAttackData']);
			foreach ($serverIPs as $testIP) {
				if (!is_string($testIP)) {
					wfWAFRuntimeHealth::failure('service-allowlist', 'invalid-entry');
					continue;
				}
				if ($serverRequest && !$allowed && wfWAFUtils::inet_pton($ip) == wfWAFUtils::inet_pton($testIP)) {
					$allowed = true;
				}
			}
		}
		
		$whitelistedServiceIPs = is_string($whitelistedServiceIPsJSON) ? wfWAFUtils::json_decode($whitelistedServiceIPsJSON, true) : null;
		if ($whitelistedServiceIPsJSON !== null && !is_array($whitelistedServiceIPs)) {
			wfWAFRuntimeHealth::failure('service-allowlist', 'invalid-container');
			$whitelistedServiceIPs = array();
		}
		if (is_array($whitelistedServiceIPs)) {
			$wfIPWhitelist = $whitelistedServiceIPs;
		}
		else {
			$wordfenceLib = realpath(dirname(__FILE__) . '/../lib');
			include($wordfenceLib . '/wfIPWhitelist.php'); /** @var array $wfIPWhitelist */
		}
		
		foreach ($wfIPWhitelist as $group) {
			if (!is_array($group)) {
				wfWAFRuntimeHealth::failure('service-allowlist', 'invalid-group');
				continue;
			}
			foreach ($group as $subnet) {
				if ($subnet instanceof wfWAFUserIPRange) { //Not currently reached
					if (!$allowed && $subnet->isIPInRange($ip)) {
						$allowed = true;
					}
				}
				elseif (is_string($subnet)) {
					if (!$allowed && wfWAFUtils::subnetContainsIP($subnet, $ip)) {
						$allowed = true;
					}
				}
				else {
					wfWAFRuntimeHealth::failure('service-allowlist', 'invalid-entry');
				}
			}
		}
		$completed = wfWAFRuntimeHealth::snapshot()['completed'];
		if (in_array('config-read-serverIPs', $completed, true) && in_array('config-read-whitelistedServiceIPs', $completed, true)) {
			wfWAFRuntimeHealth::complete('service-allowlist');
		}
		return $allowed;
	}
	
	protected function ip2Country($ip) {
		/**
		 * It's possible this class is already loaded from a different installation of the plugin
		 * by the time this is reached. See wfUtils::requireIpLocator for additional details.
		 */
		if (!class_exists('wfIpLocator'))
			require_once __DIR__ . '/../lib/wfIpLocator.php';
		return wfIpLocator::getInstance()->getCountryCode($ip);
	}
	
	/**
	 * Returns whether or not the site should be treated as if it's full-time SSL.
	 *
	 * @return bool
	 */
	protected function isFullSSL() {
		try {
			$is_ssl = false; //This is the same code from WP modified so we can use it here
			if ( isset( $_SERVER['HTTPS'] ) ) {
				if ( 'on' == strtolower( $_SERVER['HTTPS'] ) ) {
					$is_ssl = true;
				}
				
				if ( '1' == $_SERVER['HTTPS'] ) {
					$is_ssl = true;
				}
			} elseif ( isset($_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
				$is_ssl = true;
			}
			
			$homeURL = wfWAF::getInstance()->getStorageEngine()->getConfig('homeURL', null, 'synced');
			return $is_ssl && parse_url($homeURL, PHP_URL_SCHEME) === 'https';
		}
		catch (Exception $e) {
			//Do nothing
		}
		
		return false;
	}
	
}
}