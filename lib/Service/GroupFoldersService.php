<?php
/**
 * Files_FullTextSearch - Index the content of your files
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Files_FullTextSearch\Service;


use Exception;
use OC\App\AppManager;
use OCA\Files_FullTextSearch\Exceptions\FileIsNotIndexableException;
use OCA\Files_FullTextSearch\Exceptions\GroupFolderNotFoundException;
use OCA\Files_FullTextSearch\Exceptions\KnownFileSourceException;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Model\MountPoint;
use OCA\FullTextSearch\Model\Index;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Share\IManager;

class GroupFoldersService {


	/** @var IManager */
	private $shareManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var FolderManager */
	private $folderManager;

	/** @var LocalFilesService */
	private $localFilesService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var MountPoint[] */
	private $groupFolders = [];


	/**
	 * ExternalFilesService constructor.
	 *
	 * @param $userId
	 * @param IDBConnection $dbConnection
	 * @param AppManager $appManager
	 * @param IManager $shareManager
	 * @param IGroupManager $groupManager
	 * @param LocalFilesService $localFilesService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId, IDBConnection $dbConnection, AppManager $appManager, IManager $shareManager,
		IGroupManager $groupManager, LocalFilesService $localFilesService,
		ConfigService $configService, MiscService $miscService
	) {

		if ($appManager->isEnabledForUser('groupfolders', $userId)) {
			try {
				$this->folderManager = new FolderManager($dbConnection);
			} catch (Exception $e) {
				return;
			}
		}

		$this->shareManager = $shareManager;
		$this->groupManager = $groupManager;
		$this->localFilesService = $localFilesService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $userId
	 */
	public function initGroupSharesForUser($userId) {
		if ($this->folderManager === null) {
			return;
		}

		$this->groupFolders = $this->getMountPoints($userId);
	}


	/**
	 * @param Node $file
	 *
	 * @param string $source
	 *
	 * @throws KnownFileSourceException
	 */
	public function getFileSource(Node $file, &$source) {
		if ($this->folderManager === null) {
			return;
		}

		try {
			$this->getMountPoint($file);
		} catch (FileIsNotIndexableException $e) {
			return;
		}

		$source = ConfigService::FILES_GROUP_FOLDERS;
		throw new KnownFileSourceException();
	}


	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 */
	public function updateDocumentAccess(FilesDocument &$document, Node $file) {

		if ($document->getSource() !== ConfigService::FILES_GROUP_FOLDERS) {
			return;
		}

		try {
			$mount = $this->getMountPoint($file);
		} catch (FileIsNotIndexableException $e) {
			return;
		}


		$access = $document->getAccess();
		$access->addGroups($mount->getGroups());

		$document->getIndex()
				 ->addOption('group_folder_id', $mount->getId());
		$document->setAccess($access);
	}


	/**
	 * @param FilesDocument $document
	 * @param array $users
	 */
	public function getShareUsers(FilesDocument $document, &$users) {
		if ($document->getSource() !== ConfigService::FILES_GROUP_FOLDERS) {
			return;
		}

		$this->localFilesService->getSharedUsersFromAccess($document->getAccess(), $users);
	}


	/**
	 * @param Node $file
	 *
	 * @return MountPoint
	 * @throws FileIsNotIndexableException
	 */
	private function getMountPoint(Node $file) {
		foreach ($this->groupFolders as $mount) {
			if (strpos($file->getPath(), $mount->getPath()) === 0) {
				return $mount;
			}
		}

		throw new FileIsNotIndexableException();

	}


	/**
	 * @param string $userId
	 *
	 * @return MountPoint[]
	 */
	private function getMountPoints($userId) {

		$mountPoints = [];
		$mounts = $this->folderManager->getAllFolders();

		foreach ($mounts as $path => $mount) {
			$mountPoint = new MountPoint();
			$mountPoint->setId($mount['id'])
					   ->setPath('/' . $userId . '/files/' . $mount['mount_point'])
					   ->setGroups(array_keys($mount['groups']));
			$mountPoints[] = $mountPoint;
		}

		return $mountPoints;
	}


	/**
	 * @param Index $index
	 *
	 * @return string|void
	 */
	public function impersonateOwner(Index $index) {
		if ($index->getSource() !== ConfigService::FILES_GROUP_FOLDERS) {
			return;
		}

		if ($this->folderManager === null) {
			return;
		}

		$groupFolderId = $index->getOption('group_folder_id', 0);
		try {
			$mount = $this->getGroupFolderById($groupFolderId);
		} catch (GroupFolderNotFoundException $e) {
			return;
		}

		$index->setOwnerId($this->getRandomUserFromGroups(array_keys($mount['groups'])));
	}


	/**
	 * @param int $groupFolderId
	 *
	 * @return array
	 * @throws GroupFolderNotFoundException
	 */
	private function getGroupFolderById($groupFolderId) {
		if ($groupFolderId === 0) {
			throw new GroupFolderNotFoundException();
		}

		$mounts = $this->folderManager->getAllFolders();
		foreach ($mounts as $path => $mount) {
			if ($mount['id'] === $groupFolderId) {
				return $mount;
			}
		}

		throw new GroupFolderNotFoundException();
	}


	/**
	 * @param array $groups
	 *
	 * @return string
	 */
	private function getRandomUserFromGroups($groups) {
		foreach ($groups as $groupName) {
			$group = $this->groupManager->get($groupName);
			$users = $group->getUsers();
			if (sizeof($users) > 0) {
				return array_keys($users)[0];
			}
		}

		return '';
	}

}