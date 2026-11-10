<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Http\ContentType;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a single file from a ref as raw bytes: `GET /{repo}/raw/{ref}/{path}`.
 * The blob is resolved with git (never a checkout), access follows the same read
 * rules as Git dumb-HTTP, and a missing ref/path/blob answers 404. The Content-Type
 * is detected from the filename extension with an octet-stream fallback.
 */
final readonly class RawFileController
{
    public function __construct(
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
        private Security $security,
    ) {
    }

    #[Route(
        '/{repo}/raw/{ref}/{path}',
        requirements: ['repo' => '.+?', 'ref' => '[^/]+', 'path' => '.+'],
        methods: ['GET'],
        priority: 10,
    )]
    public function raw(Request $request, string $repo, string $ref, string $path): Response
    {
        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'raw', 'GET', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, false);
        if (null !== $response) {
            return $response;
        }

        if (!$this->storage->exists($repoName)) {
            return $this->notFound();
        }

        try {
            $content = $this->reader->catBlob($this->storage->open($repoName), $ref, $path);
        } catch (InvalidInput) {
            return $this->notFound();
        }

        if (null === $content) {
            return $this->notFound();
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => ContentType::fromFilename($path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
        ]);
    }

    private function notFound(): Response
    {
        return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}
