<?php
Header( 'Content-Type: text/plain; charset=utf-8' );

// Don't do this in production!
ini_set( 'error_reporting', '-1' );
ini_set( 'display_errors', '1' );
ini_set( 'html_errors', '0' );

http_response_code( 500 );

require __DIR__ . '/config.php';
require __DIR__ . '/../Bootstrap.php';

$Socket = false;
$Hook = new GitHubWebHook( );

try
{
	if( !$Hook->ValidateHubSignature( GITHUB_SECRET ) )
	{
		throw new Exception( 'Secret validation failed.' );
	}

	$Hook->ProcessRequest( );

	$RepositoryName = $Hook->GetFullRepositoryName();

	echo 'Received ' . $Hook->GetEventType() . ' in repository ' . $RepositoryName . PHP_EOL;
	//print_r( $Hook->GetPayload() );

	// Format Discord message
	$DiscordConverter = new DiscordConverter( $Hook->GetEventType(), $Hook->GetPayload() );
	$DiscordMessage = $DiscordConverter->GetEmbed();

	if( empty( $DiscordMessage ) )
	{
		throw new Exception( 'Empty message, not sending.' );
	}

	foreach( $DiscordWebhooks as $Channel => $SendTargets )
	{
		if( !wild( $RepositoryName, $Channel ) )
		{
			continue;
		}

		echo 'Matched "' . $RepositoryName . '" as "' . $Channel . '"' . PHP_EOL;

		foreach( $SendTargets as $Target )
		{
			SendToDiscord( $Target, $DiscordMessage );
		}
	}

	echo 'Payload sent to Discord' . PHP_EOL;

	http_response_code( 202 );
}
catch( IgnoredEventException $e )
{
	http_response_code( 200 );

	echo 'This GitHub event is ignored.';
}
catch( NotImplementedException $e )
{
	http_response_code( 501 );

	echo 'Unsupported GitHub event: ' . $e->EventName;
}
catch( Exception $e )
{
	echo 'Exception: ' . $e->getMessage() . PHP_EOL;
}

function wild( string $string, string $expression ) : bool
{
	if( strpos( $expression, '*' ) === false )
	{
		return strcmp( $expression, $string ) === 0;
	}

	$expression = preg_quote( $expression, '/' );
	$expression = str_replace( '\*', '.*', $expression );

	return preg_match( '/^' . $expression . '$/', $string ) === 1;
}

function SendToDiscord( string $Url, array $Payload ) : bool
{
	$c = curl_init( );
	curl_setopt_array( $c, [
		CURLOPT_USERAGENT      => 'https://github.com/xPaw/GitHub-WebHook',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => 0,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_CONNECTTIMEOUT => 30,
		CURLOPT_URL            => $Url,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( $Payload ),
		CURLOPT_HTTPHEADER     => [
			'Content-Type: application/json',
		],
	] );
	curl_exec( $c );
	$Code = curl_getinfo( $c, CURLINFO_HTTP_CODE );
	curl_close( $c );

	echo 'Discord HTTP ' . $Code . PHP_EOL;

	return $Code >= 200 && $Code < 300;
}
