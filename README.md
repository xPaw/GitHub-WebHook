[![Build Status](https://img.shields.io/travis/xPaw/GitHub-WebHook.svg?style=flat-square)](https://travis-ci.org/xPaw/GitHub-WebHook)
[![Test Coverage](https://img.shields.io/codeclimate/coverage/github/xPaw/GitHub-WebHook.svg?style=flat-square)](https://codeclimate.com/github/xPaw/GitHub-WebHook)

This script acts as a web hook for GitHub events, processes them,
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

## License
[MIT](LICENSE)
