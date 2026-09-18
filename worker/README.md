# Cloudflare Worker
A Cloudflare Worker that accepts GitHub webhook events and sends them to Discord.
It validates the signature, converts the event into an embed
and posts it to the Discord webhook that is named in the url.
Messages are sent under the name of the repository and the avatar of its owner,
so that repositories sharing a webhook can be told apart.

## Deploying
You need a [Cloudflare](https://dash.cloudflare.com/sign-up) account (the free plan is enough) and Node.js.

```
npm install
npx wrangler login
npx wrangler secret put SECRET
npm run deploy
```

`SECRET` is the secret token that every GitHub webhook has to be signed with, pick a long random string.
Without it anyone could use the Worker to post to Discord, so requests are refused until it is set.
It is listed in `secrets.required` in `wrangler.jsonc`, the value itself is never stored in the config.

Setting the secret offers to create the Worker if it does not exist yet, accept it.
The deploy prints the url of the Worker, such as `https://github-webhook.<account>.workers.dev`.
To change the name, or to serve it from your own domain, edit `name` or add
[`routes`](https://developers.cloudflare.com/workers/configuration/routing/) in `wrangler.jsonc`.

### Url format
The Worker stores no Discord webhooks, the one to send to is part of the url that GitHub calls:

```
https://<worker>/discordhook/<webhook id>/<webhook token>
https://<worker>/discordhook/<webhook id>/<webhook token>?thread_id=<thread id>
```

Discord webhooks are created in the channel settings, under Integrations → Webhooks.
Copy the url of the webhook and move its last two parts over to the Worker:

```
https://discord.com/api/webhooks/123456789012345678/aBcDeF-123
https://github-webhook.<account>.workers.dev/discordhook/123456789012345678/aBcDeF-123
```

- Add `?thread_id=` to post in a thread of the channel. Enable Developer Mode in the advanced settings
  of Discord, then right click the thread and copy its id. Webhooks of forum and media channels
  can only post in a thread, Discord rejects them without a `thread_id`.
- One url is one destination. To send a repository to several channels, add a GitHub webhook for each of them.
  To send every repository of an organization to one channel, add the webhook to the organization instead.
- Treat the url as a password: it contains the token of the Discord webhook, so anyone who can see
  the settings of the GitHub webhook can post to the channel.

### GitHub
Add a webhook in the settings of a repository or an organization:

- **Payload URL**: the url from above
- **Content type**: either one works
- **Secret**: the `SECRET` of the Worker, requests without a valid signature are rejected
- **Events**: choose "Let me select individual events." and tick the [supported events](../README.md#supported-events) you want.
  Do not choose "Send me everything.", events that are not supported are answered with a 501
  and fill Recent Deliveries with failures.

After saving, GitHub sends a `ping` event which should show up in Discord.
The response to every delivery is visible under Recent Deliveries in the webhook settings,
and `npx wrangler tail` streams the logs of the Worker.

| Status | Meaning |
|--------|---------|
| 202 | Sent to Discord |
| 200 | The event is deliberately ignored, such as `star` or an edited comment |
| 400 | Malformed request, or the url is not a valid `/discordhook/` url |
| 401 | Missing or invalid signature |
| 500 | `SECRET` is not set, see `wrangler tail` |
| 501 | The event or its action is not supported |
| 502 | Discord did not accept the message, the response has its status |

Some events are ignored because they would only be noise, see `src/ignored.ts`:

- everything sent by Dependabot, except for its alerts and the pull requests it merges itself
- pushes to and deletions of `renovate/` and `dependabot/` branches
- pushes to and deletions of the temporary `gh-readonly-queue/` branches of a merge queue
- the push GitHub makes when a pull request is merged on github.com, the merged pull request is announced already

## Development
Copy `.dev.vars.example` to `.dev.vars` and run `npm run dev` to start the Worker locally.

`npm test` runs the typecheck and then the tests. Every event in `../tests/events`
is converted and compared against its expected `discord.json` embed.
