<?php

declare(strict_types=1);

namespace OCA\Forum\Tests\Controller;

use OCA\Forum\Controller\UserPreferencesController;
use OCA\Forum\Service\UserPreferencesService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserPreferencesControllerTest extends TestCase {
	private UserPreferencesController $controller;

	/** @var UserPreferencesService&MockObject */
	private UserPreferencesService $preferencesService;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->preferencesService = $this->createMock(UserPreferencesService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new UserPreferencesController(
			'forum',
			$this->request,
			$this->preferencesService,
			$this->userSession,
			$this->logger,
		);
	}

	private function mockUser(string $userId = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testUpdateStripsDerivedAndFrameworkParams(): void {
		$this->mockUser('alice');

		// Simulate the frontend round-tripping the entire preferences object
		// (including the derived `upload_directory_resolved_path`) plus the
		// OCS `_route` framework param.
		$this->request->method('getParams')->willReturn([
			'_route' => 'ocs.forum.userpreferences.update',
			'auto_subscribe_created_threads' => false,
			'upload_directory_resolved_path' => 'Forum/Resolved',
			'use_category_upload_path' => true,
		]);

		$this->preferencesService->expects($this->once())
			->method('updatePreferences')
			->with('alice', [
				'auto_subscribe_created_threads' => false,
				'use_category_upload_path' => true,
			])
			->willReturn(['ok' => true]);

		$response = $this->controller->update();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->update();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testUpdateReturns400OnInvalidArgument(): void {
		$this->mockUser('alice');
		$this->request->method('getParams')->willReturn([
			'auto_subscribe_created_threads' => 'not-a-bool',
		]);

		$this->preferencesService->method('updatePreferences')
			->willThrowException(new \InvalidArgumentException('bad value'));

		$response = $this->controller->update();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * Round-trip invariant: anything the GET endpoint returns must be safely
	 * PUT-able. The frontend round-trips the full preferences object, so any
	 * derived/read-only field added to `getAllPreferences()` in the future
	 * must either be in VALID_KEYS or be silently dropped by the controller.
	 *
	 * Regression test for https://github.com/chenasraf/nextcloud-forum/issues/281.
	 */
	public function testGetResponseCanBeRoundTrippedToPut(): void {
		$this->mockUser('alice');

		// Simulated GET response — every key the service might ever emit,
		// including derived/read-only fields.
		$getResponse = [
			'auto_subscribe_created_threads' => true,
			'auto_subscribe_replied_threads' => false,
			'upload_directory' => 'Forum',
			'upload_directory_folder_id' => null,
			'signature' => '',
			'hide_edit_history' => false,
			'use_category_upload_path' => true,
			'upload_behavior' => 'configured',
			// Derived, server-computed, not in VALID_KEYS:
			'upload_directory_resolved_path' => 'Forum',
		];

		// Plus the OCS framework params the request will carry.
		$requestBody = $getResponse + [
			'_route' => 'ocs.forum.userpreferences.update',
			'format' => 'json',
		];
		$this->request->method('getParams')->willReturn($requestBody);

		// The service must only receive keys it knows about.
		$this->preferencesService->expects($this->once())
			->method('updatePreferences')
			->with(
				'alice',
				$this->callback(function (array $prefs): bool {
					foreach (array_keys($prefs) as $key) {
						if (!in_array($key, UserPreferencesService::VALID_KEYS, true)) {
							return false;
						}
					}
					return true;
				}),
			)
			->willReturn($getResponse);

		$response = $this->controller->update();
		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'Round-tripping the GET response through PUT must not 400. '
			. 'A new derived field was likely added to getAllPreferences() '
			. 'without being filtered in UserPreferencesController::update().',
		);
	}
}
