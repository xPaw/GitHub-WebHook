<?php
declare(strict_types=1);

define("IRKER_HOST", "127.0.0.1");
define("IRKER_PORT", 6659);

// Set secret in github webhook, it will be validated with hmac
define("GITHUB_SECRET", "secretgoeshere");

/* Send config */
$Channels = array(
	/* A single repository */
	"octo-org/octo-repo" => array(
		"irc://irc.example.com/#octo-repo"
	),
	/* Every repository of an organization, every entry that matches gets the message */
	"octo-org/*" => array(
		"irc://irc.example.com/#octo-org"
	),
	/* A private message to a nick instead of a channel */
	"octocat/Hello-World" => array(
		"irc://irc.example.com/octocat,isnick"
	),
);

/* Send config */
$DiscordWebhooks = array(
	"octo-org/octo-repo" => array(
		"https://discord.com/webhook/.........."
	),
	"octo-org/*" => array(
		"https://discord.com/webhook/.........."
	),
);
