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

use OCP\IConfig;
use Thruway\ClientSession;
use Thruway\Logging\Logger;
use Thruway\Peer\Client;
use Thruway\Transport\PawlTransportProvider;

class Autobahn {

	/** @var array */
	private $buffer;
	/** @var IConfig */
	private $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
		$this->buffer = [];
		register_shutdown_function(function() {
			$this->end();
		});
	}

	/**
	 * @param string $topic
	 * @param mixed $payLoad
	 */
	public function publish($topic, $payLoad) {
		$this->buffer[$topic] = isset($this->buffer[$topic]) ? $this->buffer[$topic] : [];
		$this->buffer[$topic][] = $payLoad;
	}

	private function end() {
		\OC::$server->getEventDispatcher()->dispatch('autobahn.end');
		$url = $this->config->getSystemValue('autobahn.websocket.url', null);

		// don't connect to wamp router if there are no messages to be sent
		if (empty($this->buffer)) {
			return;
		}

		// init client
		Logger::set(new OCLogger());
		$client = new Client("realm1");
		$client->setAttemptRetry(false);
		$client->addTransportProvider(new PawlTransportProvider($url));

		/** @var ClientSession $session */
		$session = null;
		$client->on('open', function (ClientSession $s) use (&$session) {
			$session = $s;
		});
		$client->start(false);

		while ($session === null) {
			$client->getLoop()->tick();
		}

		// publish
		foreach ($this->buffer as $topic => $payloads) {
			foreach ($payloads as $index => $payload) {
				$session->publish($topic, $payload, null, ['acknowledge' => true])
					->always(function () use ($topic, $index) {
						unset($this->buffer[$topic][$index]);
						if (empty($this->buffer[$topic])) {
							unset($this->buffer[$topic]);
						}
					});
			}
		}

		while (!empty($this->buffer)) {
			$client->getLoop()->tick();
		}
		$session->close();
	}
}
