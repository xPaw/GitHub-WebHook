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
It contains a single embed whose author is the GitHub user that triggered the event.
Titles and descriptions are cut to fit within the limits of Discord.

## Development
`composer install` installs phpunit and phpstan.

`php vendor/bin/phpunit` runs the tests. Every event in `../fixtures` is converted and compared against
its expected `expected.bin` IRC message and `discord.json` embed.
Run the tests with `UPDATE_FIXTURES=1` to write the current output as the expected one,
then review what changed with git.

`php vendor/bin/phpstan analyse` runs the static analysis.
