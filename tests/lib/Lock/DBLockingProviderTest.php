<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Test\Lock;

use OCP\Lock\ILockingProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Class DBLockingProvider
 *
 * @group DB
 *
 * @package Test\Lock
 */
class DBLockingProviderTest extends LockingProvider {
	/**
	 * @var \OC\Lock\DBLockingProvider
	 */
	protected $instance;

	/**
	 * @var \OCP\IDBConnection
	 */
	private $connection;

	/**
	 * @var \OCP\AppFramework\Utility\ITimeFactory
	 */
	private $timeFactory;

	private $currentTime;

	public function setUp() {
		$this->currentTime = time();
		$this->timeFactory = $this->getMock('\OCP\AppFramework\Utility\ITimeFactory');
		$this->timeFactory->expects($this->any())
			->method('getTime')
			->will($this->returnCallback(function () {
				return $this->currentTime;
			}));
		parent::setUp();
	}

	/**
	 * @return \OCP\Lock\ILockingProvider
	 */
	protected function createInstance() {
		$this->connection = \OC::$server->getDatabaseConnection();
		return new \OC\Lock\DBLockingProvider($this->connection, \OC::$server->getLogger(), $this->timeFactory, 3600);
	}

	public function tearDown() {
		$this->connection->executeQuery('DELETE FROM `*PREFIX*file_locks`');
		parent::tearDown();
	}

	public function testCleanEmptyLocks() {
		$this->currentTime = 100;
		$this->instance->acquireLock('foo', ILockingProvider::LOCK_EXCLUSIVE);
		$this->instance->acquireLock('asd', ILockingProvider::LOCK_EXCLUSIVE);

		$this->currentTime = 200;
		$this->instance->acquireLock('bar', ILockingProvider::LOCK_EXCLUSIVE);
		$this->instance->changeLock('asd', ILockingProvider::LOCK_SHARED);

		$this->currentTime = 150 + 3600;

		$this->assertEquals(3, $this->getLockEntryCount());

		$this->instance->cleanExpiredLocks();

		$this->assertEquals(2, $this->getLockEntryCount());
	}

	private function getLockEntryCount() {
		$query = $this->connection->prepare('SELECT count(*) FROM `*PREFIX*file_locks`');
		$query->execute();
		return $query->fetchColumn();
	}

	public function testConcurrentSharedLockConstraintViolation() {
		$connection1 = $this->getMock('\OCP\IDBConnection');
		$connection2 = $this->getMock('\OCP\IDBConnection');
		$instance1 = new \OC\Lock\DBLockingProvider($connection1, \OC::$server->getLogger(), $this->timeFactory, 3600);
		$instance2 = new \OC\Lock\DBLockingProvider($connection2, \OC::$server->getLogger(), $this->timeFactory, 3600);

		// first instance inserts entry
		$connection1->expects($this->once())
			->method('insertIfNotExist')
			->with('*PREFIX*file_locks', ['key' => 'foo', 'lock' => 1, 'ttl' => $this->currentTime + 3600], ['key'])
			->will($this->returnValue(1));
		$connection1->expects($this->never())
			->method('executeUpdate');

		// second instance fails to insert due to constraint violation
		$connection2->expects($this->once())
			->method('insertIfNotExist')
			->with('*PREFIX*file_locks', ['key' => 'foo', 'lock' => 1, 'ttl' => $this->currentTime + 3600], ['key'])
			->will($this->throwException(new UniqueConstraintViolationException('dummy', $this->getMock('\Doctrine\DBAL\Driver\DriverException'))));
		$connection2->expects($this->once())
			->method('executeUpdate')
			->with('UPDATE `*PREFIX*file_locks` SET `lock` = `lock` + 1, `ttl` = ? WHERE `key` = ? AND `lock` >= 0', [$this->currentTime + 3600, 'foo'])
			->will($this->returnValue(1));

		$instance1->acquireLock('foo', ILockingProvider::LOCK_SHARED);
		$instance2->acquireLock('foo', ILockingProvider::LOCK_SHARED);

		$this->assertTrue($instance1->isLocked('foo', ILockingProvider::LOCK_SHARED));
		$this->assertTrue($instance2->isLocked('foo', ILockingProvider::LOCK_SHARED));
	}

}
