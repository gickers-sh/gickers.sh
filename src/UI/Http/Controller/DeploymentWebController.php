<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Access\Application\AccessResolver;
use App\Deploy\Application\Query\QueryDeployments;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Dedicated deployment environment and releases inspection page:
 * `GET /{repo}/deployments/{ref}` renders the environment status, quotas, domains,
 * environment variables, releases history, and full build/runtime logs.
 */
final readonly class DeploymentWebController
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessResolver $accessResolver,
        private QueryDeployments $queryDeployments,
        private ServerSettings $settings,
        private Security $security,
        private Environment $twig,
    ) {
    }

    #[Route(
        '/{repo}/deployments/{ref}',
        name: 'repo_deployment_show',
        requirements: ['repo' => '.+?', 'ref' => '.+'],
        methods: ['GET'],
        priority: 10,
    )]
    #[Route(
        '/{repo}/deploy/{ref}',
        requirements: ['repo' => '.+?', 'ref' => '.+'],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(Request $request, string $repo, string $ref): Response
    {
        try {
            $name = RepoName::fromInput($repo);
        } catch (InvalidInput) {
            return $this->notFound();
        }

        $entity = $this->repoStore->byName($name);
        if (null === $entity) {
            return $this->notFound();
        }

        $level = $this->accessResolver->levelFor($this->domainUser(), $name, $entity);
        if (!$level->canWrite() && !$level->isAdmin()) {
            return $this->notFound();
        }

        $releaseId = null;
        if ($request->query->has('release') && is_numeric($request->query->get('release'))) {
            $releaseId = (int) $request->query->get('release');
        }

        try {
            $details = $this->queryDeployments->deploymentDetailsForRepo($entity, $ref, $releaseId);
        } catch (NotFound) {
            return $this->notFound();
        }

        $sshPrefix = $this->sshPrefix();

        return new Response(
            $this->twig->render('deployment/show.html.twig', [
                'repository' => $name->value,
                'description' => $entity->description(),
                'ref' => $ref,
                'environment' => $details['environment'],
                'quota' => $details['quota'],
                'domains' => $details['domains'],
                'envVars' => $details['envVars'],
                'releases' => $details['releases'],
                'activeRelease' => $details['activeRelease'],
                'runtimeLogs' => $details['runtimeLogs'],
                'sshPrefix' => $sshPrefix,
                'serverName' => $this->settings->name,
                'currentUsername' => $this->domainUser()?->username(),
                'canWrite' => $level->canWrite(),
                'isAdmin' => $level->isAdmin(),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function sshPrefix(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh codes.sh';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://' . $raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return 'ssh ' . $raw;
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
        $user = $parsed['user'] ?? null;

        $target = null !== $user && '' !== $user ? "{$user}@{$host}" : $host;

        return null !== $port ? "ssh -p {$port} {$target}" : "ssh {$target}";
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
