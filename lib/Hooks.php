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

use OC\Files\Filesystem;
use OCP\IAutobahn;
use OCP\Util;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class Hooks {

	/** @var EventDispatcherInterface */
	private $eventDispatcher;

	/** @var array */
	private $changedPaths = [];

	public function __construct(EventDispatcherInterface $eventDispatcher) {
		$this->eventDispatcher = $eventDispatcher;
	}

	public function register() {
		$signals = array(
			Filesystem::signal_post_create,
			Filesystem::signal_delete,
			'post_delete',
			Filesystem::signal_post_rename,
			Filesystem::signal_post_update,
		);

		foreach ($signals as $signal) {
			Util::connectHook(
				Filesystem::CLASSNAME, $signal,
				$this, $signal . 'Hook'
			);
		}

		/** @var IAutobahn $autobahn */
		$this->eventDispatcher->addListener("autobahn.end", function () {
			Hooks::end();
		});
	}

	public function post_createHook($arguments) {
		$this->changedPaths[] = $arguments['path'];
	}

	public function post_updateHook($arguments) {
		$this->changedPaths[] = $arguments['path'];
	}

	public function deleteHook($arguments) {
//		$this->changedPaths[] = $arguments['path'];
	}

	//post_rename
	public function post_renameHook($arguments) {
		$this->changedPaths[] = $arguments['oldpath'];
		$this->changedPaths[] = $arguments['newpath'];
	}

	private function end() {
		if (empty($this->changedPaths)) {
			return;
		}

		// publish
		$users = [];
		foreach ($this->changedPaths as $path) {
			$usersForPath = $this->getUsersForPath($path);
			$users = array_merge($users, $usersForPath);
		}

		$users = array_unique($users);
		foreach ($users as $index => $user) {
			$root = \OC::$server->getUserFolder($user);
			$e = $root->getEtag();

			\OC::$server->getEventDispatcher()->dispatch('files.root-etag-changed.' . $user,
				new GenericEvent('', [$e, $user]));
		}
	}

	private function getUsersForPath($path) {
		$owner = Filesystem::getOwner($path);
		$users = \OCP\Share::getUsersSharingFile($path, $owner, true, false);
		return $users['users'];
	}
}
