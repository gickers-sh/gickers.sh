<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Lfs\Application\DownloadObject;
use App\Lfs\Application\UploadObject;
use App\Lfs\Application\VerifyObject;
use App\Lfs\Domain\Oid;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LfsObjectController
{
    public function __construct(
        private DownloadObject $downloadObject,
        private UploadObject $uploadObject,
        private VerifyObject $verifyObject,
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private RepoStore $repoStore,
        private ServerSettings $settings,
        private Security $security,
    ) {
    }

    #[Route('/{repo}.git/info/lfs/objects/basic/{oid}', requirements: ['repo' => '.+?', 'oid' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[Route('/{repo}/info/lfs/objects/basic/{oid}', requirements: ['repo' => '.+?', 'oid' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function download(Request $request, string $repo, string $oid): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/objects/basic/'.$oid, 'GET', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $oidVO = Oid::fromString($oid);
        [$stream, $size] = ($this->downloadObject)($repoEntity, $oidVO);

        return new StreamedResponse(static function () use ($stream) {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if (false !== $chunk && '' !== $chunk) {
                    echo $chunk;
                }
            }
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
        ]);
    }

    #[Route('/{repo}.git/info/lfs/objects/basic/{oid}', requirements: ['repo' => '.+?', 'oid' => '[0-9a-f]{64}'], methods: ['PUT'])]
    #[Route('/{repo}/info/lfs/objects/basic/{oid}', requirements: ['repo' => '.+?', 'oid' => '[0-9a-f]{64}'], methods: ['PUT'])]
    public function upload(Request $request, string $repo, string $oid): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/objects/basic/'.$oid, 'PUT', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $oidVO = Oid::fromString($oid);
        $declaredSize = (int) ($request->headers->get('Content-Length') ?? 0);
        $inputStream = $request->getContent(true);

        ($this->uploadObject)($repoEntity, $oidVO, $declaredSize, $inputStream);

        return new Response('', Response::HTTP_OK);
    }

    #[Route('/{repo}.git/info/lfs/objects/basic/{oid}/verify', requirements: ['repo' => '.+?', 'oid' => '[0-9a-f]{64}'], methods: ['POST'])]
    #[Route('/{repo}/info/lfs/objects/basic/{oid}/verify', requirements: ['repo' => '.+?', 'oid' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function verify(Request $request, string $repo, string $oid): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/objects/basic/'.$oid, 'POST', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(['message' => 'repository not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['size']) || !is_numeric($data['size'])) {
            throw new InvalidInput('missing or invalid size in request');
        }

        $oidVO = Oid::fromString($oid);
        ($this->verifyObject)($repoEntity, $oidVO, (int) $data['size']);

        return new JsonResponse([], Response::HTTP_OK, ['Content-Type' => GitResponder::LFS_JSON]);
    }

    private function domainUser(): ?User
    {
        $sec = $this->security->getUser();

        return $sec instanceof SecurityUser ? $sec->user() : null;
    }
}
