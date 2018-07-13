<?php
/**
 * @author Ilja Neumann <ineumann@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
namespace OC\Files\Storage\Wrapper;

/**
 * Class Checksum
 *
 * Computes checksums (default: SHA1, MD5, ADLER32) on all files under the /files path.
 * The resulting checksum can be retrieved by call getMetadata($path)
 *
 * If a file is read and has no checksum oc_filecache gets updated accordingly.
 *
 *
 * @package OC\Files\Storage\Wrapper
 */
class Checksum extends Wrapper {

	/** Format of checksum field in filecache */
	const CHECKSUMS_DB_FORMAT = 'SHA1:%s MD5:%s ADLER32:%s';

	const NOT_REQUIRED = 0;
	/** Calculate checksum on write (to be stored in oc_filecache) */
	const PATH_NEW_OR_UPDATED = 1;
	/** File needs to be checksummed on first read because it is already in cache but has no checksum */
	const PATH_IN_CACHE_WITHOUT_CHECKSUM = 2;

	/** @var array */
	private $checksums;

	/**
	 * @param $path
	 * @return string Format like "SHA1:abc MD5:def ADLER32:ghi"
	 */
	private function getChecksumsInDbFormat($path) {
		if (empty($this->checksums[$path])) {
			return '';
		}

		return \sprintf(
			self::CHECKSUMS_DB_FORMAT,
			$this->checksums[$path]->sha1,
			$this->checksums[$path]->md5,
			$this->checksums[$path]->adler32
		);
	}

	/**
	 * check if the file metadata should not be fetched
	 * NOTE: files with a '.part' extension are ignored as well!
	 *       prevents unfinished put requests to fetch metadata which does not exists
	 *
	 * @param string $file
	 * @return boolean
	 */
	public static function isPartialFile($file) {
		if (\pathinfo($file, PATHINFO_EXTENSION) === 'part') {
			return true;
		}

		return false;
	}

	/**
	 * @param string $path
	 * @param resource $data
	 * @return bool
	 */
	public function file_put_contents($path, $data) {
		if (!\is_resource($data)) {
			throw new \InvalidArgumentException();
		}

		$this->checksums[$path] = new \stdClass();
		// TODO: perform register more globally
		\stream_filter_register('oc.checksum', ChecksumFilter::class);
		\stream_filter_append($data, 'oc.checksum', STREAM_FILTER_READ, $this->checksums[$path]);

		$return = $this->getWrapperStorage()->file_put_contents($path, $data);
		\fclose($data);
		return $return;
	}

	/**
	 * @param string $path
	 * @return array
	 */
	public function getMetaData($path) {
		// Check if it is partial file. Partial file metadata are only checksums
		$parentMetaData = [];
		if (!self::isPartialFile($path)) {
			$parentMetaData = $this->getWrapperStorage()->getMetaData($path);
			// can be null if entry does not exist
			if ($parentMetaData === null) {
				return null;
			}
		}
		$parentMetaData['checksum'] = $this->getChecksumsInDbFormat($path);

		if (!isset($parentMetaData['mimetype'])) {
			$parentMetaData['mimetype'] = 'application/octet-stream';
		}

		return $parentMetaData;
	}
}
