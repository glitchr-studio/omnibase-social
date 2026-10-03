<?php

namespace Base\Social\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Social\Repository\SocialMediaRepository;
use Base\Social\Service\Accounts;
use Base\Social\Service\FeedSync;
use Omnipost\Auth\OAuthInterface;
use Omnipost\Auth\RefreshableInterface;
use Omnipost\Exception\OmnipostException;
use Omnipost\FeedInterface;
use Omnipost\Platform;
use Omnipost\PublisherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The accounts on the networks, in the back office (Social): per provider
 * the account connected - its name, its picture, its followers -, when its
 * token dies, and the buttons: connect (the network's consent page, then
 * back at /connect/social/{provider}), refresh the token, read the feed now,
 * disconnect. A reading key (YouTube's) is typed here too.
 */
#[IsGranted('ROLE_ADMIN')]
class AccountsController extends AbstractController
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly FeedSync $sync,
        private readonly SocialMediaRepository $media,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
    ) {
    }

    #[Route('/admin/social', name: 'social_admin_accounts', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];
        foreach ($this->accounts->names() as $name) {
            $row = [
                'name' => $name,
                'label' => Platform::tryFrom($name)?->label() ?? $name,
                'connected' => $this->accounts->connected($name),
                'account' => $this->accounts->account($name),
                'token' => $this->accounts->token($name),
                'api_key' => null !== $this->accounts->apiKey($name),
                'last_sync' => $this->media->lastSync($name),
                'count' => $this->media->countFor($name),
                'oauth' => false, 'refreshable' => false, 'feed' => false, 'publisher' => false,
                'error' => null,
            ];
            try {
                $provider = $this->accounts->provider($name);
                $row['oauth'] = $provider instanceof OAuthInterface;
                $row['refreshable'] = $provider instanceof RefreshableInterface;
                $row['feed'] = $provider instanceof FeedInterface;
                $row['publisher'] = $provider instanceof PublisherInterface;
            } catch (OmnipostException $e) {
                $row['error'] = $e->getMessage();
            }
            $row['callback'] = $this->generateUrl('social_oauth_callback', ['provider' => $name], UrlGeneratorInterface::ABSOLUTE_URL);
            $rows[] = $row;
        }

        return $this->page('@Social/admin/accounts.html.twig', ['rows' => $rows]);
    }

    #[Route('/admin/social/{provider}/connect', name: 'social_admin_connect', requirements: ['provider' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function connect(Request $request, string $provider): Response
    {
        $this->assertPostToken($request, 'social-connect-'.$provider);
        try {
            $oauth = $this->accounts->provider($provider);
            if (!$oauth instanceof OAuthInterface) {
                throw $this->createNotFoundException();
            }
            $state = bin2hex(random_bytes(16));
            $request->getSession()->set('social.oauth.'.$provider, $state);

            return $this->redirect($oauth->authorizationUrl($this->generateUrl('social_oauth_callback', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL), $state));
        } catch (OmnipostException $e) {
            $this->addFlash('danger', $this->label($provider).' : '.htmlspecialchars($e->getMessage()));

            return $this->redirectToRoute('social_admin_accounts');
        }
    }

    /** Where the network sends the administrator back: the code exchanged, the token kept (Service\Accounts). */
    #[Route('/connect/social/{provider}', name: 'social_oauth_callback', requirements: ['provider' => '[a-z0-9_-]+'], methods: ['GET'])]
    public function callback(Request $request, string $provider, #[MapQueryParameter] ?string $code = null, #[MapQueryParameter] ?string $state = null, #[MapQueryParameter] ?string $error = null): Response
    {
        $session = $request->getSession();
        $expected = $session->get('social.oauth.'.$provider);
        $session->remove('social.oauth.'.$provider);
        if ($error || !$code || !$state || !\is_string($expected) || !hash_equals($expected, $state)) {
            $this->addFlash('danger', $this->trans('@social.admin.accounts.flash.refused', $provider));

            return $this->redirectToRoute('social_admin_accounts');
        }

        try {
            $oauth = $this->accounts->provider($provider);
            if (!$oauth instanceof OAuthInterface) {
                throw $this->createNotFoundException();
            }
            $this->accounts->saveToken($provider, $oauth->exchange($code, $this->generateUrl('social_oauth_callback', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL)));
            // Built again: with the token just kept.
            $connected = $this->accounts->provider($provider);
            if ($connected instanceof FeedInterface) {
                $this->accounts->saveAccount($provider, $connected->account());
            }
            $this->addFlash('success', $this->trans('@social.admin.accounts.flash.connected', $provider));
        } catch (OmnipostException $e) {
            $this->addFlash('danger', $this->label($provider).' : '.htmlspecialchars($e->getMessage()));
        }

        return $this->redirectToRoute('social_admin_accounts');
    }

    #[Route('/admin/social/{provider}/refresh', name: 'social_admin_refresh', requirements: ['provider' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function refresh(Request $request, string $provider): Response
    {
        $this->assertPostToken($request, 'social-refresh-'.$provider);
        try {
            $this->accounts->refresh($provider);
            $this->addFlash('success', $this->trans('@social.admin.accounts.flash.refreshed', $provider));
        } catch (OmnipostException $e) {
            $this->addFlash('danger', $this->label($provider).' : '.htmlspecialchars($e->getMessage()));
        }

        return $this->redirectToRoute('social_admin_accounts');
    }

    #[Route('/admin/social/{provider}/sync', name: 'social_admin_sync', requirements: ['provider' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function sync(Request $request, string $provider): Response
    {
        $this->assertPostToken($request, 'social-sync-'.$provider);
        try {
            $count = $this->sync->sync($provider);
            $this->addFlash('success', $this->trans('@social.admin.accounts.flash.synced', $provider, ['count' => $count]));
        } catch (OmnipostException $e) {
            $this->addFlash('danger', $this->label($provider).' : '.htmlspecialchars($e->getMessage()));
        }

        return $this->redirectToRoute('social_admin_accounts');
    }

    #[Route('/admin/social/{provider}/disconnect', name: 'social_admin_disconnect', requirements: ['provider' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function disconnect(Request $request, string $provider): Response
    {
        $this->assertPostToken($request, 'social-disconnect-'.$provider);
        $this->accounts->disconnect($provider);
        $this->addFlash('success', $this->trans('@social.admin.accounts.flash.disconnected', $provider));

        return $this->redirectToRoute('social_admin_accounts');
    }

    /** A reading key (YouTube's Data API key): kept in the settings, in the vault. Empty: the configuration's again. */
    #[Route('/admin/social/{provider}/key', name: 'social_admin_key', requirements: ['provider' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function key(Request $request, string $provider): Response
    {
        $this->assertPostToken($request, 'social-key-'.$provider);
        $this->accounts->saveApiKey($provider, $request->request->getString('api_key') ?: null);
        $this->addFlash('success', $this->trans('@social.admin.accounts.flash.key_saved', $provider));

        return $this->redirectToRoute('social_admin_accounts');
    }

    /** In the back office's chrome, with its menus, as the ledger's pages are. */
    private function page(string $template, array $parameters): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render($template, ['admin_context' => $this->adminContext] + $parameters);
    }

    private function assertPostToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        if (!$this->accounts->has((string) $request->attributes->get('provider'))) {
            throw $this->createNotFoundException();
        }
    }

    private function label(string $provider): string
    {
        return Platform::tryFrom($provider)?->label() ?? $provider;
    }

    /** A flash in the social domain, the network named. */
    private function trans(string $key, string $provider, array $parameters = []): \Symfony\Component\Translation\TranslatableMessage
    {
        return new \Symfony\Component\Translation\TranslatableMessage(substr($key, \strlen('@social.')), ['network' => $this->label($provider)] + $parameters, 'social');
    }
}
