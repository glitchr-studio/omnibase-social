# Social

Social publishing for [omnibase](https://github.com/glitchr-studio/omnibase):
a reel made from the site's own template - its logo, a band with a name on
it, its colours, its font, a clip before and after -, sent to each network
with what it says there, and the account's feed kept for a wall on the site.
A violinist's site posts her concert's excerpt to Instagram and YouTube from
its back office; a shop shows its last posts under its products.

The networks are [glitchr/omnipost](https://github.com/glitchr-studio/omnipost)'s
providers (`instagram`, `youtube`...): this bundle knows the site - its
template, its posts, its settings -, omnipost knows the networks.

## The pipeline

A `SocialPost` is the film as it was shot (or a picture), a caption, its
hashtags, a title for the networks that want one, and one `SocialPostTarget`
per network it goes to - which may say it otherwise there (its own caption,
title, hashtags, template, and extras such as Instagram's `share_to_feed` or
YouTube's `privacy`). Three Messenger messages carry it:

1. `RenderPostMessage` - each network's film made by `Service\Renderer`
   (ffmpeg) with its template: the target's, else the post's, else the
   network's, else the site's (the `Template` marked as the site's). A post
   that says "as it is" (`useTemplate` off) is not encoded again. The film
   lands in `<social.storage>/<post id>/<platform>.mp4`, its cover (the frame
   at `coverSecond`) beside it. Target: `RENDERED`.
2. `PublishTargetMessage` - the post as that network receives it
   (`SocialPost::toPost()->for(Platform)`) checked by `Omnipost\Validator`
   against the network's `capabilities()` and the provider's own rules (a
   reel is 9:16 on Instagram): a refusal fails the target with the reasons,
   nothing sent. Otherwise `publish()`: the network's id kept, `SENT` or
   `PROCESSING`. At the post's hour when it has one to come: the message is
   held back (`DelayStamp`), the networks' own scheduling is not used.
3. `CheckTargetMessage` - every `social.check.delay` seconds, `status()` read
   again until the network says `PUBLISHED` (the permalink kept) or
   `FAILED` (its reason kept), `social.check.attempts` times at most.

The handlers are thin: `Service\Publisher` holds the logic. The back office
publishes only after every enabled network passed the checks; the preview
page shows each network's reel, cover, checks, state, permalink and error,
and a button to send one network again.

Route the three messages to an asynchronous transport - a rendering takes a
while, and a network processes a reel for a minute or two:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Base\Social\Message\RenderPostMessage: async
            Base\Social\Message\PublishTargetMessage: async
            Base\Social\Message\CheckTargetMessage: async
```

The `async` transport must take delays (Doctrine, Redis, AMQP do; `sync://`
runs the checks at once and gives up early).

## The template

`Template`: a name, the site's or a network's, a logo (`#[Uploader]`, a
picture) in one of five places at 10-50 % of the width, a band across the
lower third with a text (the artist's name), its colour and the text's, a
font (TTF/OTF upload; DejaVu Sans otherwise), the background behind a film
that is shown whole, a clip before and after, how a film that is not upright
is fitted (`COVER`: cropped to fill 9:16; `CONTAIN`: whole, letterboxed), and
fades in and out.

The renderer builds one explicit `-filter_complex` (`Renderer::command()` is
pure and tested): scale and crop (or pad) to 1080 x 1920, `overlay` of the
scaled logo with 64 px margins, `drawbox` + `drawtext` for the band, `fade`
and `afade`, the intro and outro brought to the same size, rate and sound and
joined with `concat`; then H.264 High 4.1, yuv420p, 30 fps, CRF 20, AAC
160k, `+faststart` - what Instagram, YouTube, Facebook and TikTok all take.

### ffmpeg in the image

The renderer runs ffmpeg and ffprobe: they belong in the image of whatever
renders - the Messenger worker (and the web image, for the preview's checks
of a post not yet rendered):

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg fonts-dejavu-core && rm -rf /var/lib/apt/lists/*
# alpine: apk add --no-cache ffmpeg font-dejavu
```

Without them a rendering fails with a `RenderingException` saying so, and
the target shows it. `social.ffmpeg.binary` / `social.ffmpeg.ffprobe` name
other paths; `social.ffmpeg.font` the band's fallback font.

## The public address of a film

Instagram does not take an upload: it is given an address and comes for the
film itself. `Service\MediaUrls::for($target)` gives that address -
`/social/media/{target}/{token}.mp4` (`social_media`), absolute -, signed with
the kernel's secret over the target's id and the file's path
(`Service\MediaToken`): it names that one file, cannot be guessed, and
answers only while the publication is on its way, and `social.media_url_ttl`
seconds (a day) after it ended. Ranges are answered.

The worker has no request to take the host from: set
`framework.router.default_uri` to the site's public address, and keep
`/social/media/` reachable from the internet (no access restriction,
maintenance or launch date page on it).

## Install

```bash
composer require omnibase/social:dev-main
```

```php
// config/bundles.php
Omnipost\Bridge\Symfony\OmnipostBundle::class => ['all' => true],
Base\Social\SocialBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
social_controller:
    resource: "@SocialBundle/src/Controller/Client"
    type: attribute
    prefix: /
social_admin:
    resource: "@SocialBundle/src/Controller/Admin/AccountsController.php"
    type: attribute
    prefix: /
```

```yaml
# config/packages/omnipost.yaml: the app's id and secret; the tokens are the back office's
omnipost:
    providers:
        instagram: { factory: instagram, options: { app_id: '%env(default::INSTAGRAM_APP_ID)%', app_secret: '%env(default::INSTAGRAM_APP_SECRET)%' } }
        youtube:   { factory: youtube, options: { client_id: '%env(default::YOUTUBE_CLIENT_ID)%', client_secret: '%env(default::YOUTUBE_CLIENT_SECRET)%', channel_id: '%env(default::YOUTUBE_CHANNEL_ID)%' } }
```

```yaml
# config/packages/social.yaml (every key optional)
social:
    providers: [instagram]          # the omnipost providers the site uses, in order
    ffmpeg: { binary: ffmpeg, ffprobe: ffprobe, crf: 20, fps: 30 }
    storage: '%kernel.project_dir%/var/storage/social'
    feed: { limit: 24, cache_thumbnails: true }
    media_url_ttl: 86400            # seconds the film's address answers after the end
    check: { delay: 30, attempts: 20 }
```

Then `bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate`
and `bin/console assets:install` (the stylesheet lives in `public/css/social.css`).

## The accounts

`/admin/social` (`social_admin_accounts`) lists each provider: the account
connected (name, picture, followers), when its token dies, and the buttons -
connect (the network's consent page, back at `/connect/social/{provider}`,
`social_oauth_callback`, to declare in the app), refresh the token, read the
feed now, disconnect - and YouTube's reading key. The tokens are kept in
omnibase's settings, in the vault, as the back office's API keys are:
`api.social.<provider>.token` (an `Omnipost\Model\Token` as JSON),
`api.social.<provider>.account`, `api.social.<provider>.api_key`.
`Service\Accounts::provider($name)` builds the provider with them over its
configured options.

Instagram, for one artist's account: a professional account (creator or
business), and a Meta app in development mode with the artist added as an
Instagram Tester - no App Review is needed for an account of the app's own.

Two crons:

```cron
0 * * * *  bin/console social:sync            # the walls' posts, hourly
30 4 * * * bin/console social:refresh-tokens  # tokens that die within ten days (Instagram's live sixty)
```

## On the site

The host's pages ask three Twig functions:

- `social_wall(12, 'instagram')` - the account's last posts in a grid
  (`@Social/client/_wall.html.twig`): the thumbnail is the site's own copy
  (the networks' addresses expire), a film plays muted on hover from the
  network's address while it answers, each opens the post on the network.
  A post can be kept off the wall in the back office.
- `social_embed(url)` - any public post: an Instagram post or reel
  (Instagram's own blockquote embed), a YouTube film (youtube-nocookie).
  Nothing reaches the network before a click - or `social_embed(url, true)`
  once the visitor said yes, or a `social:consent` event on `document`.
- `social_connected('instagram')` - whether the account is connected.

The back office gets `SocialPost` (render, publish, retry, preview),
`Template` and `SocialMedia` CRUDs, the accounts page, and a dashboard
widget, `social_overview`: each network's last publication and its state,
when the feed was read, a word when a token dies soon.

## Tests

`tests/` holds unit tests that need no kernel: the ffmpeg command
(`RendererCommandTest`), `SocialPost::toPost()` and its variants, the media
token. `vendor/bin/phpunit` in a checkout with its dependencies.

## License

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
