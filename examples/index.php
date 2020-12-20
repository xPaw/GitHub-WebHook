<?php
	Header( 'Content-Type: text/plain; charset=utf-8' );
	
	// Don't do this in production!
	ini_set( 'error_reporting', '-1' );
	ini_set( 'display_errors', '1' );
	ini_set( 'html_errors', '0' );
	
	require __DIR__ . '/../Bootstrap.php';
	
	$Hook = new GitHubWebHook( );
	
	try
	{
		$Hook->ProcessRequest( );
		
		if( !$Hook->ValidateHubSignature( GITHUB_SECRET ) )
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
		$IRC = new IrcConverter( $Hook->GetEventType(), $Hook->GetPayload() );
		
		var_dump( $IRC->GetMessage() ); // Optional
		
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
		
		http_response_code( 500 );
	}
