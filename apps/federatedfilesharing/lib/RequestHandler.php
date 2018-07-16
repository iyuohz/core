<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OCA\FederatedFileSharing;

use OC\OCS\Result;
use OCA\FederatedFileSharing\Exception\InvalidShareException;
use OCA\FederatedFileSharing\Exception\NotSupportedException;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Share;
use OCP\Share\IShare;

/**
 * Class RequestHandler
 *
 * Handles OCS Request to the federated share API
 *
 * @package OCA\FederatedFileSharing\API
 */
class RequestHandler {

	/** @var FederatedShareProvider */
	private $federatedShareProvider;

	/** @var IDBConnection */
	private $connection;

	/** @var IAppManager */
	private $appManager;

	/** @var IUserManager */
	private $userManager;

	/** @var IRequest */
	private $request;

	/** @var Notifications */
	private $notifications;

	/** @var AddressHandler */
	private $addressHandler;

	/** @var  FedShareManager */
	private $fedShareManager;

	/**
	 * Server2Server constructor.
	 *
	 * @param FederatedShareProvider $federatedShareProvider
	 * @param IDBConnection $connection
	 * @param IAppManager $appManager
	 * @param IRequest $request
	 * @param Notifications $notifications
	 * @param AddressHandler $addressHandler
	 * @param FedShareManager $fedShareManager
	 */
	public function __construct(FederatedShareProvider $federatedShareProvider,
								IDBConnection $connection,
								IAppManager $appManager,
								IUserManager $userManager,
								IRequest $request,
								Notifications $notifications,
								AddressHandler $addressHandler,
								FedShareManager $fedShareManager
	) {
		$this->federatedShareProvider = $federatedShareProvider;
		$this->connection = $connection;
		$this->appManager = $appManager;
		$this->userManager = $userManager;
		$this->request = $request;
		$this->notifications = $notifications;
		$this->addressHandler = $addressHandler;
		$this->fedShareManager = $fedShareManager;
	}

