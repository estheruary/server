<?php
/**
 *
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
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
namespace OCA\DAV\CardDAV;

use OCP\Files\IAppData;
use OCP\ILogger;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Sabre\CardDAV\Card;
use Sabre\VObject\Property\Binary;
use Sabre\VObject\Reader;

class PhotoCache {

	/** @var IAppData */
	protected $appData;

	/** @var ILogger */
	protected $logger;

	/**
	 * PhotoCache constructor.
	 *
	 * @param IAppData $appData
	 * @param ILogger $logger
	 */
	public function __construct(IAppData $appData, ILogger $logger) {
		$this->appData = $appData;
		$this->logger = $logger;
	}

	/**
	 * @param int $addressBookId
	 * @param string $cardUri
	 * @param int $size
	 * @param Card $card
	 *
	 * @return ISimpleFile
	 * @throws NotFoundException
	 */
	public function get($addressBookId, $cardUri, $size, Card $card) {
		$folder = $this->getFolder($addressBookId, $cardUri);

		if ($this->isEmpty($folder)) {
			$this->init($folder, $card);
		}

		if (!$this->hasPhoto($folder)) {
			throw new NotFoundException();
		}

		if ($size !== -1) {
			$size = 2 ** ceil(log($size) / log(2));
		}

		return $this->getFile($folder, $size);
	}

	/**
	 * @param ISimpleFolder $folder
	 * @return bool
	 */
	private function isEmpty(ISimpleFolder $folder) {
		return $folder->getDirectoryListing() === [];
	}

	/**
	 * @param ISimpleFolder $folder
	 * @param Card $card
	 */
	private function init(ISimpleFolder $folder, Card $card) {
		$data = $this->getPhoto($card);

		if ($data === false) {
			$folder->newFile('nophoto');
		} else {
			switch ($data['Content-Type']) {
				case 'image/png':
					$ext = 'png';
					break;
				case 'image/jpeg':
					$ext = 'jpg';
					break;
				case 'image/gif':
					$ext = 'gif';
					break;
			}
			$file = $folder->newFile('photo.' . $ext);
			$file->putContent($data['body']);
		}
	}

	private function hasPhoto(ISimpleFolder $folder) {
		return !$folder->fileExists('nophoto');
	}

	private function getFile(ISimpleFolder $folder, $size) {
		$ext = $this->getExtension($folder);

		if ($size === -1) {
			$path = 'photo.' . $ext;
		} else {
			$path = 'photo.' . $size . '.' . $ext;
		}

		try {
			$file = $folder->getFile($path);
		} catch (NotFoundException $e) {
			if ($size <= 0) {
				throw new NotFoundException;
			}

			$photo = new \OC_Image();
			/** @var ISimpleFile $file */
			$file = $folder->getFile('photo.' . $ext);
			$photo->loadFromData($file->getContent());

			$ratio = $photo->width() / $photo->height();
			if ($ratio < 1) {
				$ratio = 1 / $ratio;
			}

			$size = (int) ($size * $ratio);
			if ($size !== -1) {
				$photo->resize($size);
			}
	
			try {
				$file = $folder->newFile($path);
				$file->putContent($photo->data());
			} catch (NotPermittedException $e) {

			}
		}

		return $file;
	}

	/**
	 * @param int $addressBookId
	 * @param string $cardUri
	 * @return ISimpleFolder
	 */
	private function getFolder($addressBookId, $cardUri) {
		$hash = md5($addressBookId . ' ' . $cardUri);
		try {
			return $this->appData->getFolder($hash);
		} catch (NotFoundException $e) {
			return $this->appData->newFolder($hash);
		}
	}

	/**
	 * Get the extension of the avatar. If there is no avatar throw Exception
	 *
	 * @param ISimpleFolder $folder
	 * @return string
	 * @throws NotFoundException
	 */
	private function getExtension(ISimpleFolder $folder) {
		if ($folder->fileExists('photo.jpg')) {
			return 'jpg';
		} elseif ($folder->fileExists('photo.png')) {
			return 'png';
		} elseif ($folder->fileExists('photo.gif')) {
			return 'gif';
		}
		throw new NotFoundException;
	}

	private function getPhoto(Card $node) {
		try {
			$vObject = $this->readCard($node->get());
			if (!$vObject->PHOTO) {
				return false;
			}

			$photo = $vObject->PHOTO;

			// handle data URI. e.g PHOTO;VALUE=URI:data:image/jpeg;base64,/9j/4AAQSkZJRgABAQE
			if ($photo->getValueType() === 'URI') {
				$parsed = \Sabre\URI\parse($val);

				// only allow data://
				if ($parsed['scheme'] !== 'data') {
					return false;
				}
				if (substr_count($parsed['path'], ';') === 1) {
					list($type) = explode(';', $parsed['path']);
				}
				$val = file_get_contents($val);
			} else {
				// get type if binary data
				$type = $this->getBinaryType($photo);
				$val = $photo->getValue();
			}

			$allowedContentTypes = [
				'image/png',
				'image/jpeg',
				'image/gif',
			];

			if (!in_array($type, $allowedContentTypes, true)) {
				$type = 'application/octet-stream';
			}

			return [
				'Content-Type' => $type,
				'body'         => $val
			];
		} catch (\Exception $e) {
			$this->logger->logException($ex, [
				'message' => 'Exception during vcard photo parsing'
			]);
		}
		return false;
	}

	/**
	 * @param string $cardData
	 * @return \Sabre\VObject\Document
	 */
	private function readCard($cardData) {
		return Reader::read($cardData);
	}

	/**
	 * @param Binary $photo
	 * @return string
	 */
	private function getBinaryType(Binary $photo) {
		$params = $photo->parameters();
		if (isset($params['TYPE']) || isset($params['MEDIATYPE'])) {
			/** @var Parameter $typeParam */
			$typeParam = isset($params['TYPE']) ? $params['TYPE'] : $params['MEDIATYPE'];
			$type = $typeParam->getValue();

			if (strpos($type, 'image/') === 0) {
				return $type;
			} else {
				return 'image/' . strtolower($type);
			}
		}
		return '';
	}

	/**
	 * @param int $addressBookId
	 * @param string $cardUri
	 */
	public function delete($addressBookId, $cardUri) {
		$folder = $this->getFolder($addressBookId, $cardUri);
		$folder->delete();
	}
}
