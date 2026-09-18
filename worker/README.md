# Cloudflare Worker
A Cloudflare Worker that accepts GitHub webhook events and sends them to Discord.
It validates the signature, converts the event into an embed
and posts it to every matching Discord webhook.

## Deploying
You need a [Cloudflare](https://dash.cloudflare.com/sign-up) account (the free plan is enough) and Node.js.

```
npm install
npx wrangler login
cp repositories.json.example repositories.json
npx wrangler secret put REPOSITORIES < repositories.json
npm run deploy
```

Edit `repositories.json` before uploading it, see [Configuration](#configuration).

Setting the secret offers to create the Worker if it does not exist yet, accept it.
The deploy prints the url of the Worker, such as `https://github-webhook-discord.<account>.workers.dev`.
To change the name, or to serve it from your own domain, edit `name` or add
[`routes`](https://developers.cloudflare.com/workers/configuration/routing/) in `wrangler.jsonc`.

### Configuration
All configuration lives in a single secret, `REPOSITORIES`. It is listed in `secrets.required`
in `wrangler.jsonc`, the value itself is never stored in the config.

It is a JSON object mapping repository patterns to the secret token of their GitHub webhook
and the Discord webhooks their events are sent to. Copy `repositories.json.example` to `repositories.json`
and edit it, the copy is ignored by git because it contains secrets.

- `*` in a pattern is a wildcard. Events that have no repository (organization events)
  are matched as `<org>/repositories`, which `<org>/*` covers.
- A request is only accepted by patterns whose `secret` matches its signature, so a secret
  can not be used to send events for a repository it was not configured for.
- Every matching pattern with a valid secret fires. To send a repository to the webhooks of
  both its own pattern and a wildcard one, give both patterns the same secret.
- Requests for repositories that match no pattern are rejected the same way as an invalid secret.

Discord webhook urls are created in the channel settings, under Integrations → Webhooks.
Treat them as passwords, anyone who has the url can post to the channel.
Run `npx wrangler secret put REPOSITORIES < repositories.json` again to change the configuration, no deploy is needed.

### GitHub
Add a webhook in the settings of a repository or an organization:

- **Payload URL**: the url of the Worker
- **Content type**: either one works
- **Secret**: the `secret` of the pattern that matches the repository, requests without a valid signature are rejected
- **Events**: pick the ones you want, see the list below

After saving, GitHub sends a `ping` event which should show up in Discord.
The response to every delivery is visible under Recent Deliveries in the webhook settings,
and `npx wrangler tail` streams the logs of the Worker.

| Status | Meaning |
|--------|---------|
| 202 | Sent to Discord |
| 200 | The event is deliberately ignored, such as `star` or an edited comment |
| 400 | Malformed request |
| 401 | Missing or invalid signature, or no pattern matches the repository |
| 500 | `REPOSITORIES` is missing or not valid, see `wrangler tail` |
| 501 | The event or its action is not supported |
| 502 | Every Discord webhook failed |

Supported events: `commit_comment`, `delete`, `discussion`, `discussion_comment`, `gollum`,
`issue_comment`, `issues`, `member`, `milestone`, `package`, `ping`, `project`, `public`,
`pull_request`, `pull_request_review`, `pull_request_review_comment`, `push`, `release`,
`repository`, `repository_vulnerability_alert`.

## Development
Copy `.dev.vars.example` to `.dev.vars` and run `npm run dev` to start the Worker locally.

`npm test` runs the typecheck and then the tests. Every event in `../tests/events`
is converted and compared against its expected `discord.json` embed.
