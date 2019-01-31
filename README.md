[![Build Status](https://img.shields.io/travis/com/xPaw/GitHub-WebHook.svg?style=flat-square)](https://travis-ci.com/xPaw/GitHub-WebHook)
[![Test Coverage](https://api.codeclimate.com/v1/badges/cb8ee1256a2a74830db2/test_coverage)](https://codeclimate.com/github/xPaw/GitHub-WebHook/test_coverage)

This script acts as a web hook for [GitHub](https://github.com/) events, processes them,
and returns messages which can be sent out to an IRC channel.

## GitHub_WebHook
`GitHub_WebHook.php` accepts, processes and validates an event,
it also can make sure that the event came from a GitHub server.

Functions in this class are:

#### ProcessRequest()
Accepts an event, throws `Exception` on error.

#### ValidateIPAddress()
Returns true if a request came from GitHub's IP range, false otherwise.

#### ValidateHubSignature($secretKey)
Retuns true if HMAC hex digest of the payload matches GitHub's, false otherwise.

#### GetEventType()
Returns event type.
See https://developer.github.com/webhooks/#events for a list of events.

#### GetPayload()
Returns decoded JSON payload as an object.

#### GetFullRepositoryName()
Returns full name of the repository for which an event was sent for.

## GitHub_IRC
`GitHub_IRC.php` accepts input from previous script and outputs
a colored string which can be sent to IRC.

#### __construct( $EventType, $Payload, $URLShortener = null )
`GitHub_IRC` constructor takes 3 paramaters (last one is optional).
All you need to do is pass data after parsing the message with `GitHub_WebHook`
like so: `new GitHub_IRC( $Hook->GetEventType(), $Hook->GetPayload() );`

URL shortener paramater takes a function, and that function should accept
a single string argument containing an url. If your function fails to
shorten an url or do anything with it, your function must return the
original url back.

#### GetMessage()
After calling the constructor, using this function will return
a string which can be sent to an IRC server.

Throws `GitHubNotImplementedException` when you pass an event that
is not parsed anyhow, and throws `GitHubIgnoredEventException` for
`fork`, `watch` and `status` events which are ignored by design.

## Events [\[ref\]](https://developer.github.com/v3/activity/events/types/)

Event                         | Status | Notes
----------------------------- | ------ | -----
CommitCommentEvent            | :+1: |
CreateEvent                   | :x: |
DeleteEvent                   | :+1: |
DeploymentEvent               | :x: |
DeploymentStatusEvent         | :x: |
DownloadEvent                 | :exclamation: | Events of this type are no longer created
FollowEvent                   | :exclamation: | Events of this type are no longer created
ForkApplyEvent                | :exclamation: | Events of this type are no longer created
ForkEvent                     | :droplet: | Ignored by design
GistEvent                     | :exclamation: | Events of this type are no longer created
GollumEvent                   | :+1: | Webhooks don't provide an url to changes
InstallationEvent             | :x: |
InstallationRepositoriesEvent | :x: |
IssueCommentEvent             | :+1: |
IssuesEvent                   | :+1: | `assigned`, `unassigned`, `labeled`, `unlabeled` events are ignored by design
LabelEvent                    | :x: |
MarketplacePurchaseEvent      | :x: |
MemberEvent                   | :+1: | `edited` events are ignored by design
MembershipEvent               | :+1: |
MilestoneEvent                | :+1: | `edited` events are ignored by design
OrganizationEvent             | :x: |
OrgBlockEvent                 | :x: |
PageBuildEvent                | :x: |
PingEvent                     | :+1: | Not documented by GitHub, sent out when a new hook is created
ProjectCardEvent              | :x: |
ProjectColumnEvent            | :x: |
ProjectEvent                  | :+1: | `edited` events are ignored by design
PublicEvent                   | :+1: |
PullRequestEvent              | :+1: | `synchornize`, `assigned`, `unassigned`, `labeled`, `unlabeled`, `review_requested`, `review_request_removed` events are ignored by design
PullRequestReviewEvent        | :+1: |
PullRequestReviewCommentEvent | :+1: |
PushEvent                     | :+1: | Only distinct commits are counted and printed. Ignores branch deletions (use `delete` event instead)
ReleaseEvent                  | :+1: |
RepositoryEvent               | :+1: |
StatusEvent                   | :droplet: | Ignored by design
TeamEvent                     | :x: |
TeamAddEvent                  | :x: |
WatchEvent                    | :droplet: | Ignored by design

## License
[MIT](LICENSE)
