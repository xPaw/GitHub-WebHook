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
Retuns true if HMAC hex digest of the payload matches GitHub's, false otherwise.

## IrcConverter
`IrcConverter.php` accepts input from previous script and outputs
a colored string which can be sent to IRC.

#### __construct( $EventType, $Payload )
`IrcConverter` constructor takes 3 paramaters (last one is optional).
All you need to do is pass data after parsing the message with `GitHubWebHook`
like so: `new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );`

URL shortener paramater takes a function, and that function should accept
a single string argument containing an url. If your function fails to
shorten an url or do anything with it, your function must return the
original url back.

#### GetMessage()
After calling the constructor, using this function will return
a string which can be sent to an IRC server.

Throws `NotImplementedException` when you pass an event that
is not parsed anyhow, and throws `IgnoredEventException` for
`fork`, `watch`, `star` and `status` events which are ignored by design.

## Events [\[ref\]](https://docs.github.com/en/webhooks/webhook-events-and-payloads)

Track changes to GitHub webhook payloads documentation here: https://github.com/github/docs/commits/main/data/reusables/webhooks

### Supported events

- code_scanning_alert
- commit_comment
- delete
- dependabot_alert
- discussion
- discussion_comment
- gollum
- issue_comment
- issues
- member
- milestone
- package
- ping
- public
- pull_request
- pull_request_review
- pull_request_review_comment
- push
- release
- repository
- repository_advisory
- secret_scanning_alert

### Not yet supported events

- branch_protection_configuration
- branch_protection_rule
- check_run
- check_suite
- custom_property
- custom_property_values
- deploy_key
- deployment
- deployment_status
- issue_dependencies
- label
- membership
- meta
- org_block
- organization
- page_build
- personal_access_token_request
- projects_v2
- projects_v2_item
- projects_v2_status_update
- pull_request_review_thread
- registry_package
- repository_import
- repository_ruleset
- secret_scanning_alert_location
- secret_scanning_scan
- security_and_analysis
- sponsorship
- sub_issues
- team
- team_add
- workflow_job
- workflow_run

### Events ignored by design

- create - Formatted from push event instead
- fork
- star
- status
- watch

Additionally, events like labelling or assigning an issue are also ignored.
Push event ignores branch deletions (use delete event instead).

### Events that can not be supported

These are only sent to GitHub Apps or GitHub Marketplace, not to repository or organization webhooks.

- deployment_protection_rule
- deployment_review
- github_app_authorization
- installation
- installation_repositories
- installation_target
- marketplace_purchase
- merge_group
- repository_dispatch
- security_advisory
- workflow_dispatch

### Events that are no longer sent

- project - Projects (classic) were removed
- project_card
- project_column
- repository_vulnerability_alert - Replaced by dependabot_alert

## License
[MIT](LICENSE)
