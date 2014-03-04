<?php
	Header( 'Content-Type: text/plain' );
	
	ini_set( 'error_reporting', -1 );
	ini_set( 'display_errors', 1 );
	
	require __DIR__ . '/GitHub_WebHook.php';
	
	try
	{
		$Hook = new GitHub_WebHook( );
		$Hook->ProcessRequest( );
		
		var_dump( $Hook->ValidateIPAddress() ); // Optional
		var_dump( $Hook->GetEventType() );
		var_dump( $Hook->GetPayload() );
		
		http_response_code( 202 );
	}
	catch( Exception $e )
	{
		echo PHP_EOL . PHP_EOL . 'Exception: ';
		
		print_r( $e );
	}
