<?php

declare(strict_types=1);

namespace App\UI\Http;

use App\Identity\Domain\Model\User;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\RepoName;
use App\UI\Http\Security\GitAuthorization;
use App\UI\Http\Security\GitVerdict;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Translates a GitAuthorization verdict into an HTTP Response (or null if the controller should proceed).
 * Handles push-to-create on CreateThenServe, WWW/LFS-Authenticate challenges, and denyRead parity.
 */
final readonly class GitResponder
{
    public const LFS_JSON = 'application/vnd.git-lfs+json';

    public function __construct(
        private CreateRepo $createRepo,
    ) {
    }

    /**
     * @return Response|null Returns null if the request may be served, or a terminal Response
     */
    public function respond(
        GitAuthorization $auth,
        Request $request,
        RepoName $repoName,
        ?User $user,
        bool $isLfs = false,
    ): ?Response {
        return match ($auth->verdict) {
            GitVerdict::Serve => null,
            GitVerdict::CreateThenServe => $this->handleCreateThenServe($user, $repoName),
            GitVerdict::DenyUnreadable => $this->handleDenyUnreadable($user, $isLfs),
            GitVerdict::Forbid => $this->handleForbid($isLfs),
            GitVerdict::Unauthorized => $this->handleUnauthorized($isLfs),
            GitVerdict::ForbidLfsWrite => $this->handleForbidLfsWrite(),
        };
    }

    public static function addChallengeHeaders(Response $response): void
    {
        $response->headers->set('WWW-Authenticate', 'Basic realm="Git" charset="UTF-8", Token, Bearer');
        $response->headers->set('LFS-Authenticate', 'Basic realm="Git LFS" charset="UTF-8", Token, Bearer');
    }

    private function handleCreateThenServe(?User $user, RepoName $repoName): null
    {
        ($this->createRepo)($user, $repoName);

        return null;
    }

    private function handleDenyUnreadable(?User $user, bool $isLfs): Response
    {
        // Go `denyRead`: anonymous -> 401 with challenge; authenticated -> 404
        if (null === $user) {
            return $this->handleUnauthorized($isLfs);
        }

        if ($isLfs) {
            return new JsonResponse(
                ['message' => 'repository not found'],
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => self::LFS_JSON],
            );
        }

        return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
    }

    private function handleForbid(bool $isLfs): Response
    {
        if ($isLfs) {
            return new JsonResponse(
                ['message' => 'bad credentials'],
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => self::LFS_JSON],
            );
        }

        return new Response("Forbidden\n", Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain']);
    }

    private function handleUnauthorized(bool $isLfs): Response
    {
        if ($isLfs) {
            $response = new JsonResponse(
                ['message' => 'credentials needed'],
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => self::LFS_JSON],
            );
        } else {
            $response = new Response("Unauthorized\n", Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain']);
        }

        self::addChallengeHeaders($response);

        return $response;
    }

    private function handleForbidLfsWrite(): Response
    {
        $response = new JsonResponse(
            ['message' => 'write access required'],
            Response::HTTP_FORBIDDEN,
            ['Content-Type' => self::LFS_JSON],
        );
        self::addChallengeHeaders($response);

        return $response;
    }
}
