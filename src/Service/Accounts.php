<?php

namespace Base\Social\Service;

use Base\Service\SettingBagInterface;
use Omnipost\Auth\RefreshableInterface;
use Omnipost\Model\Account;
use Omnipost\Model\Token;
use Omnipost\ProviderInterface;
use Omnipost\Registry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The site's accounts on the networks, kept in omnibase's settings the way
 * the back office's API keys are (api.*, in the vault):
 *
 *   api.social.<provider>.token    the token, an Omnipost\Model\Token::toArray() as JSON
 *   api.social.<provider>.account  who it is: username, picture, followers (JSON)
 *   api.social.<provider>.api_key  a reading key, for the providers that take one (YouTube)
 *
 * provider() builds the omnipost provider with them over its configured
 * options (Registry::create()): the configuration holds the app's id and
 * secret, the settings what the artist connected.
 */
class Accounts
{
    public function __construct(
        private readonly Registry $registry,
        private readonly SettingBagInterface $settings,
        #[Autowire('%social.providers%')] private readonly array $providers = ['instagram'],
    ) {
    }

    /**
     * The providers the site uses, in order: the configured ones that
     * omnipost declares.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_filter($this->providers, fn (string $name) => $this->registry->has($name)));
    }

    public function has(string $name): bool
    {
        return \in_array($name, $this->names(), true);
    }

    /** A token is kept for it (dead or alive), or omnipost's configuration carries one. */
    public function connected(string $name): bool
    {
        if (null !== ($token = $this->token($name))) {
            return !$token->isExpired() || null !== $token->refreshToken;
        }
        $options = $this->registry->has($name) ? $this->registry->options($name) : [];

        return !empty($options['access_token']) || !empty($options['refresh_token']);
    }

    public function token(string $name): ?Token
    {
        $data = $this->read("api.social.$name.token");

        return isset($data['access_token']) ? Token::fromArray($data) : null;
    }

    /** Kept; the refresh token and the account's id of the old one survive a new token that lacks them. */
    public function saveToken(string $name, Token $token): Token
    {
        $old = $this->token($name);
        if ($old && (null === $token->refreshToken || null === $token->accountId)) {
            $token = new Token($token->accessToken, $token->expiresAt, $token->refreshToken ?? $old->refreshToken, $token->scopes ?: $old->scopes, $token->accountId ?? $old->accountId);
        }
        $this->write("api.social.$name.token", $token->toArray());

        return $token;
    }

    /** @return array{id?: string, username?: string, name?: string, url?: string, picture?: string, followers?: int, posts?: int, kind?: string, at?: string} */
    public function account(string $name): array
    {
        return $this->read("api.social.$name.account");
    }

    public function saveAccount(string $name, Account $account): void
    {
        $this->write("api.social.$name.account", array_filter([
            'id' => $account->id,
            'username' => $account->username,
            'name' => $account->name,
            'url' => $account->url,
            'picture' => $account->pictureUrl,
            'followers' => $account->followers,
            'posts' => $account->posts,
            'kind' => $account->kind,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], static fn ($value) => null !== $value));
    }

    public function apiKey(string $name): ?string
    {
        $key = $this->settings->getScalar("api.social.$name.api_key");
        if (\is_string($key) && '' !== $key) {
            return $key;
        }
        $options = $this->registry->has($name) ? $this->registry->options($name) : [];

        return !empty($options['api_key']) ? (string) $options['api_key'] : null;
    }

    public function saveApiKey(string $name, ?string $key): void
    {
        $this->secure("api.social.$name.api_key");
        $this->settings->set("api.social.$name.api_key", null !== $key && '' !== trim($key) ? trim($key) : null);
    }

    /** The token and the account forgotten: the back office's "Disconnect". */
    public function disconnect(string $name): void
    {
        $this->settings->set("api.social.$name.token", null);
        $this->settings->set("api.social.$name.account", null);
    }

    /**
     * The provider built with what the settings hold. An access token dead
     * with a refresh token at hand is left out: the provider draws a new
     * one from the refresh token (YouTube).
     */
    public function provider(string $name): ProviderInterface
    {
        return $this->registry->create($name, $this->overrides($name));
    }

    /** @return array<string, string> */
    public function overrides(string $name): array
    {
        $overrides = [];
        if (null !== ($token = $this->token($name))) {
            if (!$token->isExpired() || null === $token->refreshToken) {
                $overrides['access_token'] = $token->accessToken;
            }
            if (null !== $token->refreshToken) {
                $overrides['refresh_token'] = $token->refreshToken;
            }
        }
        $key = $this->settings->getScalar("api.social.$name.api_key");
        if (\is_string($key) && '' !== $key) {
            $overrides['api_key'] = $key;
        }

        return $overrides;
    }

    /** A fresh token asked for and kept; null when the provider's tokens do not die. */
    public function refresh(string $name): ?Token
    {
        $provider = $this->provider($name);
        if (!$provider instanceof RefreshableInterface) {
            return null;
        }

        return $this->saveToken($name, $provider->refresh());
    }

    private function read(string $path): array
    {
        $value = $this->settings->getScalar($path);
        if (\is_array($value)) {
            return $value;
        }
        $data = \is_string($value) && '' !== $value ? json_decode($value, true) : null;

        return \is_array($data) ? $data : [];
    }

    private function write(string $path, array $data): void
    {
        $this->secure($path);
        $this->settings->set($path, json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /** In the vault, as the back office's API keys are - when the bag can (SettingBag::secure() is not in the interface). */
    private function secure(string $path): void
    {
        if (method_exists($this->settings, 'secure')) {
            $this->settings->secure($path);
        }
    }
}
