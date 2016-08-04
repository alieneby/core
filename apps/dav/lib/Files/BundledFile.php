<?php
/**
 * @author Piotr Mrowczynski <Piotr.Mrowczynski@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
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

namespace OCA\DAV\Files;

use OCA\DAV\Connector\Sabre\Exception\FileLocked;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\ServiceUnavailable;
use OCA\DAV\Connector\Sabre\File;

class BundledFile extends File {

	/**
	 * Creates the data
	 *
	 * The data argument is a readable stream
	 *
	 * @param resource $fileData
	 * @param array $fileAttributes
	 *
	 * @throws TODO
	 * @return Array $property
	 */
	public function createFile($fileData, $fileAttributes) {
		if (!is_resource($fileData)){
			throw new Forbidden('Function BundledFile->createFile received wrong argument');
		}

		$exists = $this->fileView->file_exists($this->path);
		if ($this->info && $exists) {
			throw new Forbidden('File does exists, cannot create file');
		}

		// verify path of the target
		$this->verifyPath();

		/** @var \OC\Files\Storage\Storage $storage */
		list($storage, $internalPath) = $this->fileView->resolvePath($this->path);

		try {
			$view = \OC\Files\Filesystem::getView();
			if ($view) {
				$run = $this->emitPreHooks($exists);
			} else {
				$run = true;
			}

			try {
				$this->changeLock(ILockingProvider::LOCK_EXCLUSIVE);
			} catch (LockedException $e) {
				throw new FileLocked($e->getMessage(), $e->getCode(), $e);
			}


			try {
				$target = $storage->fopen($internalPath, 'wb');
				if ($target === false) {
					// because we have no clue about the cause we can only throw back a 500/Internal Server Error
					\OCP\Util::writeLog('webdav', '\OC\Files\Filesystem::fopen() failed', \OCP\Util::ERROR);
					throw new Exception('Could not write file contents');
				}

				//will get the current pointer of written data. Should be at the end representing length of the stream
				$streamLength = fstat($fileData)['size'];

				//rewind to the begining of file for streamCopy and copy stream
				//you dont need to close the $stream since other files might use the stream resource. 
				rewind($fileData);
				list($count, $result) = \OC_Helper::streamCopy($fileData, $target);
				fclose($target);

				if ($result === false) {
					throw new Exception('Error while copying file to target location (copied bytes: ' . $count . 'expected filesize ' . $streamLength . ')');
				}

				// if content length is sent by client:
				// double check if the file was fully received
				// compare expected and actual size
				if ($streamLength != $count) {
					throw new BadRequest('expected filesize ' . $streamLength . ' got ' . $count);
				}

			} catch (\Exception $e) {
				$storage->unlink($internalPath);
				throw new Exception($e->getMessage());
			}

			// since we skipped the view we need to scan and emit the hooks ourselves
			$storage->getUpdater()->update($internalPath);

			try {
				$this->changeLock(ILockingProvider::LOCK_SHARED);
			} catch (LockedException $e) {
				throw new FileLocked($e->getMessage(), $e->getCode(), $e);
			}

			if ($view) {
				$this->emitPostHooks($exists);
			}

			// allow sync clients to send the mtime along in a header
			if (isset($fileAttributes['x-oc-mtime'])) {
				if ($this->fileView->touch($this->path, $fileAttributes['x-oc-mtime'])) {
					$property['{DAV:}x-oc-mtime'] = 'accepted'; //TODO: not sure about that
				}
			}

			$this->refreshInfo();

		} catch (StorageNotAvailableException $e) {
			throw new ServiceUnavailable("Failed to check file size: " . $e->getMessage());
		}

		//TODO add proper attributes
		$etag = '"' . $this->getEtag() . '"';
		$property['{DAV:}etag'] = $etag; //TODO: not sure about that
		$property['{DAV:}oc-etag'] = $etag; //TODO: not sure about that
		$property['{DAV:}oc-fileid'] = $this->getFileId();//TODO: not sure about that
		return $property;
	}

	/**
	 *
	 * TODO: description
	 *
	 * @param resource $data
	 *
	 * @throws Forbidden
	 */
	public function put($data) {
		throw new Forbidden('PUT method not supported for bundling');
	}
}