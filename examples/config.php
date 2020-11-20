<?php
define("IRKER_HOST", "127.0.0.1");
define("IRKER_PORT", 6659);

// Set secret in github webhook, it will be validated with hmac
define("GITHUB_SECRET", "secretgoeshere");

/* Send config */
$Channels = array(
	/* SteamDB */
	"SteamDatabase/SteamLinux" => array(
		"irc://chat.freenode.net/#steamlug"
	),
	"SteamDatabase/*" => array(
		"irc://chat.freenode.net/#steamdb"
	),
	/* Personal */
	"meklu/mekoverlay" => array(
		"irc://chat.freenode.net/meklu,isnick"
	),
);

/* Send config */
$DiscordWebhooks = array(
	"SteamDatabase/SteamLinux" => array(
		"https://discord.com/webhook/.........."
	),
	"SteamDatabase/*" => array(
		"https://discord.com/webhook/.........."
	),
);
