<?php
	declare(strict_types=1);

	header( 'Content-Type: text/plain; charset=utf-8' );
	
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
		
		// Format IRC message
		$IRC = new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );
		$Message = $IRC->GetMessage();
		
		if( isset( $_GET[ 'strip_colors' ] ) )
		{
			$Message = strip_colors( $Message );
		}
		
		if( empty( $Message ) )
		{
			throw new Exception( 'Empty message, not sending.' );
		}
		
		// Format irker payload
		$IrkerPayload = '';
		
		foreach( $Channels as $Channel => $SendTargets )
		{
			if( !wild( $RepositoryName, $Channel ) )
			{
				continue;
			}
			
			echo 'Matched "' . $RepositoryName . '" as "' . $Channel . '"' . PHP_EOL; 
			
			foreach( $SendTargets as $Target )
			{
				$IrkerPayload .= json_encode( Array(
					'to'      => $Target,
					'privmsg' => $Message
				) ) . "\n";
			}
		}
		
		if( empty( $IrkerPayload ) )
		{
			throw new Exception( 'Empty payload, not sending.' );
		}
		
		// Send to irker
		$Socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		
		if( $Socket === false
		|| socket_connect( $Socket, IRKER_HOST, IRKER_PORT ) === false
		|| socket_write( $Socket, $IrkerPayload ) === false )
		{
			throw new Exception( 'Socket error: ' . socket_strerror( socket_last_error() ) );
		}
		
		echo 'Payload sent to irker' . PHP_EOL . $IrkerPayload . PHP_EOL;
		
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
	
	// Cleanup
	if( $Socket !== false )
	{
		socket_close( $Socket );
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
	
	function strip_colors( string $message ) : string
	{
		return preg_replace( "/\x03(\d\d)?/", '', $message ) ?? $message;
	}
