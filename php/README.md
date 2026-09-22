# PHP
A PHP library that accepts GitHub webhook events and converts them into messages
for an IRC channel or a Discord webhook, depending on the converter used.
Sending the message is up to your application.

Include `Bootstrap.php` to load every class, there are no dependencies.

See `examples/discord.php` for a basic application that sends webhooks to Discord.  
See `examples/irker.php` for a basic application that sends messages to IRC.  
See the [supported events](../README.md#supported-events) for what is formatted.

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
It is a single card, headed by the GitHub user that set the event off and what they did, linked to it,
under the name of the repository, or `@` and the account for events that have no repository.
The message is sent as that user, unless Discord will not take their login as a username.
Bodies keep their markdown, but headings in them are flattened so that they can not out-shout the card.

The message sets the `IS_COMPONENTS_V2` flag, so the webhook has to be posted to with
`?with_components=true` or Discord ignores the components and rejects the message.

## Development
`composer install` installs phpunit and phpstan.

`php vendor/bin/phpunit` runs the tests. Every event in `../fixtures` is converted and compared against
its expected `expected.bin` IRC message and `discord.json` message.
Run the tests with `UPDATE_FIXTURES=1` to write the current output as the expected one,
then review what changed with git.

`php vendor/bin/phpstan analyse` runs the static analysis.