	/**
	 * Create a new incoming share
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function createShare($params) {
		try {
			$this->assertIncomingSharingEnabled();

			$remote = $this->request->getParam('remote', null);
			$token = $this->request->getParam('token', null);
			$name = $this->request->getParam('name', null);
			$owner = $this->request->getParam('owner', null);
			$sharedBy = $this->request->getParam('sharedBy', null);
			$shareWith = $this->request->getParam('shareWith', null);
			$remoteId = $this->request->getParam('remoteId', null);
			$sharedByFederatedId = $this->request->getParam(
				'sharedByFederatedId',
				null
			);
			$ownerFederatedId = $this->request->getParam('ownerFederatedId', null);

			if ($this->hasNull([$remote, $token, $name, $owner, $remoteId, $shareWith])) {
				throw new InvalidShareException(
					'server can not add remote share, missing parameter'
				);
			}

			if (!\OCP\Util::isValidFileName($name)) {
				throw new InvalidShareException(
					'The mountpoint name contains invalid characters.'
				);
			}

			// FIXME this should be a method in the user management instead
			\OCP\Util::writeLog('files_sharing', 'shareWith before, ' . $shareWith, \OCP\Util::DEBUG);
			\OCP\Util::emitHook(
				'\OCA\Files_Sharing\API\Server2Server',
				'preLoginNameUsedAsUserName',
				['uid' => &$shareWith]
			);
			\OCP\Util::writeLog('files_sharing', 'shareWith after, ' . $shareWith, \OCP\Util::DEBUG);

			if (!$this->userManager->userExists($shareWith)) {
				throw new InvalidShareException('User does not exist');
			}

			$this->fedShareManager->createShare(
				$shareWith,
				$remote,
				$remoteId,
				$owner,
				$name,
				$ownerFederatedId,
				$sharedByFederatedId,
				$sharedBy,
				$token
			);
		} catch (InvalidShareException $e) {
			return new Result(
				null,
				Http::STATUS_BAD_REQUEST,
				$e->getMessage()
			);
		} catch (NotSupportedException $e) {
			return new Result(
				null,
				Http::STATUS_SERVICE_UNAVAILABLE,
				'Server does not support federated cloud sharing'
			);
		} catch (\Exception $e) {
			\OCP\Util::writeLog(
				'files_sharing',
				'server can not add remote share, ' . $e->getMessage(),
				\OCP\Util::ERROR
			);
			return new Result(
				null,
				Http::STATUS_INTERNAL_SERVER_ERROR,
				'internal server error, was not able to add share from ' . $remote
			);
		}

		return new Result();
	}

	/**
	 * Create re-share on behalf of another user
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function reShare($params) {
		$id = isset($params['id']) ? (int)$params['id'] : null;
		$token = $this->request->getParam('token', null);
		$shareWith = $this->request->getParam('shareWith', null);
		$permission = $this->request->getParam('permission', null);
		$remoteId = $this->request->getParam('remoteId', null);

		try {
			if ($this->hasNull([$id, $token, $shareWith, $permission, $remoteId])) {
				throw new \Exception();
			}
			$permission = (int) $permission;
			$remoteId = (int) $remoteId;
			$share = $this->getValidShare($id);

			// don't allow to share a file back to the owner
			list($user, $remote) = $this->addressHandler->splitUserRemote($shareWith);
			$owner = $share->getShareOwner();
			$currentServer = $this->addressHandler->generateRemoteURL();
			if ($this->addressHandler->compareAddresses($user, $remote, $owner, $currentServer)) {
				throw new InvalidShareException();
			}

			$reSharingAllowed = $share->getPermissions() & Constants::PERMISSION_SHARE;
			if (!$reSharingAllowed) {
				throw new \Exception();
			}
			$result = $this->fedShareManager->reShare(
				$share,
				$remoteId,
				$shareWith,
				$permission
			);
		} catch (Share\Exceptions\ShareNotFound $e) {
			return new Result(null, Http::STATUS_NOT_FOUND);
		} catch (InvalidShareException $e) {
			return new Result(null, Http::STATUS_FORBIDDEN);
		} catch (\Exception $e) {
			return new Result(null, Http::STATUS_BAD_REQUEST);
		}

		return new Result(
			[
				'token' => $result->getToken(),
				'remoteId' => $result->getId()
			]
		);
	}

	/**
	 * Accept server-to-server share
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function acceptShare($params) {
		try {
			$this->assertOutgoingSharingEnabled();

			$id = (int)$params['id'];
			$share = $this->getValidShare($id);
			$this->fedShareManager->acceptShare($share);
			if ($share->getShareOwner() !== $share->getSharedBy()) {
				list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedBy());
				$remoteId = $this->federatedShareProvider->getRemoteId($share);
				$this->notifications->sendAcceptShare($remote, $remoteId, $share->getToken());
			}
		} catch (NotSupportedException $e) {
			return new Result(
				null,
				Http::STATUS_SERVICE_UNAVAILABLE,
				'Server does not support federated cloud sharing'
			);
		} catch (\Exception $e) {
			// pass
		}
		return new Result();
	}

	/**
	 * Decline server-to-server share
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function declineShare($params) {
		try {
			$this->assertOutgoingSharingEnabled();

			$id = (int)$params['id'];
			$share = $this->getValidShare($id);
			if ($share->getShareOwner() !== $share->getSharedBy()) {
				list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedBy());
				$remoteId = $this->federatedShareProvider->getRemoteId($share);
				$this->notifications->sendDeclineShare($remote, $remoteId, $share->getToken());
			}
			$this->fedShareManager->declineShare($share);
		} catch (NotSupportedException $e) {
			return new Result(
				null,
				Http::STATUS_SERVICE_UNAVAILABLE,
				'Server does not support federated cloud sharing'
			);
		} catch (\Exception $e) {
			// pass
		}

		return new Result();
	}

	/**
	 * Remove server-to-server share if it was unshared by the owner
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function unshare($params) {
		try {
			$this->assertOutgoingSharingEnabled();

			$id = $params['id'];
			$token = isset($_POST['token']) ? $_POST['token'] : null;

			$query = $this->connection->getQueryBuilder();
			$query->select('*')->from('share_external')
				->where(
					$query->expr()->eq(
						'remote_id', $query->createNamedParameter($id)
					)
				)
				->andWhere(
					$query->expr()->eq(
						'share_token',
						$query->createNamedParameter($token)
					)
				);
			$shareRow = $query->execute()->fetch();

			if ($token && $id && $shareRow !== false) {
				$this->fedShareManager->unshare($shareRow);
			}
		} catch (NotSupportedException $e) {
			return new Result(
				null,
				Http::STATUS_SERVICE_UNAVAILABLE,
				'Server does not support federated cloud sharing'
			);
		} catch (\Exception $e) {
			// pass
		}

		return new Result();
	}

	/**
	 * Federated share was revoked, either by the owner or the re-sharer
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function revoke($params) {
		try {
			$id = (int)$params['id'];
			$share = $this->getValidShare($id);
			$this->fedShareManager->revoke($share);
		} catch (\Exception $e) {
			return new Result(null, Http::STATUS_BAD_REQUEST);
		}

		return new Result();
	}

	/**
	 * Update share information to keep federated re-shares in sync
	 *
	 * @param array $params
	 *
	 * @return Result
	 */
	public function updatePermissions($params) {
		try {
			$id = (int)$params['id'];
			$permissions = $this->request->getParam('permissions', null);

			$share = $this->getValidShare($id);
			$validPermission = \ctype_digit($permissions);
			if (!$validPermission) {
				throw new \Exception();
			}
			$this->fedShareManager->updatePermissions($share, (int)$permissions);
		} catch (\Exception $e) {
			return new Result(null, Http::STATUS_BAD_REQUEST);
		}

		return new Result();
	}

	/**
	 * Check if value is null or an array has any null item
	 *
	 * @param mixed $param
	 *
	 * @return bool
	 */
	protected function hasNull($param) {
		if (\is_array($param)) {
			return \in_array(null, $param, true);
		} else {
			return $param === null;
		}
	}

	/**
	 * Get share by id, validate it's type and token
	 *
	 * @param int $id
	 *
	 * @return IShare
	 *
	 * @throws Share\Exceptions\ShareNotFound
	 * @throws InvalidShareException
	 */
	protected function getValidShare($id) {
		$share = $this->federatedShareProvider->getShareById($id);
		$token = $this->request->getParam('token', null);
		if ($share->getShareType() !== FederatedShareProvider::SHARE_TYPE_REMOTE
			|| $share->getToken() !== $token
		) {
			throw new InvalidShareException();
		}
		return $share;
	}

	/**
	 * Make sure that incoming shares are supported
	 *
	 * @return void
	 *
	 * @throws NotSupportedException
	 */
	protected function assertIncomingSharingEnabled() {
		if (!$this->appManager->isEnabledForUser('files_sharing')
			|| !$this->federatedShareProvider->isIncomingServer2serverShareEnabled()
		) {
			throw new NotSupportedException();
		}
	}

	/**
	 * Make sure that outgoing shares are supported
	 *
	 * @return void
	 *
	 * @throws NotSupportedException
	 */
	protected function assertOutgoingSharingEnabled() {
		if (!$this->appManager->isEnabledForUser('files_sharing')
			|| !$this->federatedShareProvider->isOutgoingServer2serverShareEnabled()
		) {
			throw new NotSupportedException();
		}
	}
}
