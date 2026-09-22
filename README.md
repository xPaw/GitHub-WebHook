# GitHub WebHook
Accepts webhook events of [GitHub](https://github.com/), validates them, and converts them into
readable messages which can be sent out to an IRC channel or a Discord webhook.

A push, a merged pull request and a release look like this on IRC, with colors:

```
[Hello-World] monalisa pushed 1 new commit: Add tests for the webhook handler https://github.com/monalisa/Hello-World/commit/8ddec647
[Hello-World] monalisa merged pull request #6 from monalisa to master: test pull request. https://github.com/monalisa/Hello-World/pull/6
[Spoon-Knife] monalisa published a pre-release 0.0.4: https://github.com/octo-org/Spoon-Knife/releases/tag/0.0.4
```

On Discord the same events are cards built with [components](https://docs.discord.com/developers/components/reference),
sent under the name and avatar of the GitHub user who set the event off, headed by a link
such as "monalisa pushed 1 new commit", with the commits, the comment or the release notes below it.

Discord can accept GitHub webhooks on its own when `/github` is added to the url of a webhook,
but it only formats a handful of events and silently drops the rest, such as security alerts, wiki edits
and everything about organizations and teams, and there is no control over what is announced or how it looks.

Only the events that are worth reading are announced, the noisy ones are left out on purpose,
see the [list of events](#events-ref) below.

There are two versions, use whichever is easier for you to host:

| Version | Sends to | What it is |
|---------|----------|------------|
| [`php/`](php/) | IRC, Discord | A PHP library, your application receives the request and sends out the converted message |
| [`worker/`](worker/) | Discord | A Cloudflare Worker that is ready to deploy, it posts to the Discord webhook that is named in the url |

Both versions are kept in sync: they support the same events, ignore the same actions,
and produce the same Discord message for the same payload.
Every event in [`fixtures/`](fixtures/) has a payload along with the messages that are expected for it,
and the tests of both versions run against these same fixtures.
A change to how an event is formatted has to be made in both versions.

## Fixtures
Every folder in `fixtures/` is one event:

| File | Contents |
|------|----------|
| `type.txt` | The name of the event, as sent in the `X-GitHub-Event` header |
| `payload.json` | The payload as sent by GitHub |
| `expected.bin` | The IRC message that is expected for it, with its color codes |
| `discord.json` | The Discord message that is expected for it |

The PHP tests check both messages, the Worker tests check the Discord one
and validate the payload against the webhook schemas of GitHub.

To add or change an event, add a folder with its type and payload, make the change in `php/`,
and run its tests with `UPDATE_FIXTURES=1` to write the expected messages.
Review them with git, then make the same change in `worker/` until its tests pass against the same message.

## Events [\[ref\]](https://docs.github.com/en/webhooks/webhook-events-and-payloads)

Track changes to GitHub webhook payloads documentation here: https://github.com/github/docs/commits/main/data/reusables/webhooks

### Supported events

- **branch_protection_configuration** - Branch protection was enabled or disabled for every branch of a repository
- **branch_protection_rule** - A branch protection rule was created or deleted
- **code_scanning_alert** - A code scanning alert was created, fixed, reopened or closed
- **commit_comment** - Someone commented on a commit
- **delete** - A branch or a tag was deleted
- **dependabot_alert** - A vulnerable dependency was found, fixed, dismissed or reintroduced
- **deploy_key** - A deploy key was added or removed, saying whether it can write to the repository
- **discussion** - A discussion was created, answered, closed, locked, pinned, transferred or deleted
- **discussion_comment** - Someone commented on a discussion
- **gollum** - Wiki pages were created or edited
- **issue_comment** - Someone commented on an issue or a pull request
- **issues** - An issue was opened, closed, reopened, locked, pinned, transferred or deleted
- **member** - A collaborator was added to or removed from a repository
- **membership** - A user was added to or removed from a team
- **meta** - This very webhook was deleted, so it is the last message that it sends
- **milestone** - A milestone was created, closed, opened or deleted
- **org_block** - An organization blocked or unblocked a user
- **organization** - An organization was renamed or deleted, or a member was invited, added or removed
- **package** - A package was published or updated in GitHub Packages
- **ping** - Sent once when a webhook is created, to confirm that it works
- **project** - A project (classic) was created, closed, reopened or deleted
- **projects_v2** - A project was created, closed, reopened or deleted
- **projects_v2_status_update** - A status update was posted on a project, along with how the project is doing
- **public** - A private repository was made public
- **pull_request** - A pull request was opened, merged, closed, reopened, locked, readied for review or converted to a draft
- **pull_request_review** - A pull request review was submitted or dismissed
- **pull_request_review_comment** - Someone commented on the diff of a pull request
- **push** - Commits were pushed to a branch, or a branch or a tag was created
- **registry_package** - A package was published or updated in a registry, such as a container image
- **release** - A release was published, unpublished or deleted
- **repository** - A repository was created, deleted, archived, renamed, transferred or had its visibility changed
- **repository_advisory** - A security advisory of a repository was published or reported
- **repository_ruleset** - A ruleset was created, edited or deleted, along with how it is enforced
- **secret_scanning_alert** - A leaked secret was found, resolved, reopened or seen in a public place
- **sponsorship** - Someone started sponsoring an account, a private sponsor is not named and amounts are never shown
- **team** - A team was created, renamed, deleted, had its privacy changed, or was given or refused access to a repository
- **workflow_run** - A GitHub Actions workflow run failed, timed out or failed to start on the default branch, other runs are ignored

Actions of supported events that would only be noise, such as editing, labelling or assigning an issue, are ignored.
Push event ignores branch deletions (use delete event instead).

### Unsupported events

- **bypass_request_secret_scanning** - Someone asked to push a secret past push protection, or got a response
- **check_run** - Sent for every state change of every check, the same noise as status
- **check_suite** - Sent for every set of checks that runs on a commit
- **create** - New branches and tags are formatted from the push event instead, which has the commits
- **custom_property** - A custom property definition of an organization was changed
- **custom_property_values** - The custom property values of a repository were changed
- **deployment** - Sent for every deployment
- **deployment_status** - Sent for every state change of every deployment
- **dismissal_request_code_scanning** - Someone asked to dismiss a code scanning alert, or got a response
- **dismissal_request_dependabot** - Someone asked to dismiss a Dependabot alert, or got a response
- **dismissal_request_secret_scanning** - Someone asked to dismiss a secret scanning alert, or got a response
- **exemption_request_push_ruleset** - Someone asked to push past a push ruleset, or got a response
- **fork** - Someone forked a repository
- **issue_dependencies** - An issue was marked as blocked by or blocking another one
- **label** - A label was created, edited or deleted, label syncs send dozens at once
- **merge_group** - Sent for every entry of a merge queue
- **page_build** - Sent for every GitHub Pages build, which follows a push that is already announced
- **personal_access_token_request** - A fine-grained personal access token asked for access to an organization
- **project_card** - Sent for every card that is created, moved or edited in a project (classic)
- **project_column** - A column was created, moved or deleted in a project (classic)
- **projects_v2_item** - Sent for every item that is added, moved or edited in a project
- **pull_request_review_thread** - A review thread was resolved or unresolved
- **repository_import** - A repository import from another source finished
- **secret_scanning_alert_location** - Sent for every place a secret was found in, the alert itself is announced
- **secret_scanning_scan** - A secret scanning scan has finished
- **security_and_analysis** - Security features were enabled or disabled for a repository
- **star** - Someone starred or unstarred a repository
- **status** - Sent for every state change of every commit status
- **sub_issues** - A sub-issue was added to or removed from an issue
- **team_add** - A team was given access to a repository, which the team event announces already
- **watch** - Someone starred a repository, despite the name
- **workflow_job** - Sent for every state change of every GitHub Actions job

### Events that can not be supported

These are only sent to GitHub Apps or GitHub Marketplace, not to repository or organization webhooks.

- **deployment_protection_rule** - A deployment is waiting for a custom protection rule of a GitHub App
- **deployment_review** - A deployment is waiting for, or got, an approval
- **github_app_authorization** - A user revoked their authorization of a GitHub App
- **installation** - A GitHub App was installed, uninstalled or suspended
- **installation_repositories** - Repositories were added to or removed from a GitHub App installation
- **installation_target** - The account a GitHub App is installed on was renamed
- **marketplace_purchase** - A GitHub Marketplace plan was bought, changed or cancelled
- **repository_dispatch** - A GitHub App was sent a custom event through the API
- **repository_vulnerability_alert** - Replaced by dependabot_alert
- **security_advisory** - A global security advisory was published or updated, for any project on GitHub
- **workflow_dispatch** - A GitHub Actions workflow was triggered manually

## License
[MIT](LICENSE)
