<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Lfs\Application\LockService;
use App\Lfs\Domain\Model\LfsLock;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LfsLockController
{
    public function __construct(
        private LockService $lockService,
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private RepoStore $repoStore,
        private ServerSettings $settings,
        private Security $security,
    ) {
    }

    #[Route('/{repo}.git/info/lfs/locks', requirements: ['repo' => '.+?'], methods: ['GET'])]
    #[Route('/{repo}/info/lfs/locks', requirements: ['repo' => '.+?'], methods: ['GET'])]
    public function listLocks(Request $request, string $repo): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/locks', 'GET', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        if (!$this->isLfs($request)) {
            return new Response('', Response::HTTP_NOT_ACCEPTABLE);
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $path = $request->query->get('path');
        $id = $request->query->has('id') ? $request->query->getInt('id') : null;
        $cursor = $request->query->getInt('cursor', 1);
        $limit = $request->query->getInt('limit', LockService::DEFAULT_LIMIT);

        $result = $this->lockService->list($repoEntity, $path, $id, $cursor, $limit);

        $locks = array_map($this->formatLock(...), $result['locks']);
        $resp = ['locks' => $locks];
        if (null !== $result['next_cursor']) {
            $resp['next_cursor'] = $result['next_cursor'];
        }

        return new JsonResponse($resp, Response::HTTP_OK, ['Content-Type' => GitResponder::LFS_JSON]);
    }

    #[Route('/{repo}.git/info/lfs/locks', requirements: ['repo' => '.+?'], methods: ['POST'])]
    #[Route('/{repo}/info/lfs/locks', requirements: ['repo' => '.+?'], methods: ['POST'])]
    public function createLock(Request $request, string $repo): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/locks', 'POST', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        if (!$this->isLfs($request)) {
            return new Response('', Response::HTTP_NOT_ACCEPTABLE);
        }

        if (null === $user) {
            throw new AccessDenied('unauthorized');
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['path']) || !\is_string($data['path'])) {
            throw new InvalidInput('invalid request');
        }

        $refName = isset($data['ref']) && \is_array($data['ref']) && isset($data['ref']['name']) && \is_string($data['ref']['name']) ? $data['ref']['name'] : null;
        $lock = $this->lockService->create($user, $repoEntity, $data['path'], $refName);

        return new JsonResponse(
            ['lock' => $this->formatLock($lock)],
            Response::HTTP_CREATED,
            ['Content-Type' => GitResponder::LFS_JSON],
        );
    }

    #[Route('/{repo}.git/info/lfs/locks/verify', requirements: ['repo' => '.+?'], methods: ['POST'])]
    #[Route('/{repo}/info/lfs/locks/verify', requirements: ['repo' => '.+?'], methods: ['POST'])]
    public function verifyLocks(Request $request, string $repo): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/locks/verify', 'POST', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        if (!$this->isLfs($request)) {
            return new Response('', Response::HTTP_NOT_ACCEPTABLE);
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $raw = json_decode($request->getContent(), true);
        $data = \is_array($raw) ? $raw : [];
        $cursor = isset($data['cursor']) && is_numeric($data['cursor']) ? (int) $data['cursor'] : 1;
        $limit = isset($data['limit']) && is_numeric($data['limit']) ? (int) $data['limit'] : LockService::DEFAULT_LIMIT;

        $result = $this->lockService->verify($repoEntity, $user, $cursor, $limit);

        $ours = array_map($this->formatLock(...), $result['ours']);
        $theirs = array_map($this->formatLock(...), $result['theirs']);

        $resp = [
            'ours' => $ours,
            'theirs' => $theirs,
        ];
        if (null !== $result['next_cursor']) {
            $resp['next_cursor'] = $result['next_cursor'];
        }

        return new JsonResponse($resp, Response::HTTP_OK, ['Content-Type' => GitResponder::LFS_JSON]);
    }

    #[Route('/{repo}.git/info/lfs/locks/{id}/unlock', requirements: ['repo' => '.+?', 'id' => '[0-9]+'], methods: ['POST'])]
    #[Route('/{repo}/info/lfs/locks/{id}/unlock', requirements: ['repo' => '.+?', 'id' => '[0-9]+'], methods: ['POST'])]
    public function unlock(Request $request, string $repo, string $id): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/locks/'.$id.'/unlock', 'POST', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        if (!$this->isLfs($request)) {
            return new Response('', Response::HTTP_NOT_ACCEPTABLE);
        }

        if (null === $user) {
            throw new AccessDenied('unauthorized');
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }
        $raw = json_decode($request->getContent(), true);
        $data = \is_array($raw) ? $raw : [];
        $force = isset($data['force']) && true === $data['force'];

        $lock = $this->lockService->unlock($user, $repoEntity, (int) $id, $force);

        $data = $this->formatLock($lock, (int) $id);

        return new JsonResponse(
            ['lock' => $data],
            Response::HTTP_OK,
            ['Content-Type' => GitResponder::LFS_JSON],
        );
    }

    /**
     * @return array{id: string, path: string, locked_at: string, owner: array{name: string}}
     */
    private function formatLock(LfsLock $lock, ?int $fallbackId = null): array
    {
        try {
            $id = $lock->id();
        } catch (\LogicException) {
            $id = $fallbackId ?? 0;
        }

        return [
            'id' => (string) $id,
            'path' => $lock->path(),
            'locked_at' => $lock->createdAt()->format(\DateTimeInterface::ATOM),
            'owner' => [
                'name' => $lock->owner()->username(),
            ],
        ];
    }

    private function isLfs(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept');
        $contentType = (string) $request->headers->get('Content-Type');

        return str_contains($accept, GitResponder::LFS_JSON)
            || str_contains($contentType, GitResponder::LFS_JSON);
    }

    private function domainUser(): ?User
    {
        $sec = $this->security->getUser();

        return $sec instanceof SecurityUser ? $sec->user() : null;
    }
}
