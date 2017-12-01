<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Autobahn;

use OCP\AppFramework\App;
use OCP\AppFramework\Http\ContentSecurityPolicy;

class Application extends App {

	/** @var Hooks */
	private $hook;

	public function __construct (array $urlParams = array()) {
		parent::__construct('activity', $urlParams);
	}

	public function boot() {
		$server = $this->getContainer()->getServer();
		$config = $server->getConfig();
		$url = $config->getSystemValue('autobahn.websocket.url', null);
		// not configured? -> bye bye
		if ($url === null) {
			return;
		}

		// add capability
		$server->getCapabilitiesManager()->registerCapability(function () {
			return new Capabilities(\OC::$server->getConfig());
		});

		// register subscriber
		$server->getEventDispatcher()->addSubscriber(
			new \OCA\Autobahn\Subscriber(
				new \OCA\Autobahn\Autobahn($config))
			);

		// frontend elements are only added if a user is logged in
		if (!$server->getUserSession()->isLoggedIn()) {
			return;
		}

		// etag change handler
		$this->hook = new Hooks($server->getEventDispatcher());
		$this->hook->register();

		// allow csp connect
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedConnectDomain($url);
		\OC::$server->getContentSecurityPolicyManager()->addDefaultPolicy($csp);

		// add javascript
		\OCP\Util::addScript('autobahn', 'autobahn');

		// add data element for web socket url
		\OCP\Util::addHeader('data', ['key' => 'autobahn','ws-url' => $url]);
	}
}
