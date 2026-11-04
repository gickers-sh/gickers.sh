<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Lfs\Application\Batch;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Http\GitResponder;
use App\UI\Http\Security\GitAccessAuthorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LfsBatchController
{
    public function __construct(
        private Batch $batch,
        private GitAccessAuthorizer $authorizer,
        private GitResponder $responder,
        private RepoStore $repoStore,
        private ServerSettings $settings,
        private Security $security,
    ) {
    }

    #[Route('/{repo}.git/info/lfs/objects/batch', requirements: ['repo' => '.+?'], methods: ['POST'])]
    #[Route('/{repo}/info/lfs/objects/batch', requirements: ['repo' => '.+?'], methods: ['POST'])]
    public function __invoke(Request $request, string $repo): Response
    {
        if (!$this->settings->lfsEnabled) {
            return new JsonResponse(['message' => 'not found'], Response::HTTP_NOT_FOUND, ['Content-Type' => GitResponder::LFS_JSON]);
        }

        $repoName = RepoName::fromInput($repo);
        $user = $this->domainUser();
        $badCreds = (bool) $request->attributes->get('_bad_credentials', false);

        $auth = $this->authorizer->decide($user, $repoName, '', 'info/lfs/objects/batch', 'POST', $badCreds);
        $response = $this->responder->respond($auth, $request, $repoName, $user, true);
        if (null !== $response) {
            return $response;
        }

        $accept = (string) $request->headers->get('Accept');
        $contentType = (string) $request->headers->get('Content-Type');
        if (!str_contains($accept, GitResponder::LFS_JSON) && !str_contains($contentType, GitResponder::LFS_JSON)) {
            return new Response('', Response::HTTP_NOT_ACCEPTABLE);
        }

        $rawBody = $request->getContent();
        /** @var array{operation?: string, transfers?: list<string>, objects?: list<array{oid: string, size: int}>}|null $data */
        $data = json_decode($rawBody, true);
        if (!\is_array($data) || !isset($data['operation'])) {
            return new JsonResponse(
                ['message' => 'validation error in request'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => GitResponder::LFS_JSON],
            );
        }

        if (isset($data['transfers']) && !\in_array('basic', $data['transfers'], true)) {
            return new JsonResponse(
                ['message' => 'unsupported transfer'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => GitResponder::LFS_JSON],
            );
        }

        if (empty($data['objects'])) {
            return new JsonResponse(
                ['message' => 'no objects found'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => GitResponder::LFS_JSON],
            );
        }

        $repoEntity = $this->repoStore->byName($repoName);
        if (null === $repoEntity) {
            return new JsonResponse(
                ['message' => 'repository not found'],
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => GitResponder::LFS_JSON],
            );
        }

        $operation = $data['operation'];
        $results = ($this->batch)($repoEntity, $operation, $data['objects']);

        $authHeader = $request->headers->get('Authorization');
        $objects = [];

        foreach ($results as $res) {
            if ($res->isError()) {
                $objects[] = [
                    'oid' => $res->oid,
                    'size' => $res->size,
                    'error' => [
                        'code' => $res->errorCode,
                        'message' => $res->errorMessage,
                    ],
                ];
                continue;
            }

            $baseHref = sprintf(
                '%s/%s.git/info/lfs/objects/basic/%s',
                rtrim($this->settings->httpPublicUrl, '/'),
                $repoName->value,
                $res->oid,
            );

            $actions = [];
            if ('upload' === $operation) {
                $uploadAction = ['href' => $baseHref];
                if (null !== $authHeader) {
                    $uploadAction['header'] = ['Authorization' => $authHeader];
                }
                $actions['upload'] = $uploadAction;

                $verifyAction = ['href' => $baseHref.'/verify'];
                if (null !== $authHeader) {
                    $verifyAction['header'] = ['Authorization' => $authHeader];
                }
                $actions['verify'] = $verifyAction;
            } else {
                $downloadAction = ['href' => $baseHref];
                if (null !== $authHeader) {
                    $downloadAction['header'] = ['Authorization' => $authHeader];
                }
                $actions['download'] = $downloadAction;
            }

            $objects[] = [
                'oid' => $res->oid,
                'size' => $res->size,
                'actions' => $actions,
            ];
        }

        return new JsonResponse([
            'transfer' => 'basic',
            'hash_algo' => 'sha256',
            'objects' => $objects,
        ], Response::HTTP_OK, ['Content-Type' => GitResponder::LFS_JSON]);
    }

    private function domainUser(): ?User
    {
        $sec = $this->security->getUser();

        return $sec instanceof SecurityUser ? $sec->user() : null;
    }
}
