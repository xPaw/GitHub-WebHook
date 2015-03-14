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
a colored string which can be sent to an IRC channel.
