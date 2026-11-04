<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Access\Application\AccessResolver;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoGetController
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessResolver $accessResolver,
        private ServerSettings $settings,
        private Security $security,
    ) {
    }

    // Catch-all like Go's `/{repo:.*}`: sub-package import paths walk up to the repository.
    // Lowest priority so every specific git/LFS route (all of which carry a fixed suffix) wins.
    #[Route('/{repo}', requirements: ['repo' => '.+'], methods: ['GET'], priority: -100)]
    public function __invoke(Request $request, string $repo): Response
    {
        if ('1' !== $request->query->get('go-get')) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $user = $this->domainUser();
        $targetRepo = preg_replace('/\.git$/', '', $repo) ?? $repo;

        // Walk up directory path to find the closest repo the caller can read
        $current = $targetRepo;
        $found = null;

        while ('' !== $current && '.' !== $current && '/' !== $current) {
            $name = RepoName::fromInput($current);
            $entity = $this->repoStore->byName($name);
            if (null !== $entity && $this->accessResolver->levelFor($user, $name, $entity)->canRead()) {
                $found = $entity;
                break;
            }

            $parent = \dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        if (null === $found) {
            return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
        }

        $parsed = parse_url($this->settings->httpPublicUrl);
        $importHost = $parsed['host'] ?? 'localhost';
        if (isset($parsed['port'])) {
            $importHost .= ':'.$parsed['port'];
        }

        $canonicalName = $found->name()->value;
        $html = sprintf(
            '<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta http-equiv="refresh" content="0; url=https://godoc.org/%s/%s">
    <meta name="go-import" content="%s/%s git %s/%s.git">
</head>
<body>
Redirecting to docs at <a href="https://godoc.org/%s/%s">godoc.org/%s/%s</a>...
</body>
</html>
',
            htmlspecialchars($importHost, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($canonicalName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($importHost, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($canonicalName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(rtrim($this->settings->httpPublicUrl, '/'), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($canonicalName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($importHost, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($canonicalName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($importHost, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($canonicalName, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
        );

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function domainUser(): ?User
    {
        $sec = $this->security->getUser();

        return $sec instanceof SecurityUser ? $sec->user() : null;
    }
}
