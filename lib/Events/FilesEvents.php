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

namespace OCA\Files_FullTextSearch\Events;

use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Service\FilesService;
use OCA\Files_FullTextSearch\Service\MiscService;
use OCA\FullTextSearch\Api\v1\FullTextSearch;
use OCA\FullTextSearch\Model\Index;
use OCP\AppFramework\QueryException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;

class FilesEvents {


	/** @var string */
	private $userId;

	/** @var FilesService */
	private $filesService;

	/** @var MiscService */
	private $miscService;

	/**
	 * FilesEvents constructor.
	 *
	 * @param string $userId
	 * @param FilesService $filesService
	 * @param MiscService $miscService
	 */
	public function __construct($userId, FilesService $filesService, MiscService $miscService) {

		$this->userId = $userId;
		$this->filesService = $filesService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $path
	 *
	 * @throws QueryException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function onNewFile($path) {
		$file = $this->filesService->getFileFromPath($this->userId, $path);
		FullTextSearch::createIndex('files', $file->getId(), $this->userId, Index::INDEX_FULL);
	}


	/**
	 * @param string $path
	 *
	 * @throws QueryException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function onFileUpdate($path) {
		$file = $this->filesService->getFileFromPath($this->userId, $path);
		FullTextSearch::updateIndexStatus('files', $file->getId(), Index::INDEX_FULL);
	}


	/**
	 * @param string $target
	 *
	 * @throws NotFoundException
	 * @throws QueryException
	 * @throws InvalidPathException
	 */
	public function onFileRename($target) {
		$file = $this->filesService->getFileFromPath($this->userId, $target);
		FullTextSearch::updateIndexStatus('files', $file->getId(), Index::INDEX_META);
	}


	/**
	 * @param string $path
	 *
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws QueryException
	 */
	public function onFileTrash($path) {
		// check if trashbin does not exist. -> onFileDelete
		// we do not index trashbin

		$file = $this->filesService->getFileFromPath($this->userId, $path);
		FullTextSearch::updateIndexStatus('files', $file->getId(), Index::INDEX_REMOVE, true);

		//$this->miscService->log('> ON FILE TRASH ' . json_encode($path));
	}


	/**
	 * @param string $path
	 *
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws QueryException
	 */
	public function onFileRestore($path) {
		$file = $this->filesService->getFileFromPath($this->userId, $path);
		FullTextSearch::updateIndexStatus('files', $file->getId(), Index::INDEX_FULL);
	}


	/**
	 * @param string $path
	 */
	public function onFileDelete($path) {
//		$file = $this->filesService->getFileFromPath($this->userId, $path);
//		FullTextSearch::updateIndexStatus('files', $file->getId(), Index::INDEX_REMOVE);
	}


	/**
	 * @param string $fileId
	 *
	 * @throws QueryException
	 */
	public function onFileShare($fileId) {
		FullTextSearch::updateIndexStatus('files', $fileId, FilesDocument::STATUS_FILE_ACCESS);

	}


	/**
	 * @param string $fileId
	 *
	 * @throws QueryException
	 */
	public function onFileUnshare($fileId) {
		FullTextSearch::updateIndexStatus('files', $fileId, FilesDocument::STATUS_FILE_ACCESS);
	}
}




