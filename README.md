This script acts as a web hook for [GitHub](https://github.com/) events, processes them,
and returns messages which can be sent out to an IRC channel or a Discord webhook,
depending on the converter used.

See `examples/discord.php` for a basic application that sends webhooks to Discord.  
See `examples/irker.php` for a basic application that sends messages to IRC.  
See `worker/` for a Cloudflare Worker that sends webhooks to Discord.  

## GitHubWebHook
`GitHubWebHook.php` accepts, processes and validates an event,
it also can make sure that the event came from a GitHub server.

Functions in this class are:

#### ProcessRequest()
Accepts an event, throws `Exception` on error.

#### GetEventType()
Returns event type.

#### GetPayload()
Returns decoded JSON payload as an object.

#### GetFullRepositoryName()
Returns full name of the repository for which an event was sent for.

#### ValidateHubSignature( $SecretKey )
Returns true if HMAC hex digest of the payload matches GitHub's, false otherwise.
Throws `Exception` if the signature header is missing.

## Converters
Both converters take the data parsed by `GitHubWebHook`, and neither of them modifies the payload,
so the same payload can be given to both:

```php
$Hook = new GitHubWebHook( );
$Hook->ProcessRequest( );

$Irc = new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );
$Discord = new DiscordConverter( $Hook->GetEventType(), $Hook->GetPayload() );
```

Both of them throw `NotImplementedException` for an event or an action that is not formatted,
and `IgnoredEventException` for actions of supported events that are ignored by design,
such as editing, labelling or assigning an issue.

### IrcConverter

#### GetMessage()
Returns a colored string which can be sent to an IRC server.
Some events, such as wiki updates, return multiple lines separated by a new line.

### DiscordConverter

#### GetEmbed()
Returns an array which can be encoded as JSON and sent to a Discord webhook as is.
It contains a single embed whose author is the GitHub user that triggered the event.
Titles and descriptions are cut to fit within the limits of Discord.

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
- **team** - A team was created, renamed, deleted, or given or refused access to a repository

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
- **sponsorship** - Someone started, changed or cancelled a sponsorship
- **star** - Someone starred or unstarred a repository
- **status** - Sent for every state change of every commit status
- **sub_issues** - A sub-issue was added to or removed from an issue
- **team_add** - A team was given access to a repository, which the team event announces already
- **watch** - Someone starred a repository, despite the name
- **workflow_job** - Sent for every state change of every GitHub Actions job
- **workflow_run** - A GitHub Actions workflow run was requested, started or completed

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
