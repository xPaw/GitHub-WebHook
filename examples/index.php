<?php
	Header( 'Content-Type: text/plain; charset=utf-8' );
	
	// Don't do this in production!
	ini_set( 'error_reporting', -1 );
	ini_set( 'display_errors', 1 );
	
	require __DIR__ . '/../GitHub_WebHook.php';
	require __DIR__ . '/../GitHub_IRC.php';
	
	$Hook = new GitHub_WebHook( );
	
	try
	{
		$Hook->ProcessRequest( );
		
		// This check is optional, you can implement some secret GET param for example
		if( !$Hook->ValidateIPAddress() )
		{
			http_response_code( 401 );
			
			exit;
		}
		
		// This check is optional, checks if your hook secret matches
		if( !$Hook->ValidateHubSignature( 'My secret key' ) )
		{
			http_response_code( 401 );
			
			exit;
		}
		
		echo 'Received ' . $Hook->GetEventType() . ' in repository ' . $Hook->GetFullRepositoryName() . PHP_EOL;
		//var_dump( $Hook->GetPayload() );
		
		http_response_code( 202 );
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
		
		http_response_code( 500 );
		
		exit();
	}
	
	try
	{
		$IRC = new GitHub_IRC( $Hook->GetEventType(), $Hook->GetPayload() );
		
		var_dump( $IRC->GetMessage() ); // Optional
		
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
		
		http_response_code( 500 );
	}
